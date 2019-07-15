<?php

namespace console\components\crawlers\ss;

use common\helpers\Constants;
use common\helpers\Logger;
use common\models\Car;
use common\models\CarAd;
use common\models\CarAdStatus;
use common\models\CarPrice;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class ActualizationCrawler extends CarCrawler
{
    const WHOLE_BREADCRUMBS_SELECTOR = '.headtitle';

    public function crawl($url, $html)
    {
        $domCrawler = new DomCrawler($html);

        try {
            $breadcrumbs = trim($domCrawler->filter(self::WHOLE_BREADCRUMBS_SELECTOR)->text());
        } catch (\InvalidArgumentException $exception) {
            Logger::logError("Something wrong with this ss.com ad, skip", ['url' => $url]);
            return;
        }

        $categories = array_map('trim', explode('/', $breadcrumbs));
        $firstCategory = reset($categories);
        $lastCategory = end($categories);
        $isSellAuto = ($firstCategory == 'Легковые авто') && ($lastCategory == 'Продают');
        $isSellDamaged = isset($categories[1]) && ($categories[1] == 'Транспорт с дефектами или после аварии') && ($lastCategory == 'Продают');
        if (!$isSellAuto && !$isSellDamaged) {
            Logger::log("Start removing car {$url} because it was not sell car ad");
            if (Car::removeByUrl($url)) {
                Logger::log("Removed car {$url} because it was not sell car ad", Logger::COLOR_CYAN);
            } else {
                Logger::logError("Error removing ss.com car because it was not sell car ad", ['url' => $url]);
            }
            return;
        }

        $carAd = CarAd::findBySsUrl($url);
        $priceHtml = $domCrawler->filter('.ads_price')->count() > 0 ? $domCrawler->filter('.ads_price')->text() : null;
        $mileageHtml = $domCrawler->filter('#tdo_16')->count() > 0 ? $domCrawler->filter('#tdo_16')->text() : null;
        $characteristics = $this->extractCharacteristics($domCrawler);
        $engineType = $this->extractEngineType($characteristics);
        $bodyTypeId = $this->extractBodyType($characteristics);
        if ($carAd) {
            $carPrice = CarPrice::find()->where(['car_ad_id' => $carAd->id])->orderBy(['updated_at' => SORT_DESC])->one();
            if (!$this->handleCarIsRemovedCase($url, $html)) {
                if ($carAd->current_status == CarAdStatus::STATUS_INACTIVE) {
                    $carAd->addRenewedStatusRecord($carPrice);
                }
            }
            if (($bodyTypeId) && ($carAd->car->car_body_type_id != $bodyTypeId)) {
                $carAd->car->car_body_type_id = $bodyTypeId;
                $carAd->car->save();
            }
            if ($priceHtml) {
                $price = $this->makeNumber($priceHtml);
                $this->checkPrice($price);

                $mileage = $mileageHtml ? $this->makeNumber($mileageHtml) : null;
                $carPrice = $this->handleCarPriceChanges($carAd, 'EUR', $price, $mileage,
                    Constants::SOURCE_SITE_SS_COM);
                $this->handleCarPriceAndMileage($carAd->car, $carPrice, $carAd);
            }
            if ($engineType) {
                $carAd->car->updateAttributes(['engine_type' => $engineType]);
            }
            $carAd->car->updateAttributes(['checkup_date' => $this->extractCheckupDate($characteristics)]);
            $imageUrl = $domCrawler->filter(self::FIRST_IMAGE_SELECTOR)->count() > 0 ? $domCrawler->filter(self::FIRST_IMAGE_SELECTOR)->first()->filter('a')->attr('href') : null;
            $totalImagesCount = $domCrawler->filter(self::GALLERY_SELECTOR)->filter('img')->count();
            if (($imageUrl != $carAd->photo_url) || ($carAd->photo_amount != $totalImagesCount)) {
                Logger::log("photo_url and photo_amount for CarAd updated to $imageUrl/$totalImagesCount");
                $carAd->updateAttributes(['photo_url' => $imageUrl, 'photo_amount' => $totalImagesCount]);
            }
            if ($carAd->car->photo_url != $imageUrl) {
                Logger::log("photo_url for Car updated to $imageUrl");
                $carAd->car->updateAttributes(['photo_url' => $imageUrl]);
            }
        }

        Logger::log("Car {$url} processed");
    }
}