<?php

namespace common\processors\facebook\post;

use common\models\Campaign;
use common\processors\CommonProcessor;
use yii\web\HttpException;

class GetStatistics extends CommonProcessor
{

    const TWELVE_DAYS_SECONDS = 1036800;
    const DATE_KEY_FORMAT = "Y-m-d";

    public function ifRequestValid()
    {
        return true;
    }

    /**
     * @return false|string
     * @throws HttpException
     */
    function process()
    {
        $compiledResponse = $this->extractDataFromResponse($this->request->getOriginalFileContent());

        /**
         * @var \yii\caching\FileCache $cache
         */
        $cache = \Yii::$app->cache;

        foreach ($compiledResponse as $campaignStatsRow) {
            $campaignStats = $campaignStatsRow["stats"];
            if (sizeof($campaignStats) == 0) {
                continue;
            }
            $campaignCurrency = Campaign::findOne([
                "ad_platform_id" => $campaignStats[0]->campaign_id,
                "client_project_account_id" => $this->request->client_project_account_id])
                ->currency;
            foreach ($campaignStats as $dayStat) {
                $coef = $this->getCoef($dayStat->date_start, $this->request->client_project_account_id);
                if (!$coef) {
                    throw new HttpException(400, 'Bad Request');
                }
                $dayStat->spend *= $coef;
                if ($campaignCurrency != 'RUR') {
                    $dayCourse = $this->getDayCourse($cache, $dayStat->date_start, $campaignStats, $campaignCurrency);
                    $dayStat->spend = round($dayCourse * $dayStat->spend);
                }
                unset($dayStat->campaign_id);
                unset($dayStat->campaign_name);
            }
        }

        return json_encode($compiledResponse);
    }

    /**
     * @param string $response
     * @return array
     */
    private function extractDataFromResponse($response)
    {
        $jsonResponse = json_decode($response);
        $compiledResponse = [];
        foreach ($jsonResponse as $campaignStats) {
            $data = json_decode($campaignStats->body)->data;
            if (sizeof($data) > 0) {
                $compiledResponse[] = array(
                    "campaign_name" => $data[0]->campaign_name,
                    "campaign_id" => $data[0]->campaign_id,
                    "stats" => $data
                );
            }
        }
        return $compiledResponse;
    }

    /**
     * @param \yii\caching\Cache $cache
     * @param string $day
     * @param $campaignStats
     * @param string $campaignCurrency
     * @return double
     * @throws HttpException
     */
    private function getDayCourse(\yii\caching\Cache $cache, $day, $campaignStats, $campaignCurrency)
    {
        if ($campaignCurrency != 'USD') {
            \Yii::error("unknown currency {$campaignCurrency} for account {$this->request->client_project_account_id}");
            throw new HttpException(500, 'Internal Error');
        }
        $dayCourse = $this->getCourseFromCache($cache, $day);
        if ($dayCourse == null) {
            $this->updateCache($cache, $campaignStats);
            $dayCourse = $this->getCourseFromCache($cache, $day);
        }

        if ($dayCourse == null) {
            \Yii::error("not found usd currency course for {$day} for account {$this->request->client_project_account_id}");
            throw new HttpException(500, 'Internal Error');
        }

        return $dayCourse;
    }

    /**
     * @param $unformattedDate
     * @param $secondsToAdd
     * @param $format
     * @return false|string
     */
    private function addSecondsAndFormatDate($unformattedDate, $secondsToAdd, $format)
    {
        $stamp = $this->getStamp($unformattedDate);
        $stamp += $secondsToAdd;
        $formattedDate = date($format, $stamp);
        return $formattedDate;
    }

    /**
     * @param $strDate
     * @return false|int
     */
    private function getStamp($strDate)
    {
        $date = date_parse($strDate);
        $stamp = mktime(null, null, null, $date["month"], $date["day"], $date["year"]);
        return $stamp;
    }

    /**
     * @param \yii\caching\Cache $cache
     * @param $campaignStats
     */
    private function updateCache(\yii\caching\Cache $cache, $campaignStats)
    {
        //запрашиваем курс валют с запасом дней, на случай праздников - 12 дней (январские праздники)
        $startDate = $this->addSecondsAndFormatDate($campaignStats[0]->date_start, -self::TWELVE_DAYS_SECONDS, "d/m/Y");
        $endDate = $this->addSecondsAndFormatDate(end($campaignStats)->date_start, self::TWELVE_DAYS_SECONDS, "d/m/Y");
        $context = stream_context_create(array(
            'http' => array(
                'max_redirects' => 101
            )));
        $usdCode = "R01235";

        $currencyCourses = $this->getCoursesFromCb($startDate, $endDate, $usdCode, $context);
        $records = $currencyCourses->xpath('Record');
        foreach ($records as $record) {
            $dateKey = $this->addSecondsAndFormatDate($record["Date"], 0, self::DATE_KEY_FORMAT);
            $cache->set($dateKey, (double)str_replace(',', '.', $record->Value));
        }
    }

    /**
     * @param $startDate
     * @param $endDate
     * @param $usdCode
     * @param $context
     * @return false|string
     */
    private function callCbApi($startDate, $endDate, $usdCode, $context)
    {
        try {
            return file_get_contents(
                "http://www.cbr.ru/scripts/XML_dynamic.asp?date_req1={$startDate}&date_req2={$endDate}&VAL_NM_RQ={$usdCode}",
                false,
                $context);
        } catch (\Exception $e) {
            \Yii::error("error when call to cb ${e}");
            return false;
        }
    }

    /**
     * @param $startDate
     * @param $endDate
     * @param $usdCode
     * @param $context
     * @return \SimpleXMLElement
     */
    private function getCoursesFromCb($startDate, $endDate, $usdCode, $context)
    {
        $content = $this->callCbApi($startDate, $endDate, $usdCode, $context);
        $retry_count = 0;
        while ($content === false && $retry_count < 5) {
            sleep(1);
            $content = $this->callCbApi($startDate, $endDate, $usdCode, $context);
        }
        return new \SimpleXMLElement($content);
    }

    /**
     * @param \yii\caching\Cache $cache
     * @param $day
     * @return mixed
     */
    private function getCourseFromCache(\yii\caching\Cache $cache, $day)
    {
        $course = $cache->get($day);
        if ($course == null) {
            $i = 0;
            while ($course == null && $i < 12) { //ищем курс за предыдущий рабочий день
                $day = $this->addSecondsAndFormatDate($day, -86400, self::DATE_KEY_FORMAT);
                $course = $cache->get($day);
                $i++;
            }
        }
        return $course;
    }
}