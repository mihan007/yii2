<?php

namespace console\components\jobs\auto24;

use common\helpers\Logger;
use common\helpers\Utils;
use console\components\crawlers\auto24\IndexPageCrawler;
use console\components\CurlHelper;
use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;

class IndexPageJob extends BaseObject implements RetryableJobInterface
{
    public $url;
    public $pageNum;

    public function execute($queue)
    {
        Logger::log("[{$this->pageNum}] Auto24 IndexPageJob started");
        $startTime = Utils::profileStarted();

        try {
            $html = CurlHelper::crawlUrl($this->url, \Yii::$app->params['proxyEnabled']['auto24'], false);
            $parser = new IndexPageCrawler();
            $parser->crawl($this->url, $html);
            Logger::log("[{$this->pageNum}] Auto24 IndexPageJob ended");
        } catch (\Exception $e) {
            Logger::logError("Auto24 IndexPageJob error happened", ['errorMessage' => $e->getMessage()]);
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
