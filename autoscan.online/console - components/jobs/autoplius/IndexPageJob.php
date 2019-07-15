<?php

namespace console\components\jobs\autoplius;

use common\helpers\Logger;
use common\helpers\Utils;
use console\components\crawlers\autoplius\IndexPageCrawler;
use console\components\CurlHelper;
use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;

class IndexPageJob extends BaseObject implements RetryableJobInterface
{
    public $url;
    public $pageNum;

    public function execute($queue)
    {
        Logger::log("[{$this->pageNum}] AutopliusParseIndexJob started: {$this->url}");
        $startTime = Utils::profileStarted();

        $html = CurlHelper::crawlUrl($this->url, \Yii::$app->params['proxyEnabled']['autoplius'], false);
        $parser = new IndexPageCrawler();
        $parser->updateCarsFromIndexHtml($html);
        Logger::log("[{$this->pageNum}] AutopliusParseIndexJob ended: {$this->url}");

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
