<?php

namespace console\components\jobs\ss;

use common\helpers\Logger;
use common\helpers\Utils;
use console\components\crawlers\ss\IndexPageCrawler;
use console\components\CurlHelper;
use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;

class IndexPageJob extends BaseObject implements RetryableJobInterface
{
    public $url;
    public $pageNum;

    public function execute($queue)
    {
        Logger::log("[{$this->pageNum}] Ss IndexPageJob started");
        $startTime = Utils::profileStarted();

        try {
            $html = CurlHelper::crawlUrl($this->url, \Yii::$app->params['proxyEnabled']['ss'], false);
            $parser = new IndexPageCrawler();
            $parser->updateCarsFromIndexHtml($html, $this->url);
            Logger::log("[{$this->pageNum}] Ss IndexPageJob ended");
        } catch (\Exception $e) {
            Logger::logError("[{$this->pageNum}] Ss IndexPageJob error happened: {$e->getMessage()}");
        }

        Utils::profileEnded($startTime);
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
