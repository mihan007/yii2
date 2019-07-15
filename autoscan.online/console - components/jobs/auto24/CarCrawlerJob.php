<?php

namespace console\components\jobs\auto24;

use common\helpers\Logger;
use common\helpers\Utils;
use console\components\crawlers\auto24\CarCrawler;
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
            Logger::log("[{$adId}] Auto24 CarCrawlerJob started");
            $startTime = Utils::profileStarted();

            $html = CurlHelper::crawlUrl($this->url, \Yii::$app->params['proxyEnabled']['auto24']);
            $parser = new CarCrawler();
            $carIdIfCrawled = $parser->crawl($this->url, $html);

            if ($carIdIfCrawled) {
                Logger::log("[{$adId}] added to db");
            }

            Logger::log("[{$adId}] Auto24 CarCrawlerJob ended");
            Utils::profileEnded($startTime);
        } catch (\Exception $e) {
            Logger::logError("Error crawling auto24 car: {$e->getMessage()}}",
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
