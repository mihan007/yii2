<?php

namespace console\components\crawlers\autoplius;

use common\helpers\Constants;
use common\helpers\Logger;
use common\helpers\Utils;
use common\models\CarAd;
use common\models\CarAdStatus;
use common\models\CarPrice;
use console\components\crawlers\autoplius\CarCrawler;
use console\components\jobs\autoplius\CarCrawlerJob;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class IndexPageCrawler extends CarCrawler
{
    const PRICE_SELECTOR = '.price-value';
    const MILEAGE_SELECTOR = '.field_kilometrage';
    const IS_ARCHIVED_SELECTOR = '.info-msg';

    const IS_DAMAGED_SELECTOR = '.field_has_damaged_id';
    const PHOTO_SELECTOR = '#bigphotoimg';

    public static function getCarsDataHtml(string $html, $url = false)
    {
        $domCrawler = new DomCrawler($html);
        return $domCrawler
            ->filter('.auto-list li')
            ->each(function (DomCrawler $node) {
                if ($node->filter('a')->count() > 0) {
                    $url = CarCrawler::normalizeUrl($node->filter('a')->attr('href'));
                } else {
                    return [];
                }
                $params = $node->filter('.param-list-row')->each(function (DomCrawler $param) {
                    return $param->text();
                });
                $result = [];
                foreach ($params as $param) {
                    $els = explode(PHP_EOL, $param);
                    foreach ($els as $el) {
                        $cutted = trim($el);
                        if ($cutted) {
                            $result[] = $cutted;
                        }
                    }
                }
                $params = $result;
                $mileage = array_values(array_filter($params, function ($param) {
                    return mb_substr($param, -2) === 'км';
                }));
                $image = $node->filter('img');
                $price = $node->filter('.price-list strong');
                $exportPrice = $node->filter('.price-list .list-price-subtitle');
                $tax = $node->filter('.price-list .is-price-netto');
                return [
                    'isSold' => $node->filter('.badge-sold')->count() > 0,
                    'url' => $url,
                    'image' => $image->count() > 0 ? $image->first()->attr('src') : null,
                    'price' => $price->count() > 0 ? $price->first()->text() : null,
                    'mileage' => isset($mileage[0]) ? $mileage[0] : null,
                    'is_urgent' => $node->filter('.badge')->count() > 0,
                    'export_price' => $exportPrice->count() > 0 ? Utils::makeNumber($exportPrice->text()) : null,
                    'tax' => $tax->count() > 0 ? Constants::TAX_NOT_INCLUDED : null
                ];
            });
    }

    public function updateCarsFromIndexHtml(string $html)
    {
        $carsData = array_filter($this->getCarsDataHtml($html));
        Logger::log("Got urls: " . sizeof($carsData));
        foreach ($carsData as $carData) {
            $url = CarCrawler::normalizeUrl($carData['url']);
            $autopliusId = CarCrawler::getIdFromUrl($url);
            Logger::log("[{$autopliusId}] Found url: " . $url);
            $this->parseHtml($autopliusId, $url, $carData);
        }
    }

    public function parseHtml($autopliusId, $url, $carData)
    {
        try {
            $carAd = CarAd::findByAutopliusUrl($url);
            if (!$carAd) {
                $uniqueUrl = CarCrawler::getUnqueIdFromUrl($url);
                $result = \Yii::$app->redis->executeCommand('sadd', [\Yii::$app->params['redisUrlsSet'], $uniqueUrl]);
                if ($result) {
                    Logger::log("[{$autopliusId}] It is new one, add to parse queue: " . $url, Logger::COLOR_YELLOW);
                    \Yii::$app->queueAutopliusNewAd->priority(20)->push(new CarCrawlerJob([
                        'url' => $url
                    ]));
                } else {
                    Logger::log("[{$uniqueUrl}] It is at parsing queue, skip", Logger::COLOR_GREEN);
                }
                return;
            }
            if (!$carAd) {
                return;
            }
            Logger::log("[{$autopliusId}] Url already in db, update data: " . $url);
            if ($carAd->photo_url !== $carData['image']) {
                $carAd->car->updateAttributes([
                    'photo_url' => $carData['image'],
                ]);
                $carAd->updateAttributes([
                    'photo_url' => $carData['image'],
                ]);
            }
            $attrChanged = $carAd->car->export_price != $carData['export_price'];
            $attrChanged = $attrChanged || $carAd->car->is_urgent != $carData['is_urgent'];
            $attrChanged = $attrChanged || $carAd->car->tax != $carData['tax'];
            if ($attrChanged) {
                $carAd->car->updateAttributes([
                    'export_price' => $carData['export_price'],
                    'is_urgent' => $carData['is_urgent'],
                    'tax' => $carData['tax']
                ]);
            }
            $attrChanged = $carAd->tax != $carData['tax'];
            $attrChanged = $attrChanged || $carAd->autoplius_id != $autopliusId;
            if ($attrChanged) {
                $carAd->updateAttributes([
                    'tax' => $carData['tax'],
                    'autoplius_id' => $autopliusId
                ]);
            }
            $price = $this->makeNumber($carData['price']);
            $this->checkPrice($price);

            if ($carData['mileage']) {
                $mileage = $this->makeNumber($carData['mileage']);
            } else {
                $mileage = 0;
            }

            $currencyText = mb_substr($carData['price'], -1);
            $currency = $this->detectCurrency($currencyText);
            $carPrice = $this->handleCarPriceChanges($carAd, $currency, $price, $mileage,
                Constants::SOURCE_SITE_AUTOPLIUS);
            $this->handleCarPriceAndMileage($carAd->car, $carPrice, $carAd);
            if ($carData['isSold']) {
                Logger::log("Marked $url as removed");
                $carPrice = CarPrice::find()
                    ->where([
                        'car_ad_id' => $carAd->id
                    ])
                    ->orderBy(['updated_at' => SORT_DESC])
                    ->one();
                $carAd->addRemovedStatusRecord($carPrice);
            } elseif ($carAd->current_status == CarAdStatus::STATUS_INACTIVE) {
                $carAd->addRenewedStatusRecord($carPrice);
            }
        } catch (\Exception $e) {
            Logger::logError("[{$autopliusId}] Fail to change autoplius ad: " . $e->getMessage(),
                ['url' => $carData['url']]);
        }
    }
}
