<?php

namespace console\components\jobs\ss;

use common\helpers\Logger;
use common\helpers\Utils;
use console\components\crawlers\ss\DamagedCarCrawler;
use console\components\crawlers\ss\CarCrawler;
use console\components\CurlHelper;
use yii\base\BaseObject;
use yii\console\ExitCode;
use yii\queue\RetryableJobInterface;

class CarCrawlerJob extends BaseObject implements RetryableJobInterface
{
    public $url;

    public function execute($queue)
    {
        try {
            $adId = CarCrawler::getIdFromUrl($this->url);
            Logger::log("[{$adId}] SsCarCrawlerJob started");
            $startTime = Utils::profileStarted();

            $html = CurlHelper::crawlUrl($this->url, \Yii::$app->params['proxyEnabled']['ss']);
            if (CarCrawler::isDamagedCarUrl($this->url)) {
                $parser = new DamagedCarCrawler();
            } else {
                $parser = new CarCrawler();
            }
            $parser->crawl($this->url, $html);
            Logger::log("[{$adId}] SsCarCrawlerJob ended");
            Utils::profileEnded($startTime);
        } catch (\Exception $e) {
            Logger::logError("Error crawling ss.com car: {$e->getMessage()}}",
                [
                    'message' => $e->getMessage(),
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
