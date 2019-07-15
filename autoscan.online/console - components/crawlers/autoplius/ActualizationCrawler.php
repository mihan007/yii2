<?php


namespace console\components\crawlers\autoplius;

use common\helpers\Constants;
use common\helpers\Logger;
use common\models\CarAd;
use common\models\CarAdStatus;
use common\models\CarMark;
use common\models\CarModel;
use common\models\CarPrice;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class ActualizationCrawler extends CarCrawler
{
    private $domCrawler;

    public function crawl($url, $html)
    {
        $autopliusAdId = CarCrawler::getIdFromUrl($url);

        $htmlLength = mb_strlen($html);
        Logger::log("[$autopliusAdId] html length: " . $htmlLength);
        if ($htmlLength < 1000) {
            Logger::logError("[$autopliusAdId] html length too small, stop");
            return;
        }

        $carAd = CarAd::findByAutopliusUrl($url);
        $isActive = false;
        if ($carAd && $carAd->current_status == CarAdStatus::STATUS_INACTIVE) {
            $isActive = $this->isActive($html, $autopliusAdId);
            if ($isActive) {
                $carPrice = CarPrice::find()
                    ->where(['car_ad_id' => $carAd->id])
                    ->orderBy(['updated_at' => SORT_DESC])
                    ->limit(1)
                    ->one();
                $carAd->addRenewedStatusRecord($carPrice);
                Logger::log("[$autopliusAdId] marked as new");
            }
        } elseif ($carAd) {
            $isActive = $this->isActive($html, $autopliusAdId);
            if (!$isActive) {
                $carPrice = CarPrice::find()
                    ->where(['car_ad_id' => $carAd->id])
                    ->orderBy(['updated_at' => SORT_DESC])
                    ->limit(1)
                    ->one();
                Logger::log("[$autopliusAdId] marked as removed");
                $carAd->addRemovedStatusRecord($carPrice);
            }
        }
        if ($isActive && $carAd) {
            $this->domCrawler = new Crawler($html);
            $this->updateAdImage($carAd);
            $this->updateCarTax($carAd);
            $this->updateCarPrice($carAd);
            $this->updateDriveType($carAd);
            $this->updateEngineType($carAd);
            $this->updateBroken($carAd);
            $this->updateBodyType($carAd);
            $this->updateVin($carAd);
            $this->detectCarMarkAndModel($carAd);
        }
        Logger::log("[$autopliusAdId] processed");
    }

    public function isActive($html, $autopliusAdId)
    {
        $missString1 = 'skelbimo galiojimo';
        $removedLabelSearchIndex1 = mb_strpos($html, $missString1);

        $missString2 = 'Объявление не существует';
        $removedLabelSearchIndex2 = mb_strpos($html, $missString2);

        if (($removedLabelSearchIndex1 !== false) || ($removedLabelSearchIndex2 !== false)) {
            Logger::log("[$autopliusAdId] is inactive", Logger::COLOR_CYAN);
            return false;
        }

        Logger::log("[$autopliusAdId] is active", Logger::COLOR_BLUE);
        return true;
    }

    private function updateAdImage(CarAd $carAd)
    {
        $imageUrl = $this->domCrawler->filter('#bigphotoimg')->count() > 0 ? $this->domCrawler->filter('#bigphotoimg')->attr('src') : null;
        if ($imageUrl && $carAd->photo_url != $imageUrl) {
            $carAd->car->updateAttributes([
                'photo_url' => $imageUrl,
            ]);
            $carAd->updateAttributes([
                'photo_url' => $imageUrl,
            ]);
            Logger::log("[{$carAd->autoplius_id}] assigned new image url", Logger::COLOR_YELLOW);
        }
    }

    private function updateCarTax(CarAd $carAd)
    {
        $tax = $this->domCrawler->filter('.price-taxes')->count() > 0 ? Constants::TAX_NOT_INCLUDED : null;
        $carAd->car->updateAttributes([
            'tax' => $tax
        ]);
        $carAd->updateAttributes([
            'tax' => $tax
        ]);
    }

    private function updateCarPrice(CarAd $carAd)
    {
        $priceText = $this->crawlPriceText($this->domCrawler);
        $price = $this->makeNumber($priceText);
        $this->checkPrice($price);
        $carPrice = $this->handleCarPriceChanges($carAd, 'EUR', $price, null, Constants::SOURCE_SITE_AUTOPLIUS);
        $this->handleCarPriceAndMileage($carAd->car, $carPrice, $carAd);
    }

    /**
     * @param DomCrawler $domCrawler
     * @return string|null
     */
    protected function crawlPriceText(DomCrawler $domCrawler)
    {
        $priceText = null;
        $priceTextCounter = $domCrawler->filter('.price-value')->count() > 0;
        $priceText2Counter = $domCrawler->filter('.price-value')->count() > 1;
        if ($priceText2Counter) {
            $priceText = trim($domCrawler->filter('.price-value')->eq(1)->text());
        } else if ($priceTextCounter) {
            $priceText = trim($domCrawler->filter('.price-value')->text());
        }
        return $priceText;
    }

    private function updateDriveType(CarAd $carAd)
    {
        $result = null;
        $driveType = $this->domCrawler->filter('.field_wheel_drive_id')->count() > 0 ? $this->domCrawler->filter('.field_wheel_drive_id')->text() : null;
        if ($driveType) {
            $parts = explode(":", $driveType);
            if (isset($parts[1])) {
                $result = $this->detectDriveType(trim($parts[1]));
            }
        }

        if ($result) {
            $carAd->car->updateAttributes(['drive_type' => $result]);
        }
    }

    private function updateEngineType(CarAd $carAd)
    {
        $result = null;
        $engineType = $this->domCrawler->filter('.field_fuel_id')->count() > 0 ? $this->domCrawler->filter('.field_fuel_id')->text() : null;
        if ($engineType) {
            $parts = explode(":", $engineType);
            if (isset($parts[1])) {
                $result = $this->detectEngineType(trim($parts[1]));
            }
        }

        if ($result) {
            $carAd->car->updateAttributes(['engine_type' => $result]);
        }
    }

    private function updateBroken(CarAd $carAd)
    {
        $result = null;
        $selector = '.field_has_damaged_id';
        $whatBroken = $this->domCrawler->filter($selector)->count() > 0 ? $this->domCrawler->filter($selector)->text() : null;
        if ($whatBroken) {
            $parts = explode(":", $whatBroken);
            if (isset($parts[1])) {
                $result = $this->normalizeWhatBroken(trim($parts[1]));
            }
        }

        if ($result) {
            $carAd->updateAttributes(['what_broken' => $result]);
            $carAd->car->updateAttributes(['what_broken' => $result]);
        }
    }

    private function updateBodyType(CarAd $carAd)
    {
        $result = null;
        $selector = '.field_body_type_id';
        $bodyType = $this->domCrawler->filter($selector)->count() > 0 ? $this->domCrawler->filter($selector)->text() : null;
        if ($bodyType) {
            $parts = explode(":", $bodyType);
            if (isset($parts[1])) {
                $result = $this->getOrCreateBodyType($parts[1]);
            }
        }

        if ($result) {
            $carAd->car->car_body_type_id = $result;
            $carAd->car->save();
        }
    }

    private function detectCarMarkAndModel(CarAd $carAd)
    {
        $selector = 'h1';
        $markAndModel = $this->domCrawler->filter($selector)->count() > 0 ? $this->domCrawler->filter($selector)->text() : null;
        $parts = explode(" ", $markAndModel);
        $carMark = null;
        $carModel = null;
        for ($length = 1; $length < sizeof($parts); $length++) {
            $currentCarMark = implode(" ", array_slice($parts, 0, $length));
            $carMark = CarMark::findOne(['name' => $currentCarMark]);
            if ($carMark) {
                $currentCarModel = implode(" ", array_slice($parts, $length));
                if ($currentCarModel) {
                    $carModel = CarModel::findOne(['name' => $currentCarModel]);
                    if ($carModel) {
                        break;
                    }
                }
            }
        }
        if ($carMark && $carModel) {
            $carAd->car->car_mark_id = $carMark->id;
            $carAd->car->car_model_id = $carModel->id;
            $carAd->car->save();
        }
    }

    private function updateVin(CarAd $carAd)
    {
        Logger::log("Started vin parsing from carAd.id={$carAd->id}");
        $vin = $this->extractVin($this->domCrawler);
        Logger::log("Got vin from carAd.id={$carAd->id}: {$vin}");
        if ($vin) {
            $carAd->car->updateAttributes(['vin' => $vin]);
        }
    }
}
