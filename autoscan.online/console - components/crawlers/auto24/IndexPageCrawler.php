<?php

namespace console\components\crawlers\auto24;

use common\helpers\Logger;
use common\models\CarAd;
use console\components\jobs\auto24\ActualizationJob;
use console\components\jobs\auto24\CarCrawlerJob;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use yii\helpers\VarDumper;

class IndexPageCrawler extends CarCrawler
{
    const SEARCH_RESULT_TABLE = '#usedVehiclesSearchResult';
    const PICTURE_CELL = '.pictures img';
    const URL_CELL = '.make_and_model a';
    const PRICE_CELL = '.price';
    const URL_PREFIX = 'https://rus.auto24.ee';

    /**
     * @param $url
     * @param $html
     */
    public function crawl($url, $html)
    {
        $domCrawler = new DomCrawler($html);

        $ads = $domCrawler->filter(self::SEARCH_RESULT_TABLE)->filter('tr')->each(function (DomCrawler $node, $i) {
            $picture = $node->filter(self::PICTURE_CELL)->count() > 0 ? $node->filter(self::PICTURE_CELL)->attr('src') : null;
            $adUrl = $node->filter(self::URL_CELL)->count() > 0 ? $node->filter(self::URL_CELL)->attr('href') : null;
            $price = $node->filter(self::PRICE_CELL)->count() > 0 ? $this->makeNumber($node->filter(self::PRICE_CELL)->text()) : null;
            return [
                'pictureUrl' => $picture,
                'adUrl' => $adUrl,
                'price' => $price
            ];
        });

        $countAds = sizeof($ads);
        Logger::log("[Auto24:IndexPageCrawler] found ads $countAds");

        $ads = collect($ads)->filter(function ($el) {
            return strlen($el['price']) > 0;
        })->all();

        Logger::log("[Auto24:IndexPageCrawler] found valid ads ".sizeof($ads));
        foreach ($ads as $adInfo) {
            $carAd = null;
            Logger::log('Working with '.$adInfo['adUrl']);
            if ($adInfo['adUrl']) {
                Logger::log($adInfo['adUrl'] . ' - all good, processing');
                $normalizedUrl = self::URL_PREFIX . $adInfo['adUrl'];
                $carAd = CarAd::findByAuto24Url($normalizedUrl);
                $auto24Id = CarCrawler::getIdFromUrl($normalizedUrl);
                if ($carAd) {
                    Logger::log("[{$auto24Id}] It is old one, check picture: " . $normalizedUrl, Logger::COLOR_CYAN);
                    if ($adInfo['pictureUrl'] && ($carAd->photo_url != $adInfo['pictureUrl'])) {
                        $carAd->updateAttributes(['photo_url' => $adInfo['pictureUrl']]);
                        $carAd->car->updateAttributes(['photo_url' => $adInfo['pictureUrl']]);
                        Logger::log("For ad $normalizedUrl changed photo_url. Now it is {$adInfo['pictureUrl']}",
                            Logger::COLOR_GREEN);
                    }
                    if (!$carAd->car) {
                        Logger::logError("[{$auto24Id}] invalid carAd, deleting bad one and add url to crawling queue", [
                            'carAd.id' => $carAd->id
                        ]);
                        $carAd->delete();
                        continue;
                    }
                    $currentCarPrice = $carAd->car->current_price;
                    if ($adInfo['price'] && ($currentCarPrice != $adInfo['price'])) {
                        Logger::log("[{$auto24Id}] current price {$adInfo['price']} != old price {$currentCarPrice}, add to actualization " . $normalizedUrl, Logger::COLOR_YELLOW);
                        \Yii::$app->queueAuto24Actualization->priority(10)->push(new ActualizationJob([
                            'url' => $carAd->source_url
                        ]));
                    }
                } else {
                    $result = $this->addAdToCrawlingQueue($adInfo, $auto24Id, $normalizedUrl);
                    if ($result) {
                        Logger::log($normalizedUrl . " added to crawler queue");
                    } else {
                        Logger::log($normalizedUrl . " skipped, it is already at crawler queue");
                    }
                }
            } else {
                Logger::log($adInfo['adUrl'] . ' - for some reason empty, skip');
            }
        }
    }

    /**
     * @param $adInfo
     * @param $auto24Id
     * @param string $normalizedUrl
     * @throws \yii\db\Exception
     */
    protected function addAdToCrawlingQueue($adInfo, $auto24Id, string $normalizedUrl)
    {
        $uniqueUrl = CarCrawler::getUnqueIdFromUrl($adInfo['adUrl']);
        $result = \Yii::$app->redis->executeCommand('sadd', [\Yii::$app->params['redisUrlsSet'], $uniqueUrl]);
        if ($result) {
            Logger::log("[{$auto24Id}] It is new one, add to parse queue: " . $normalizedUrl, Logger::COLOR_YELLOW);
            \Yii::$app->queueAuto24NewAd->priority(20)->push(new CarCrawlerJob([
                'url' => $normalizedUrl
            ]));
        } else {
            Logger::log("[{$uniqueUrl}] It is at parsing queue, skip", Logger::COLOR_GREEN);
        }
        return $result;
    }
}