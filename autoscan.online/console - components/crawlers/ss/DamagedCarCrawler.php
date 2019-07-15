<?php

namespace console\components\crawlers\ss;

use common\helpers\Constants;
use common\helpers\Logger;
use common\models\CarAd;
use common\models\CarPrice;
use console\components\Url;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class DamagedCarCrawler extends CarCrawler
{
    const BREADCRUMBS_SELECTOR = '.headtitle';
    const PARAMS_SELECTOR = '.options_list table tr';

    const CUR_EUR = 'EUR';
    const CUR_USD = 'USD';
    const CUR_RUB = 'RUB';
    const CUR_UNKNOWN = '---';

    public static function isValidCarMarkUrl(Url $url)
    {
        $cleanUrl = (string)$url;
        $parts = explode("/", $cleanUrl);
        $condition = Url::starts_with($cleanUrl, 'https://www.ss.com/ru/transport/cars/');
        $condition = $condition && (!self::isNonCarMark($parts[6]));
        return $condition;
    }

    private static function isNonCarMark($mark)
    {
        Logger::log($mark, Logger::COLOR_GREEN);
        return in_array($mark, [
            'new',
            'search',
            'exchange',
            'rss'
        ]);
    }

    public function crawl($url, $html)
    {
        $domCrawler = new DomCrawler($html);

        try {
            $isAutoSelector = trim($domCrawler->filter(self::BREADCRUMBS_SELECTOR)->eq(0)->text());
            $parts = explode("/", $isAutoSelector);
            $crumbs = [];
            foreach ($parts as $part) {
                $crumbs[] = trim($part);
            }
        } catch (\InvalidArgumentException $exception) {
            Logger::logError("Something wrong with this ss.com ad, skip", ['url' => $url]);
            return;
        }

        if (($crumbs[0] != 'Другое...') || ($crumbs[2] != 'Продают')) {
            Logger::logError("At {$url} is not valid auto ad, skip");
            return;
        }

        $params = $domCrawler->filter(self::PARAMS_SELECTOR)->each(function (DomCrawler $node, $i) {
            return $node->text();
        });
        $characteristics = [];
        foreach ($params as $i => $row) {
            if (strpos($row, 'Марка авто') !== false) {
                $charName = mb_strtolower('Марка авто');
                $charValue = trim(str_replace('Марка авто', '', $row));
                $characteristics[$charName] = $charValue;
                continue;
            }
            $parts = explode(":", $row);
            if (sizeof($parts) < 2) {
                continue;
            }
            $charName = mb_strtolower(trim($parts[0]));
            $charValue = trim($parts[1]);
            if (strlen($charName) > 0) {
                $characteristics[$charName] = $charValue;
            }
        }
        $mark = $model = null;
        if (isset($characteristics['марка авто']) && ($characteristics['марка авто'] != '-')) {
            $mark = $characteristics['марка авто'];
        }
        if (isset($characteristics['модель'])) {
            $model = $characteristics['модель'];
        }
        list($markId, $modelId) = $this->getOrCreateMarkModel($mark, $model);

        $displacement = $this->normalizeDisplacement($characteristics);
        $horsepower = $this->normalizeHorsePower($characteristics);
        $price = $this->extractPrice($domCrawler);
        $this->checkPrice($price);

        $currency = self::CUR_EUR;

        $sellerName = $this->extractSellerName($domCrawler);
        $sellerPhone = $this->extractSellerPhone();
        try {
            $sellerAddress = $this->extractSellerAddress($domCrawler);
        } catch (\InvalidArgumentException $e) {
            $sellerAddress = null;
        }

        $bodyTypeId = $this->extractBodyType($characteristics);
        $mileage = $this->extractMileage($characteristics);

        /**
         * @var CarAd $carAd
         * @var CarPrice $carPrice
         */
        list($carAd, $carPrice) = $this->getOrCreateCarAdCarPrice($url, $price, $currency, $mileage);

        list ($sellerCountryId, $sellerCityId) = $this->getOrCreateCountryCity($sellerAddress);
        $seller = $this->getOrCreateSeller($carAd, $sellerName, $sellerPhone, $sellerCountryId, $sellerCityId);

        try {
            $imageUrl = $domCrawler->filter(self::FIRST_IMAGE_SELECTOR)->first()->filter('a')->attr('href');
        } catch (\InvalidArgumentException $e) {
            $imageUrl = null;
        }
        $totalImagesCount = $domCrawler->filter(self::GALLERY_SELECTOR)->filter('img')->count();

        $carAd->photo_url = $imageUrl;
        $carAd->photo_amount = $totalImagesCount;
        $whatBroken = $characteristics['процент сохранности,%'] ? "Процент сохранности: " . $characteristics['процент сохранности,%'] . '%' : null;
        $carAd->what_broken = $whatBroken;
        $carAd->save(false);

        $vin = $this->extractVin($domCrawler);

        $carPrice = $this->handleCarPriceChanges($carAd, $currency, $price, $mileage, Constants::SOURCE_SITE_SS_COM);
        list($car, $isNewCar) = $this->getOrCreateCarModel($vin, $carAd);
        $this->handleCarPriceAndMileage($car, $carPrice, $carAd);

        $car->what_broken = $whatBroken;
        $car->car_mark_id = $markId;
        $car->has_service_book = (int)$this->extractHasServiceBook($domCrawler);
        $car->car_model_id = $modelId;
        $car->horsepower = $horsepower;
        $car->displacement = $displacement;
        $car->transmission = $this->extractTransmission($characteristics);
        $car->drive_type = $this->extractDriveType($domCrawler);
        $car->car_body_type_id = $bodyTypeId;
        $car->vin = $vin;
        $car->reg_number = $this->extractRegNumber($characteristics);
        $car->photo_url = $imageUrl;
        if ($car->isNewRecord) {
            if ($seller) {
                $car->first_seller_id = $seller->id;
                $car->first_seller_name = $seller->name;
                $car->first_seller_phone = $seller->phone;
                $car->first_location_country_id = $sellerCountryId;
                $car->first_location_city_id = $sellerCityId;
            }
            $car->first_url = $url;
        }
        $car->year = $this->extractYear($characteristics);
        $car->checkup_date = $this->extractCheckupDate($characteristics);
        $car->source_country_id = $this->detectSourceCountryId($characteristics);
        $car->engine_type = $this->extractEngineType($characteristics);
        $car->has_gas = (int)$this->extractHasGas($characteristics);
        $car->save(false);

        $carAd->car_id = $car->id;
        $carAd->save(false);
        if ($isNewCar) {
            $carAd->addCarToStatusRecord();
        }

        $carPrice->car_id = $car->id;
        $carPrice->save(false);

        $this->handleCarIsRemovedCase($url, $html);

        Logger::log("Saved car info for {$url}");
    }
}