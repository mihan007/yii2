<?php

namespace console\components\crawlers\ss;

use common\helpers\Constants;
use common\helpers\Logger;
use common\models\CarAd;
use common\models\CarAdStatus;
use common\models\CarPrice;
use console\components\jobs\ss\CarCrawlerJob;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class IndexPageCrawler extends CarCrawler
{
    const PRICE_SELECTOR = '.price-value';
    const MILEAGE_SELECTOR = '.field_kilometrage';
    const IS_ARCHIVED_SELECTOR = '.info-msg';

    const IS_DAMAGED_SELECTOR = '.field_has_damaged_id';
    const PHOTO_SELECTOR = '#bigphotoimg';

    public static function getCarsDataHtml(string $html)
    {
        $domCrawler = new DomCrawler($html);
        $info = $domCrawler
            ->filter('.msga2')
            ->parents()
            ->filter('tr')
            ->each(function (DomCrawler $node) {
                $imgNode = $node->filter('a');
                if ($imgNode->count() == 0) {
                    return null;
                }
                $priceNodes = $node->filter('.msga2-o');
                if ($priceNodes->count() == 0) {
                    return null;
                }
                return [
                    'url' => $imgNode->attr('href'),
                    'image' => $imgNode->filter('img')->count() > 0 ? $imgNode->filter('img')->attr('src') : null,
                    'price' => $priceNodes->last()->text(),
                ];
            });

        return collect($info)->filter(function ($el) {
            $cond1 = substr($el['url'], -4) == 'html';
            $cond2 = (($el !== null) && (mb_substr($el['price'], -1) === '€'));
            return $cond1 && $cond2;
        })->all();
    }

    public function updateCarsFromIndexHtml(string $html, $url)
    {
        $carsData = $this->getCarsDataHtml($html);
        foreach ($carsData as $carData) {
            $url = self::normalizeUrl($carData['url']);
            $ssId = CarCrawler::getIdFromUrl($url);
            Logger::log("[{$ssId}] Found url: " . $url);
            try {
                $carAd = CarAd::findBySsUrl($url);
                if (!$carAd) {
                    $uniqueUrl = CarCrawler::getUnqueIdFromUrl($url);
                    $result = \Yii::$app->redis->executeCommand('sadd', [\Yii::$app->params['redisUrlsSet'], $uniqueUrl]);
                    if ($result) {
                        Logger::log("[{$ssId}] It is new one, add to parse queue: " . $url, Logger::COLOR_YELLOW);
                        \Yii::$app->queueSsNewAd->priority(20)->push(new CarCrawlerJob([
                            'url' => $url
                        ]));
                    } else {
                        Logger::log("[{$uniqueUrl}] It is at parsing queue, skip", Logger::COLOR_GREEN);
                    }
                    continue;
                }
                Logger::log("[{$ssId}] Url already in db, update data: " . $url);
                if ($carAd->photo_url !== $carData['image']) {
                    $carAd->car->updateAttributes([
                        'photo_url' => $carData['image'],
                    ]);
                    $carAd->updateAttributes([
                        'photo_url' => $carData['image'],
                    ]);
                }
                $carPrice = CarPrice::find()
                    ->where([
                        'car_ad_id' => $carAd->id,
                    ])->orderBy(['updated_at' => SORT_DESC])
                    ->one();
                if ($carAd->current_status == CarAdStatus::STATUS_INACTIVE) {
                    Logger::log("[{$ssId}] CarAdStatus::STATUS_INACTIVE => CarAdStatus::STATUS_ACTIVE");
                    $carAd->addRenewedStatusRecord($carPrice);
                }
                $price = $this->makeNumber($carData['price']);
                $currentPrice = $carAd->car->current_price;
                Logger::log("Found price $price, price before {$currentPrice}.");
                if ($price != $currentPrice) {
                    Logger::log("[{$ssId}] Change price from $currentPrice to $price", Logger::COLOR_BLUE);
                }
                $this->checkPrice($price);
                $carPrice = $this->handleCarPriceChanges($carAd, 'EUR', $price, null, Constants::SOURCE_SITE_SS_COM);
                $this->handleCarPriceAndMileage($carAd->car, $carPrice, $carAd);
            } catch (\Exception $e) {
                Logger::logError("Fail to change ss.com ad: " . $e->getMessage(), ['url' => $url]);
            }
        }
    }
}
