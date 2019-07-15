<?php

namespace console\components\jobs\autoplius;

use common\helpers\Logger;
use common\helpers\Utils;
use console\components\crawlers\autoplius\ActualizationCrawler;
use console\components\crawlers\autoplius\CarCrawler;
use console\components\CurlHelper;
use Exception;
use yii\base\BaseObject;
use yii\console\ExitCode;
use yii\queue\RetryableJobInterface;

class ActualizationJob extends BaseObject implements RetryableJobInterface
{
    public $url;

    public function execute($queue)
    {
        try {
            $adId = CarCrawler::getIdFromUrl($this->url);
            Logger::log("[{$adId}] Autoplius ActualizationJob started");
            $startTime = Utils::profileStarted();

            $url = CarCrawler::convertUrlToMobile($this->url);
            Logger::log("[{$adId}] convertUrlToMobile: {$url}");

            $html = CurlHelper::crawlUrl($url, \Yii::$app->params['proxyEnabled']['autoplius'], false);
            $crawler = new ActualizationCrawler();
            $crawler->crawl($this->url, $html);
            Logger::log("[{$adId}] Autoplius ActualizationJob ended");

            Utils::profileEnded($startTime);
        } catch (Exception $e) {
            Logger::logError("Error actualization autoplius: {$e->getMessage()}",
                [
                    'url' => $this->url,
                    'traceback' => $e->getTraceAsString()
                ]);
            exit(ExitCode::UNSPECIFIED_ERROR);
        }
    }

    public function getTtr()
    {
        return 3 * 60;
    }

    public function canRetry($attempt, $error)
    {
        return $attempt < 5;
    }
}
