<?php

namespace console\components\jobs\ss;

use common\helpers\Logger;
use common\helpers\Utils;
use console\components\crawlers\ss\ActualizationCrawler;
use console\components\crawlers\ss\CarCrawler;
use console\components\CurlHelper;
use yii\base\BaseObject;
use yii\console\ExitCode;
use yii\queue\RetryableJobInterface;
use Exception;

class ActualizationJob extends BaseObject implements RetryableJobInterface
{
    public $url;

    public function execute($queue)
    {
        try {
            $adId = CarCrawler::getIdFromUrl($this->url);
            Logger::log("[{$adId}] Ss ActualizationJob started");
            $startTime = Utils::profileStarted();

            $html = CurlHelper::crawlUrl($this->url, \Yii::$app->params['proxyEnabled']['ss'], false);
            $crawler = new ActualizationCrawler();
            $crawler->crawl($this->url, $html);
            Logger::log("[{$adId}] Ss ActualizationJob ended");

            Utils::profileEnded($startTime);
        } catch (Exception $e) {
            Logger::logError("Error actualization ss.com: {$e->getMessage()}}",
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
