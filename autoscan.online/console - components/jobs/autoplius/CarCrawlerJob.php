<?php

namespace console\components\jobs\autoplius;

use common\helpers\Logger;
use common\helpers\Utils;
use console\components\crawlers\autoplius\CarCrawler;
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
            Logger::log("[{$adId}] AutopliusParseCarAdJob started");
            $startTime = Utils::profileStarted();

            $html = CurlHelper::crawlUrl($this->url, \Yii::$app->params['proxyEnabled']['autoplius']);
            $parser = new CarCrawler();
            $parser->crawl($this->url, $html);
            Logger::log("[{$adId}] AutopliusParseCarAdJob ended");
            Utils::profileEnded($startTime);
        } catch (\Exception $e) {
            Logger::logError("Error crawling autoplius car",
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
