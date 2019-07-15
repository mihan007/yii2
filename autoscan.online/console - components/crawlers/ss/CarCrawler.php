<?php

namespace console\components\crawlers\ss;

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
use console\components\Url;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use console\components\crawlers\CarsCrawler;

class CarCrawler extends CarsCrawler
{
    const BREADCRUMBS_SELECTOR = '.headtitle';
    const MARK_SELECTOR = '.headtitle a';
    const MODEL_SELECTOR = '.headtitle a';
    const PARAMS_SELECTOR = '.options_list tr';
    const PRICE_SELECTOR = '.ads_price';
    const SELLER_ADDRESS_SELECTOR = '.contacts_table tr';
    const FIRST_IMAGE_SELECTOR = '.ads_photo_label .pic_dv_thumbnail';
    const GALLERY_SELECTOR = '.ads_photo_label';
    const ADDITIONAL_OPTIONS = '.auto_c';

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

    public static function normalizeUrl($url)
    {
        $url = str_replace('https://m.ss', 'https://www.ss', $url);
        $url = str_replace('/msg/', 'https://www.ss.com/msg/', $url);
        return $url;
    }

    public static function convertToMobile($url)
    {
        return str_replace('https://www.ss', 'https://m.ss', $url);
    }

    public static function getIdFromUrl($source_url)
    {
        $re = '/\/(\w+).html/m';
        preg_match_all($re, $source_url, $matches, PREG_SET_ORDER, 0);
        $possibleId = isset($matches[0][1]) ? $matches[0][1] : null;

        return $possibleId;
    }

    public static function getUnqueIdFromUrl($source_url)
    {
        return 'ss'.self::getIdFromUrl($source_url);
    }

    public static function convertUrlToMobile($url)
    {
        $url = str_replace('https://www.ss', 'https://m.ss', $url);
        return $url;
    }

    public function crawl($url, $html)
    {
        $domCrawler = new DomCrawler($html);

        try {
            $breadcrumbsRaw = trim($domCrawler->filter(self::BREADCRUMBS_SELECTOR)->text());
        } catch (\InvalidArgumentException $exception) {
            Logger::logError("Something wrong with this ss.com ad, skip", ['url' => $url]);
            return;
        }
        $parts = explode("/", $breadcrumbsRaw);
        $breadcrumbs = [];
        foreach ($parts as $part) {
            $breadcrumbs[] = trim($part);
        }

        if (($breadcrumbs[0] != 'Легковые авто') || ($breadcrumbs[3] != 'Продают')) {
            Logger::log("At {$url} is not sell auto ad, skip");
            if (Car::removeByUrl($url)) {
                Logger::log("Removed car with {$url} because it was not sell car");
            }
            return;
        }

        $mark = trim($domCrawler->filter(self::MARK_SELECTOR)->eq(1)->text());
        try {
            $model = trim($domCrawler->filter(self::MODEL_SELECTOR)->eq(2)->text());
        } catch (\InvalidArgumentException $e) {
            $model = null;
        }

        $characteristics = $this->extractCharacteristics($domCrawler);

        if (isset($characteristics['марка']) && ($characteristics['марка'] != '-')) {
            $mark = $characteristics['марка'];
        }
        if (($model === null) && (isset($characteristics['модель']))) {
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
        $carAd->save(false);

        $vin = $this->extractVin($domCrawler);

        $carPrice = $this->handleCarPriceChanges($carAd, $currency, $price, $mileage, Constants::SOURCE_SITE_SS_COM);
        list($car, $isNewCar) = $this->getOrCreateCarModel($vin, $carAd);
        $this->handleCarPriceAndMileage($car, $carPrice, $carAd);

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

        $removedImage = 'https://i.ss.com/img/a_ru.gif';
        if (strpos($html, $removedImage) !== false) {
            Logger::logError("Ad {$url} removed");
            $carAd = CarAd::findOne(['source_url' => $url]);
            if ($carAd) {
                $carPrice = CarPrice::find()
                    ->where([
                        'car_ad_id' => $carAd->id
                    ])->orderBy(['updated_at' => SORT_DESC])
                    ->one();
                $carAd->addRemovedStatusRecord($carPrice);
            }
        }

        Logger::log("Saved car info for {$url}");
    }

    protected function getOrCreateMarkModel($markName, $modelName)
    {
        $carMark = CarMark::findOne(['name' => $markName]);
        if (!$carMark) {
            $carMark = new CarMark();
            $carMark->name = $markName;
            $carMark->source = Constants::SOURCE_SITE_AUTO24;
            $carMark->save(false);
        }
        $carModel = CarModel::findOne(['name' => $modelName, 'car_mark_id' => $carMark->id]);
        if (!$carModel) {
            $carModel = new CarModel();
            $carModel->name = $modelName;
            $carModel->car_mark_id = $carMark->id;
            $carModel->source = Constants::SOURCE_SITE_AUTO24;
            $carModel->save(false);
        }

        return [$carMark->id, $carModel->id];
    }

    protected function normalizeDisplacement($characteristics)
    {
        if (isset($characteristics['двигатель'])) {
            if (preg_match('/\d\.\d/', $characteristics['двигатель'])) {
                return $this->makeNumber($characteristics['двигатель']) * 1000;
            }
        }

        return null;
    }

    protected function getOrCreateCountryCity($cityName)
    {
        $countryName = 'Латвия';
        //todo: Найти объявление, где явно указана страна
        $country = false;
        if ($countryName) {
            $country = Country::findOne(['name' => $countryName]);
            if (!$country) {
                $country = new Country();
                $country->name = $countryName;
                $country->source = Constants::SOURCE_SITE_AUTO24;
                $country->save();
            }
        }
        $city = false;
        if ($cityName) {
            if ($country) {
                $city = City::findOne(['name' => $cityName, 'country_id' => $country->id]);
            } else {
                $city = City::findOne(['name' => $cityName]);
            }
            if (!$city) {
                $city = new City();
                $city->name = $cityName;
                $city->country_id = $country ? $country->id : null;
                $city->source = Constants::SOURCE_SITE_AUTO24;
                $city->save();

            }
        }
        return [$country ? $country->id : null, $city ? $city->id : null];
    }

    protected function extractMileage($characteristics)
    {
        if (isset($characteristics['пробег, км'])) {
            return $this->makeNumber($characteristics['пробег, км']);
        }
        return null;
    }

    protected function extractEngineType($characteristics)
    {
        $result = isset($characteristics['двигатель']) ? $characteristics['двигатель'] : false;
        if ($result) {
            return $this->detectEngineType($result);
        }

        $result = isset($characteristics['тип двигателя']) ? $characteristics['тип двигателя'] : false;
        if ($result) {
            return $this->detectEngineType($result);
        }

        return null;
    }

    protected function extractHasGas($characteristics)
    {
        if (isset($characteristics['двигатель'])) {
            $normalized = mb_strtolower($characteristics['двигатель']);
            return mb_strpos($normalized, 'газ') !== false;
        }

        return null;
    }

    protected function detectSourceCountryId($characteristics)
    {
        //todo: найти объявление, где указана страна производства
        return null;
    }

    protected function extractYear($characteristics)
    {
        if (isset($characteristics['год выпуска'])) {
            return $this->makeNumber($characteristics['год выпуска']);
        }

        return null;
    }

    protected function extractCheckupDate($characteristics)
    {
        foreach ($characteristics as $charName => $charValue) {
            if (mb_strpos($charName, 'техосмотр') !== false) {
                $parts = explode(".", $charValue);
                $normalized = mb_strtolower($charValue);
                if (strpos($normalized, 'без') !== false) {
                    return null;
                }
                if (isset($parts[1])) {
                    return "{$parts[1]}-{$parts[0]}-01 00:00:00";
                } else {
                    return "{$parts[0]}-01-01 00:00:00";
                }
            }
        }

        return null;
    }

    protected function extractTransmission($characteristics)
    {
        if (isset($characteristics['кпп'])) {
            $normalized = mb_strtolower($characteristics['кпп']);
            if (mb_strpos($normalized, 'ручн') !== false) {
                return 'MT';
            } else {
                return 'AT';
            }
        }

        return null;
    }

    protected function extractDriveType(DomCrawler $domCrawler)
    {
        $params = $this->getParams($domCrawler);
        foreach ($params as $param) {
            if ($param == 'Полный привод 4x4') {
                return Car::DRIVE_TYPE_FULL;
            }
        }
        return null;
    }

    protected function normalizeHorsePower($characteristic)
    {
        //todo: найти объявление, где указана л.с.
        return null;
    }

    protected function extractVin($domCrawler)
    {
        //todo: найти объявление, где указан vin
        return null;
    }

    /**
     * @param $url
     * @param $price
     * @param $currency
     * @param $mileage
     * @param $seller
     * @return array
     */
    protected function getOrCreateCarAdCarPrice($url, $price, $currency, $mileage)
    {
        /**
         * @var CarAd $carAd
         */
        $carAd = CarAd::findOne(['source_url' => $url]);
        if (!$carAd) {
            $carAd = new CarAd();
            $carAd->first_price = $price;
            $carAd->first_mileage = $mileage;
            $carAd->currency = $currency;
            $carAd->source_url = $url;
            $carAd->car_seller_id = null;
            $carAd->source = Constants::SOURCE_SITE_SS_COM;
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

        return [$carAd, $carPrice];
    }

    protected function getOrCreateSeller(CarAd $carAd, $sellerName, $sellerPhone, $sellerCountryId, $sellerCityId)
    {
        $carSeller = false;
        if ($carAd->car_seller_id) {
            $carSeller = CarSeller::findOne($carAd->car_seller_id);
        } elseif ($sellerPhone) {
            $carSeller = CarSeller::findOne(['phone' => $sellerPhone]);
        }
        if (!$carSeller) {
            $carSeller = new CarSeller();
            $carSeller->name = $sellerName;
            $carSeller->phone = $sellerPhone;
            $carSeller->save(false);
            $carAd->setAttributes([
                'car_seller_id' => $carSeller->id,
            ]);
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
        $carPrice->source = Constants::SOURCE_SITE_AUTO24;
        $carPrice->save(false);

        return $carPrice;
    }

    public static function isMobileCarUrl($url)
    {
        if (!is_string($url)) {
            $url = (string)$url;
        }
        $condition = Url::starts_with($url, 'https://m.ss.com/msg/ru/transport/cars');

        return $condition;
    }

    public static function isCarUrl($url)
    {
        if (!is_string($url)) {
            $url = (string)$url;
        }
        $condition = Url::starts_with($url, 'https://www.ss.com/msg/ru/transport/cars/');

        return $condition;
    }

    public static function isDamagedCarUrl($url)
    {
        if (!is_string($url)) {
            $url = (string)$url;
        }
        //https://www.ss.com/msg/ru/transport/other/transport-with-defects-or-after-crash/diblj.html
        $condition = Url::starts_with($url,
            'https://www.ss.com/msg/ru/transport/other/transport-with-defects-or-after-crash/');

        return $condition;
    }

    protected function extractPrice($domCrawler)
    {
        $priceText = $this->getPriceText($domCrawler);
        if ($priceText) {
            return $this->makeNumber($priceText);
        }

        Logger::logError("Could not detect price");
        return null;
    }

    protected function extractSellerPhone()
    {
        //todo: Научиться извлекать номер продавца
        return null;
    }

    protected function extractHasServiceBook(DomCrawler $domCrawler)
    {
        $params = $this->getParams($domCrawler);
        foreach ($params as $param) {
            if ($param == 'Сервисная книжка') {
                return true;
            }
        }
        return null;
    }

    protected function extractRegNumber(array $characteristics)
    {
        //todo: Научиться извлекать регистрационный номер
        return null;
    }

    /**
     * @param $domCrawler
     * @return string|null
     */
    protected function getPriceText($domCrawler)
    {
        try {
            $priceText = trim($domCrawler->filter(self::PRICE_SELECTOR)->eq(0)->text());
            return $priceText;
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    protected function extractSellerName($domCrawler)
    {
        $params = $this->getLocationParams($domCrawler);
        foreach ($params as $param) {
            $parts = explode(":", $param);
            if (isset($parts[1]) && ($parts[0] === 'Компания')) {
                return $parts[1];
            }
        }
        return null;
    }

    protected function extractSellerAddress($domCrawler)
    {
        $params = $this->getLocationParams($domCrawler);
        foreach ($params as $row) {
            $parts = explode(":", $row);
            if (isset($parts[1]) && ($parts[0] == 'Место')) {
                return $parts[1];
            }
        }
    }

    /**
     * @param DomCrawler $domCrawler
     * @return array
     */
    protected function getParams(DomCrawler $domCrawler): array
    {
        return $domCrawler->filter(self::ADDITIONAL_OPTIONS)->each(function (DomCrawler $node, $i) {
            return trim($node->text());
        });
    }

    /**
     * @param $domCrawler
     * @return mixed
     */
    protected function getLocationParams($domCrawler)
    {
        return $domCrawler->filter(self::SELLER_ADDRESS_SELECTOR)->each(function (DomCrawler $node, $i) {
            return trim($node->text());
        });
    }

    /**
     * @param $url
     * @param $html
     * @return boolean
     */
    protected function handleCarIsRemovedCase($url, $html): bool
    {
        $removedImage = 'https://i.ss.com/img/a_ru.gif';
        if (strpos($html, $removedImage) !== false) {
            $carAd = CarAd::findBySsUrl($url);
            if ($carAd) {
                $carPrice = CarPrice::find()
                    ->where([
                        'car_ad_id' => $carAd->id
                    ])->orderBy(['updated_at' => SORT_DESC])
                    ->one();
                $carAd->addRemovedStatusRecord($carPrice);
                Logger::log("Marked {$url} as removed", Logger::COLOR_BLUE);

                return true;
            }
        }

        return false;
    }

    /**
     * @param DomCrawler $domCrawler
     * @return array
     */
    protected function extractCharacteristics(DomCrawler $domCrawler): array
    {
        $params = $domCrawler->filter(self::PARAMS_SELECTOR)->each(function (DomCrawler $node, $i) {
            return $node->text();
        });
        $characteristics = [];
        foreach ($params as $i => $row) {
            if ($i == 0) {
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
        return $characteristics;
    }

    /**
     * @param array $characteristics
     * @return int|null
     */
    protected function extractBodyType(array $characteristics)
    {
        $bodyType = $characteristics['тип кузова'] ?? null;
        if ($bodyType && ($bodyType != '-')) {
            $bodyTypeId = $this->getOrCreateBodyType($bodyType);
        } else {
            $bodyTypeId = null;
        }
        return $bodyTypeId;
    }
}
