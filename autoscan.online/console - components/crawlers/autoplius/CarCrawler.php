<?php

namespace console\components\crawlers\autoplius;

use common\helpers\Constants;
use common\helpers\Logger;
use common\models\Car;
use common\models\CarAd;
use common\models\Country;
use common\models\CarMark;
use common\models\CarModel;
use common\models\CarPrice;
use common\models\CarSeller;
use common\models\CarSellerLocation;
use common\models\City;
use console\components\CurlHelper;
use console\components\Url;
use console\components\crawlers\CarsCrawler;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use thiagoalessio\TesseractOCR\TesseractOCR;

class CarCrawler extends CarsCrawler
{
    const MARK_SELECTOR = '.breadcrumbs .crumb';
    const MODEL_SELECTOR = '.breadcrumbs .crumb';
    const DETAIL_SELECTOR = 'h1';
    const DOUBLE_PRICE_SELECTOR = '.price .price-column';
    const PRICE_SELECTOR = '.price';
    const CURRENCY_SELECTOR = '.default-currency';
    const SELLER_NAME_SELECTOR = '.seller-contact-name';
    const SELLER_PHONE_SELECTOR = '.seller-phone-number';
    const SELLER_ADDRESS_SELECTOR = '.seller-contact-location';
    const PARAMS_SELECTOR = '.parameter-row';
    const FIRST_IMAGE_SELECTOR = '.thumbnail';
    const GALLERY_SELECTOR = '.announcement-media-gallery';
    const MORE_IMAGES_SELECTOR = '.thumbnail.has-more-items > div > p';
    const IS_AUTO_SELECTOR = '.crumb';
    const IS_URGENT_SELECTOR = '.is-sale';
    const TAX_SELECTOR = '.price-taxes';

    const CUR_EUR = 'EUR';
    const CUR_USD = 'USD';
    const CUR_RUB = 'RUB';
    const CUR_UNKNOWN = '---';

    const MARK_SEARCH_PAGE_URL = 'https://ru.autoplius.lt/poisk/b-u-avtomobili?category_id=2';
    const MARK_SELECT_SELECTOR = '#make_id_list';
    const IS_ARCHIVED_SELECTOR = '.is-archived';

    private static $markIds = [];
    /**
     * @var array
     */
    private $characteristics = [];

    public static function isMobileCarUrl($url)
    {
        return strpos($url, "ru.m") !== false;
    }

    public static function convertUrlToMobile($url)
    {
        if (self::isMobileCarUrl($url)) {
            return $url;
        }
        return str_replace('ru.', 'ru.m.', $url);
    }

    public static function getIdFromUrl($source_url)
    {
        $re = '/-(\d+).html/m';
        preg_match_all($re, $source_url, $matches, PREG_SET_ORDER, 0);
        return isset($matches[0][1]) ? $matches[0][1] : null;
    }

    public static function getUnqueIdFromUrl($source_url)
    {
        return 'autoplius'.self::getIdFromUrl($source_url);
    }

    public function crawl($url, $html)
    {
        $domCrawler = new DomCrawler($html);

        try {
            $isAutoSelector = trim($domCrawler->filter(self::IS_AUTO_SELECTOR)->eq(1)->text());
        } catch (\InvalidArgumentException $exception) {
            Logger::logError("Something wrong with this autoplius ad, skip", ['url' => $url]);
            return;
        }

        if ($isAutoSelector != 'Aвтомобили') {
            Logger::log("At {$url} is not auto ad, skip", Logger::COLOR_BLUE);
            return;
        }
        try {
            trim($domCrawler->filter(self::IS_ARCHIVED_SELECTOR)->first()->text());
            /**
             * @var CarAd $carAd
             */
            $carAd = CarAd::findByAutopliusUrl($url);
            if ($carAd) {
                $carPrice = CarPrice::find()
                    ->where([
                        'car_ad_id' => $carAd->id
                    ])->orderBy(['updated_at' => SORT_DESC])
                    ->one();
                if ($carPrice) {
                    $carAd->addRemovedStatusRecord($carPrice);
                }
            }
            Logger::log("Ad $url is inactive, stop", Logger::COLOR_YELLOW);
            return;
        } catch (\InvalidArgumentException $e) {
            Logger::log("Ad $url is active, continue");
        }
        if ($this->isAdRemoved($html)) {
            $carAd = CarAd::findByAutopliusUrl($url);
            if ($carAd) {
                $carPrice = CarPrice::find()
                    ->where([
                        'car_ad_id' => $carAd->id
                    ])->orderBy(['updated_at' => SORT_DESC])
                    ->one();
                if ($carPrice) {
                    $carAd->addRemovedStatusRecord($carPrice);
                }
            }
            Logger::log("Ad $url is inactive, stop", Logger::COLOR_YELLOW);
            return;
        }
        $mark = trim($domCrawler->filter(self::MARK_SELECTOR)->eq(2)->text());
        $model = trim($domCrawler->filter(self::MODEL_SELECTOR)->eq(3)->text());
        $detail = $domCrawler->filter(self::DETAIL_SELECTOR)->first()->text();
        $parts = explode(",", $detail);
        $displacementNormalized = null;
        $bodyType = null;
        $bodyTypeId = null;
        if (isset($parts[2])) {
            $bodyType = trim($parts[2]);
            $displacementText = trim($parts[1]);
            $displacementNormalized = $this->normalizeDisplacement($displacementText);
            $bodyTypeId = $this->getOrCreateBodyType($bodyType);
        } elseif (isset($parts[1])) {
            $bodyType = trim($parts[1]);
            $bodyTypeId = $this->getOrCreateBodyType($bodyType);
        }
        list($markId, $modelId) = $this->getOrCreateMarkModel($mark, $model);
        $priceText = $this->crawlPriceText($domCrawler);
        if (!$priceText) {
            Logger::logError("This car $url probably inactive, skip");
            return;
        }
        $price = $this->makeNumber($priceText);
        $this->checkPrice($price);

        $currencyText = trim($domCrawler->filter(self::CURRENCY_SELECTOR)->first()->text());
        $currency = $this->detectCurrency($currencyText);
        $sellerNameCrawler = $domCrawler->filter(self::SELLER_NAME_SELECTOR)->first();
        try {
            $sellerName = trim($sellerNameCrawler->text());
            $sellerPhone = preg_replace('/[^0-9]/', '',
                trim($domCrawler->filter(self::SELLER_PHONE_SELECTOR)->first()->text()));
            $sellerAddress = trim($domCrawler->filter(self::SELLER_ADDRESS_SELECTOR)->first()->text());
            list ($sellerCountryId, $sellerCityId) = $this->getOrCreateCountryCity(explode(" ", $sellerAddress));
            $seller = $this->getOrCreateSeller($sellerName, $sellerPhone, $sellerCountryId, $sellerCityId);
        } catch (\InvalidArgumentException $exception) {
            Logger::logError("This car $url was sold so seller is null");
            $seller = null;
        }

        $params = $domCrawler->filter(self::PARAMS_SELECTOR)->each(function (DomCrawler $node, $i) {
            return $node->text();
        });
        $characteristics = [];
        foreach ($params as $row) {
            $row = trim(str_replace("  ", "", $row));
            $parts = explode("\n\n", $row);
            if (!isset($parts[1])) {
                continue;
            }
            $charName = trim($parts[0]);
            $charValue = trim($parts[1]);
            if (strlen($charName) > 0) {
                $characteristics[$charName] = $charValue;
            }
        }
        $this->characteristics = $characteristics;
        $mileage = $this->extractMileage();

        list($carAd, $carPrice) = $this->getOrCreateCarAdCarPrice($url, $price, $currency, $mileage, $seller);
        list($imageUrl, $totalImagesCount) = $this->handleImages($domCrawler);

        $whatBroken = $this->extractWhatBroken($characteristics);
        $taxValue = $domCrawler->filter(self::TAX_SELECTOR)->count() > 0 ? Constants::TAX_NOT_INCLUDED : null;
        $exportPrice = $this->extractExportPrice();
        $carAd->what_broken = $whatBroken;
        $carAd->photo_url = $imageUrl;
        $carAd->photo_amount = $totalImagesCount;
        $carAd->tax = $taxValue;
        $carAd->autoplius_id = self::getIdFromUrl($url);
        $carAd->save(false);

        Logger::log("Started vin parsing from $url");
        $vin = $this->extractVin($domCrawler);
        Logger::log("Got vin from $url: {$vin}");

        /**
         * @var Car $car
         */
        list($car, $isNewCar) = $this->getOrCreateCarModel($vin, $carAd);
        $car->what_broken = $whatBroken;
        $car->car_mark_id = $markId;
        $car->car_model_id = $modelId;
        $car->horsepower = $this->extractHorsePower($characteristics);
        $car->displacement = $displacementNormalized;
        $car->transmission = $this->extractTransmission($characteristics);
        $car->drive_type = $this->extractDriveType($characteristics);
        $car->car_body_type_id = $bodyTypeId;
        $this->handleCarPriceAndMileage($car, $carPrice, $carAd);
        $car->has_service_book = null;
        $car->vin = $vin;
        $car->photo_url = $imageUrl;
        $car->is_urgent = $domCrawler->filter(self::IS_URGENT_SELECTOR)->count() > 0;
        $car->tax = $taxValue;
        $car->export_price = $exportPrice;
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
        $car->has_gas = $this->extractHasGas($characteristics);
        $car->save(false);

        $carAd->car_id = $car->id;
        $carAd->save(false);
        if ($isNewCar) {
            $carAd->addCarToStatusRecord();
        }

        $carPrice->car_id = $car->id;
        $carPrice->save(false);
        Logger::log("Saved car info for {$url}");
    }

    public static function isCarUrl($url)
    {
        $urlToCheck = (string)$url;
        $question = strpos($urlToCheck, '?');
        if ($question !== false) {
            $urlToCheck = substr($urlToCheck, 0, $question);
        }
        $condition = Url::starts_with($urlToCheck, 'https://ru.autoplius.lt/objavlenija/');
        $condition = $condition && Url::ends_with($urlToCheck, '.html');
        $condition = $condition && (strpos($urlToCheck, '/kita') === false);
        $condition = $condition && (strpos($urlToCheck, '/fotos/') === false);

        return $condition;
    }

    public static function normalizeUrl($url)
    {
        $question = strpos($url, '?');
        if ($question !== false) {
            $url = substr($url, 0, $question);
        }
        return str_replace('ru.m.', 'ru.', $url);
    }

    public static function buildUrlListOfMarkIndexPages()
    {
        if (sizeof(self::$markIds) == 0) {
            $page = CurlHelper::crawlUrl(self::MARK_SEARCH_PAGE_URL);
            $domCrawler = new DomCrawler($page);

            $markIds = [];
            $domCrawler->filter(self::MARK_SELECT_SELECTOR)->filter('option')->each(function ($el) use (&$markIds) {
                $markIds[$el->attr('value')] = trim($el->text());
            });

            self::$markIds = $markIds;
        }

        return self::$markIds;
    }

    protected function getOrCreateMarkModel($markName, $modelName)
    {
        $carMark = CarMark::findOne(['name' => $markName]);
        if (!$carMark) {
            $carMark = new CarMark();
            $carMark->name = $markName;
            $carMark->save(false);
        }
        $carModel = CarModel::findOne(['name' => $modelName, 'car_mark_id' => $carMark->id]);
        if (!$carModel) {
            $carModel = new CarModel();
            $carModel->name = $modelName;
            $carModel->car_mark_id = $carMark->id;
            $carModel->save(false);
        }

        return [$carMark->id, $carModel->id];
    }

    protected function normalizeDisplacement($displacementText)
    {
        $parts = explode(" ", $displacementText);
        $possibleDisplacement = trim($parts[0]);
        if (is_numeric($possibleDisplacement)) {
            $litr = $possibleDisplacement * 1000;
        } else {
            $litr = null;
        }

        return $litr;
    }

    protected function detectCurrency($currencyText)
    {
        switch ($currencyText) {
            case '€':
                return self::CUR_EUR;
            case '$':
                return self::CUR_USD;
            default:
                return self::CUR_UNKNOWN;
        }
    }

    private function getOrCreateCountryCity($parts)
    {
        if (isset($parts[1])) {
            $countryName = trim($parts[1]);
        } else {
            $countryName = trim($parts[0]);
        }
        $country = Country::findOne(['name' => $countryName]);
        if (!$country) {
            $country = new Country();
            $country->name = $countryName;
            $country->save(false);
        }
        if (isset($parts[1])) {
            $cityName = trim($parts[0], ",");
        } else {
            $cityName = 'Не указан';
        }
        $city = City::findOne(['name' => $cityName, 'country_id' => $country->id]);
        if (!$city) {
            $city = new City();
            $city->name = $cityName;
            $city->country_id = $country->id;
            $city->save();
        }

        return [$country->id, $city->id];
    }

    private function extractMileage()
    {
        return $this->makeNumber($this->extractCharacteristicByName('пробег'));
    }

    private function extractExportPrice()
    {
        return $this->makeNumber($this->extractCharacteristicByName('На экспорт'));
    }

    private function extractEngineType($characteristics)
    {
        $needVal = 'тип топлива';
        $result = false;
        foreach ($characteristics as $charName => $charValue) {
            if (mb_strtolower($charName) == $needVal) {
                $result = $charValue;
                break;
            }
        }

        if ($result) {
                return $this->detectEngineType($result);
        }

        return null;
    }

    private function extractHasGas($characteristics)
    {
        $needVal = 'тип топлива';
        $result = false;
        foreach ($characteristics as $charName => $charValue) {
            if (mb_strtolower($charName) == $needVal) {
                $result = $charValue;
                break;
            }
        }

        if ($result) {
            $normalized = mb_strtolower($result);
            if (strpos($normalized, 'газ') !== false) {
                return true;
            }
        }

        return false;
    }

    private function detectSourceCountryId($characteristics)
    {
        $result = $this->extractCharacteristicByName('страна первичной регистрации');
        if ($result) {
            $country = Country::findOne(['name' => $result]);
            if (!$country) {
                $country = new Country();
                $country->name = $result;
                $country->save(false);
            }

            return $country->id;
        }

        return null;
    }

    private function extractYear($characteristics)
    {
        $needVal = 'дата выпуска';
        foreach ($characteristics as $charName => $charValue) {
            if (mb_strtolower($charName) == $needVal) {
                return substr($charValue, 0, 4);
            }
        }

        return null;
    }

    private function extractCheckupDate($characteristics)
    {
        $result = null;
        $needVal = 'техосмотр до';
        foreach ($characteristics as $charName => $charValue) {
            if (mb_strtolower($charName) == $needVal) {
                if (strlen($charValue) > 4) {
                    $result = $charValue . "-01 00:00:00";
                } else {
                    $result = $charValue . "-01-01 00:00:00";
                }
                break;
            }
        }

        return $result;
    }

    private function extractTransmission($characteristics)
    {
        $needVal = 'коробка передач';
        foreach ($characteristics as $charName => $charValue) {
            if (mb_strtolower($charName) == $needVal) {
                $result = $charValue;
                break;
            }
        }
        switch ($result) {
            case 'Механическая':
                return 'MT';
        }

        return 'AT';
    }

    private function extractDriveType($characteristics)
    {
        $needVal = 'тип трансмиссии';
        $result = false;
        foreach ($characteristics as $charName => $charValue) {
            if (mb_strtolower($charName) == $needVal) {
                $result = mb_strtolower($charValue);
                break;
            }
        }
        if ($result) {
            return $this->detectDriveType($result);
        }

        return null;
    }

    private function extractHorsePower($characteristics)
    {
        $needVal = 'двигатель';
        $result = false;
        foreach ($characteristics as $charName => $charValue) {
            if (mb_strtolower($charName) == $needVal) {
                $result = $charValue;
                break;
            }
        }
        if ($result) {
            $parts = explode(", ", $result);
            if (!isset($parts[1])) {
                return null;
            }
            $parts2 = explode(" ", $parts[1]);
            return $parts2[0];
        }

        return null;
    }

    protected function extractVin(DomCrawler $dom)
    {
        $images = $dom->filter('.parameter-value img');
        if ($images->count() < 1) {
            return null;
        }
        $image = $images->first()->attr('src');
        $image = str_replace('data:image/png;base64, ', '', $image);
        $file = tmpfile();
        fwrite($file, base64_decode($image));
        $path = stream_get_meta_data($file)['uri'];
        $vin = (new TesseractOCR($path))
            ->lang('eng')
            ->psm(7)
            ->whitelist(range(0, 9), "ABCDEFGHJKLMNPRSTUVWXYZ")
            ->run();
        fclose($file);
        if (mb_strlen($vin) != 17) {
            return null;
        }
        return $vin;
    }

    private function getOrCreateCarAdCarPrice($url, $price, $currency, $mileage, $seller)
    {
        $carAd = CarAd::findByAutopliusUrl($url);
        if (!$carAd) {
            $carAd = new CarAd();
            $carAd->source = Constants::SOURCE_SITE_AUTOPLIUS;
            $carAd->first_price = $price;
            $carAd->first_mileage = $mileage;
            $carAd->currency = $currency;
            $carAd->source_url = $url;
            $carAd->car_seller_id = $seller ? $seller->id : null;
            $carAd->save(false);

            $carPrice = $this->createNewCarPrice($carAd, $price, $currency, $mileage);
            $carAd->addNewStatusRecord($carPrice);
        } else {
            $carPrice = CarPrice::find()
                ->where([
                    'car_ad_id' => $carAd->id,
                    'currency' => $currency
                ])->orderBy(['updated_at' => SORT_DESC])
                ->one();

            $changedPrice = $carPrice && ($carPrice->price != $price);
            $changedMileage = $carPrice && ($carPrice->mileage != $mileage);

            if (!$carPrice || $changedPrice || $changedMileage) {
                $carPrice = $this->createNewCarPrice($carAd, $price, $currency, $mileage);
                $carAd->addUpdatedStatusRecord($carPrice, $changedPrice, $changedMileage);
            }
        }
        if (!$seller) {
            $carAd->addRemovedStatusRecord($carPrice);
        }

        return [$carAd, $carPrice];
    }

    private function getOrCreateSeller($sellerName, $sellerPhone, $sellerCountryId, $sellerCityId)
    {
        $carSeller = CarSeller::findOne(['phone' => $sellerPhone]);
        if (!$carSeller) {
            $carSeller = new CarSeller();
            $carSeller->name = $sellerName;
            $carSeller->phone = $sellerPhone;
            $carSeller->save(false);
        }
        $carSellerLocation = CarSellerLocation::findOne([
            'car_seller_id' => $carSeller->id,
            'country_id' => $sellerCountryId,
            'city_id' => $sellerCityId
        ]);
        if (!$carSellerLocation) {
            $carSellerLocation = new CarSellerLocation();
            $carSellerLocation->car_seller_id = $carSeller->id;
            $carSellerLocation->country_id = $sellerCountryId;
            $carSellerLocation->city_id = $sellerCityId;
            $carSellerLocation->save();
        }
        $carSeller->updatePhones([
            ['+' . $sellerPhone, $sellerName],
        ]);

        return $carSeller;
    }

    /**
     * @param CarAd $carAd
     * @param $price
     * @param $currency
     * @param $mileage
     * @return CarPrice
     */
    private function createNewCarPrice($carAd, $price, $currency, $mileage): CarPrice
    {
        $carPrice = new CarPrice();
        $carPrice->price = $price;
        $carPrice->mileage = $mileage;
        $carPrice->currency = $currency;
        $carPrice->car_ad_id = $carAd->id;
        $carPrice->car_id = $carAd->car_id;
        $carPrice->save(false);

        return $carPrice;
    }

    public static function isNonNormalizedCarUrl($url)
    {
        return strpos($url, '/objavlenija/-') !== false;
    }

    /**
     * @param DomCrawler $domCrawler
     * @return array
     */
    protected function handleImages(DomCrawler $domCrawler): array
    {
        $imageUrlText = $domCrawler->filter(self::FIRST_IMAGE_SELECTOR)->first()->attr('style');
        $imageUrlStart = strpos($imageUrlText, 'url(\'') + 5;
        $imageUrlEnd = strlen($imageUrlText) - 2;
        $imageUrl = substr($imageUrlText, $imageUrlStart, $imageUrlEnd - $imageUrlStart);

        $galleryCount = $domCrawler->filter(self::GALLERY_SELECTOR)->filter('.thumbnail')->count();
        try {
            $moreImagesCount = preg_replace('/[^0-9]/', '',
                $domCrawler->filter(self::MORE_IMAGES_SELECTOR)->first()->text());
        } catch (\InvalidArgumentException $exception) {
            $moreImagesCount = 1;
        }
        $totalImagesCount = $galleryCount + $moreImagesCount - 1;
        return array($imageUrl, $totalImagesCount);
    }

    protected function extractWhatBroken(array $characteristics)
    {
        $needVal = 'дефекты';
        foreach ($characteristics as $charName => $charValue) {
            $normalizedCharName = mb_strtolower($charName);
            if (mb_strpos($normalizedCharName, $needVal) !== false) {
                return $this->normalizeWhatBroken($charValue);
            }
        }

        return null;
    }

    /**
     * @param array $parts
     * @return string
     */
    protected function normalizeWhatBroken($brokenString)
    {
        $resultBroken = mb_strtolower(trim($brokenString));
        if ($resultBroken == 'без дефектов') {
            return null;
        }
        return $resultBroken;
    }

    /**
     * @param $name
     * @return string|string[]|null
     */
    private function extractCharacteristicByName($name)
    {
        $needVal = mb_strtolower($name);
        foreach ($this->characteristics as $charName => $charValue) {
            if (mb_strtolower($charName) == $needVal) {
                return $charValue;
            }
        }

        return null;
    }

    private function isAdRemoved($html)
    {
        return mb_strpos($html, 'Объявление не существует') !== false;
    }

    /**
     * @param DomCrawler $domCrawler
     * @return string|null
     */
    protected function crawlPriceText(DomCrawler $domCrawler)
    {
        $priceText = null;
        $doublePriceCounter = $domCrawler->filter(self::DOUBLE_PRICE_SELECTOR)->count() > 0;
        if ($doublePriceCounter) {
            $priceText = trim($domCrawler->filter(self::DOUBLE_PRICE_SELECTOR)->text());
        } else {
            $priceText = $domCrawler->filter(self::PRICE_SELECTOR)->count() > 0 ? trim($domCrawler->filter(self::PRICE_SELECTOR)->text()) : null;
        }
        return $priceText;
    }

    /**
     * @param $result
     * @return string
     */
    protected function detectDriveType($result)
    {
        $result = mb_strtolower($result);
        if (mb_strpos($result, 'передн') !== false) {
            return Car::DRIVE_TYPE_FRONT;
        }
        if (mb_strpos($result, 'задн') !== false) {
            return Car::DRIVE_TYPE_BACK;
        }
        if (mb_strpos($result, 'полн') !== false) {
            return Car::DRIVE_TYPE_FULL;
        }

        return null;
    }
}
