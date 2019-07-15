<?php

namespace console\components\crawlers\auto24;

use common\helpers\Constants;
use common\helpers\Logger;
use common\helpers\Utils;
use common\models\Car;
use common\models\CarAd;
use common\models\Country;
use common\models\CarMark;
use common\models\CarModel;
use common\models\CarPrice;
use common\models\CarSeller;
use common\models\CarSellerLocation;
use common\models\City;
use console\components\crawlers\CarsCrawler;
use console\components\CurlHelper;
use console\components\jobs\auto24\HiddenFieldsParseJob;
use console\components\Url;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Yii;

class CarCrawler extends CarsCrawler
{
    const IS_AUTO_SELECTOR = '#navi-links a';

    const MARK_SELECTOR = '#navi-links a';
    const MODEL_SELECTOR = '#navi-links a';
    const DETAIL_SELECTOR = 'h1.commonSubtitle';
    const DEALER_SELECTOR = 'h1.commonSubtitle .dealer-name';
    const PARAMS_SELECTOR = '.main-data tr';
    const OTHER_INFO = '.other-info';
    const SELLER_NAME_SELECTOR = '.seller h2.commonSubtitle';
    const SELLER_PHONE_ICON_SELECTOR = '.icon-phone';
    const SELLER_PHONE_SELECTOR = 'div span';
    const SELLER_ADDRESS_ICON_SELECTOR = '.icon-location';
    const SELLER_ADDRESS_SELECTOR = 'div';
    const FIRST_IMAGE_SELECTOR = '#uvImgContainer img';
    const GALLERY_SELECTOR = '#uvImgContainer';

    const CUR_EUR = 'EUR';
    const CUR_USD = 'USD';
    const CUR_RUB = 'RUB';
    const CUR_UNKNOWN = '---';

    public function crawl($url, $html)
    {
        $domCrawler = new DomCrawler($html);

        $isSold = $domCrawler->filter('.errorMessage')->count() > 0;
        if ($isSold) {
            $carAd = CarAd::findByAuto24Url($url);
            if ($carAd) {
                $carPrice = CarPrice::find()->where(['car_ad_id' => $carAd->id])->orderBy(['updated_at' => SORT_DESC])->one();
                $carAd->addRemovedStatusRecord($carPrice);
                Logger::log("Url $url marked as INACTIVE", Logger::COLOR_GREEN);
            }
            return false;
        }
        try {
            $isAutoSelector = trim($domCrawler->filter(self::IS_AUTO_SELECTOR)->eq(1)->text());
        } catch (InvalidArgumentException $exception) {
            Logger::logError("Something wrong with this auto24 ad, skip", ['url' => $url]);
            return false;
        }

        if ($isAutoSelector != 'Под. автомобили') {
            Logger::logError("It is not auto auto24 ad, skip", ['url' => $url]);
            $carAd = CarAd::findByAuto24Url($url);
            if ($carAd) {
                $carPrice = CarPrice::find()->where(['car_ad_id' => $carAd->id])->orderBy(['updated_at' => SORT_DESC])->one();
                $carAd->addRemovedStatusRecord($carPrice);
            }
            return false;
        }
        $category = mb_strtolower(trim($domCrawler->filter(self::IS_AUTO_SELECTOR)->eq(2)->text()));
        Logger::log("Category of item is $category", Logger::COLOR_BLUE);
        if ($this->isExcluded($category)) {
            Logger::log("We don't neet category $category, skip", Logger::COLOR_GREEN);
            $carAd = CarAd::findByAuto24Url($url);
            if ($carAd) {
                $carPrice = CarPrice::find()->where(['car_ad_id' => $carAd->id])->orderBy(['updated_at' => SORT_DESC])->one();
                $carAd->addRemovedStatusRecord($carPrice);
            }
            return false;
        }

        $breadcrumbs = array_map('trim', explode("»", $domCrawler->filter('#navi-links')->text()));
        if (sizeof($breadcrumbs) < 5) {
            Logger::logError("Something wrong with auto24 ad, skip", ['url' => $url]);
            $carAd = CarAd::findByAuto24Url($url);
            if ($carAd) {
                $carPrice = CarPrice::find()->where(['car_ad_id' => $carAd->id])->orderBy(['updated_at' => SORT_DESC])->one();
                $carAd->addRemovedStatusRecord($carPrice);
            }
            return false;
        }
        $mark = $breadcrumbs[3];
        $model = $this->detectCarModel($mark, $breadcrumbs);
        $detail = $domCrawler->filter(self::DETAIL_SELECTOR)->first()->text();
        try {
            $dealerName = $domCrawler->filter(self::DEALER_SELECTOR)->first()->text();
        } catch (InvalidArgumentException $e) {
            $dealerName = "";
        }
        $detail = str_replace($dealerName, "", $detail);
        $parts = explode(" ", $detail);
        $displacement = $this->normalizeDisplacement($parts);
        $horsepower = $this->normalizeHorsePower($parts);

        list($markId, $modelId) = $this->getOrCreateMarkModel($mark, $model);

        $params = $domCrawler->filter(self::PARAMS_SELECTOR)->each(function (DomCrawler $node, $i) {
            return $node->text();
        });
        $characteristics = [];
        foreach ($params as $row) {
            $row = preg_replace('/\s+/S', " ", $row);;
            $parts = explode(":", $row);
            if (isset($parts[1])) {
                $charName = mb_strtolower(trim($parts[0]));
                $charValue = trim($parts[1]);
                if (strlen($charName) > 0) {
                    $characteristics[$charName] = $charValue;
                }
            }
        }
        $otherInfoText = trim($domCrawler->filter(self::OTHER_INFO)->html());
        $otherInfoParts = explode("<br>", $otherInfoText);
        foreach ($otherInfoParts as $infoPart) {
            $infoPart = str_replace("<b>", ": ", $infoPart);
            if (strpos($infoPart, ':') === false) {
                continue;
            }
            $parts = explode(": ", $infoPart);
            $charName = mb_strtolower(trim($parts[0], "\t\n: "));
            $charValue = trim(strip_tags(end($parts)));
            if (strlen($charName) > 0) {
                $characteristics[$charName] = $charValue;
            }
        }

        $price = $this->extractPrice($characteristics);
        $currency = $this->detectCurrency($characteristics);

        $this->checkPrice($price);

        try {
            $sellerName = trim($domCrawler->filter(self::SELLER_NAME_SELECTOR)->text());
        } catch (InvalidArgumentException $e) {
            $sellerName = null;
        }
        try {
            $sellerPhoneElement = $domCrawler->filter(self::SELLER_PHONE_ICON_SELECTOR)->siblings()->filter(self::SELLER_PHONE_SELECTOR);
            $sellerPhone = $this->extractSellerPhone($url, $sellerPhoneElement);
        } catch (InvalidArgumentException $e) {
            $sellerPhone = null;
        }
        try {
            $sellerAddress = trim($domCrawler->filter(self::SELLER_ADDRESS_ICON_SELECTOR)->siblings()->filter(self::SELLER_ADDRESS_SELECTOR)->text());
        } catch (InvalidArgumentException $e) {
            $sellerAddress = null;
        }

        $bodyTypeId = $this->detectBodyTypeId($characteristics);
        $mileage = $this->extractMileage($characteristics);

        /**
         * @var CarAd $carAd
         * @var CarPrice $carPrice
         */
        list($carAd, $carPrice) = $this->getOrCreateCarAdCarPrice($url, $price, $currency, $mileage);

        list ($sellerCountryId, $sellerCityId) = $this->getOrCreateCountryCity($sellerAddress, $characteristics);
        $seller = $this->getOrCreateSeller($carAd, $sellerName, $sellerPhone, $sellerCountryId, $sellerCityId);

        $imageUrl = $domCrawler->filter(self::FIRST_IMAGE_SELECTOR)->first()->attr('src');
        $totalImagesCount = $domCrawler->filter(self::GALLERY_SELECTOR)->filter('a')->count();

        $carAd->photo_url = $imageUrl;
        $carAd->photo_amount = $totalImagesCount;
        $carAd->save(false);

        $vin = $this->extractVin($characteristics);

        /**
         * @var Car $car
         */
        $car = false;
        $isNewCar = false;
        if ($vin) {
            $car = Car::findOne(['vin' => $vin]);
        }
        if (!$car && ($carAd->car_id)) {
            $car = Car::findOne(['id' => $carAd->car_id]);
        }
        if (!$car) {
            $car = new Car();
            $isNewCar = true;
        }
        $car->car_mark_id = $markId;
        $car->has_service_book = $this->extractHasServiceBook($characteristics);
        $car->car_model_id = $modelId;
        $car->horsepower = $horsepower;
        $car->displacement = $displacement;
        $car->transmission = $this->extractTransmission($characteristics);
        $car->drive_type = $this->extractDriveType($characteristics);
        $car->car_body_type_id = $bodyTypeId;
        $this->handleCarPriceAndMileage($car, $carPrice, $carAd);
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
        $car->tax = $this->extractTax($domCrawler);
        $car->save(false);

        $carAd->car_id = $car->id;
        $carAd->save(false);
        if ($isNewCar) {
            $carAd->addCarToStatusRecord();
        }
        $vinIsEmpty = ($car->vin == Constants::VIN_IS_EMPTY) || (strlen(trim($car->vin)) == 0);
        $vinHasAppeared = !$isNewCar && $vinIsEmpty && $this->hasVin($domCrawler);
        if ($isNewCar || $vinHasAppeared) {
            \Yii::$app->queueAuto24HiddenFields->priority(10)->push(new HiddenFieldsParseJob([
                'carUrl' => $car->first_url,
                'carId' => $car->id,
            ]));
        }

        $carPrice->car_id = $car->id;
        $carPrice->save(false);

        Logger::log("Saved car info for {$url}");

        return $car->id;
    }

    public static function getIdFromUrl($source_url)
    {
        $parts = explode("/", $source_url);
        return end($parts);
    }

    public static function getUnqueIdFromUrl($source_url)
    {
        return 'auto24'.self::getIdFromUrl($source_url);
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

    protected function normalizeDisplacement($parts)
    {
        foreach ($parts as $part) {
            if (preg_match('/\d\.\d/', $part)) {
                return $this->makeNumber($part) * 1000;
            }
        }

        return null;
    }

    protected function detectCurrency($characteristics)
    {
        return self::CUR_EUR;
    }

    private function getOrCreateCountryCity($cityName, $characteristic)
    {
        $countryName = false;
        $country = false;
        $city = false;
        if (isset($characteristic['местонахождение автомобиля'])) {
            $parts = explode(", ", $characteristic['местонахождение автомобиля']);
            if (sizeof($parts) == 2) {
                $cityName = $parts[0];
                $countryName = $parts[1];
            } else {
                $countryName = $parts[0];
                $cityName = false;
            }
        }
        if ($countryName) {
            $country = Country::findOne(['name' => $countryName]);
            if (!$country) {
                $country = new Country();
                $country->name = $countryName;
                $country->source = Constants::SOURCE_SITE_AUTO24;
                $country->save();
            }
        }
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
        if (isset($characteristics['показ одометра']) && strpos($characteristics['показ одометра'], 'km') !== false) {
            $parts = explode("km", $characteristics['показ одометра']);
            $mileage = $this->makeNumber($parts[0]);
            return $mileage;
        }

        return null;
    }

    protected function extractEngineType($characteristics)
    {
        //'gasoline','diesel','electro','hybrid'
        $result = isset($characteristics['топливо']) ? $characteristics['топливо'] : false;
        if ($result) {
            return $this->detectEngineType($result);
        }

        return null;
    }

    private function extractHasGas($characteristics)
    {
        return isset($characteristics['топливо']) && mb_strpos($characteristics['топливо'], 'газ');
    }

    private function detectSourceCountryId($characteristics)
    {
        $result = isset($characteristics['привезено из страны']) ? $characteristics['привезено из страны'] : false;
        if ($result) {
            $country = Country::findOne(['name' => $result]);
            if (!$country) {
                $country = new Country();
                $country->name = $result;
                $country->source = Constants::SOURCE_SITE_AUTO24;
                $country->save(false);
            }

            return $country->id;
        }

        return null;
    }

    private function extractYear($characteristics)
    {
        if (isset($characteristics['первичная рег'])) {
            return substr($characteristics['первичная рег'], -4);
        }

        return null;
    }

    private function extractCheckupDate($characteristics)
    {
        foreach ($characteristics as $charName => $charValue) {
            if (mb_strpos($charName, 'техосмотр до') !== false) {
                $parts = explode(".", $charValue);
                if (isset($parts[1])) {
                    return "{$parts[1]}-{$parts[0]}-01 00:00:00";
                } else {
                    return "{$parts[0]}-01-01 00:00:00";
                }
            }
        }

        return null;
    }

    private function extractTransmission($characteristics)
    {
        if (isset($characteristics['коробка передач'])) {
            if (mb_strpos($characteristics['коробка передач'], 'механ') !== false) {
                return 'MT';
            } else {
                return 'AT';
            }
        }

        return null;
    }

    private function extractDriveType($characteristics)
    {
        $result = isset($characteristics['ведущий мост']) ? $characteristics['ведущий мост'] : false;
        if ($result) {
            if (strpos($result, 'передн') !== false) {
                return Car::DRIVE_TYPE_FRONT;
            }
            if (strpos($result, 'задн') !== false) {
                return Car::DRIVE_TYPE_BACK;
            }
            return Car::DRIVE_TYPE_FULL;
        }

        return null;
    }

    private function normalizeHorsePower($parts)
    {
        foreach ($parts as $part) {
            if (Utils::endsWith($part, 'kW')) {
                $numberKW = $this->makeNumber($part);
                return round(1.34102209 * $numberKW);
            }
        }

        return null;
    }

    private function extractVin($characteristics)
    {
        //todo: Научиться извлекать вин номер
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
    private function getOrCreateCarAdCarPrice($url, $price, $currency, $mileage)
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
            $carAd->source = Constants::SOURCE_SITE_AUTO24;
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

    private function getOrCreateSeller(CarAd $carAd, $sellerName, $sellerPhone, $sellerCountryId, $sellerCityId)
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
    protected function createNewCarPrice($carAd, $price, $currency, $mileage): CarPrice
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

    public static function isCarUrl($url)
    {
        if (!is_string($url)) {
            $url = (string)$url;
        }
        $condition = Url::starts_with($url, 'https://rus.auto24.ee/used');
        $condition = $condition && (mb_strpos($url, 'rus.auto24.ee') !== false);

        return $condition;
    }

    protected function extractPrice(array $characteristics)
    {
        $priceText = $this->extractCurrentPriceText($characteristics);
        if ($priceText) {
            return $this->makeNumber($priceText);
        }

        return null;
    }

    /**
     * @param $characteristics
     * @return bool|mixed
     */
    private function extractCurrentPriceText($characteristics)
    {
        $priceText = null;
        if (isset($characteristics['цена со скидкой'])) {
            $discountPrice = $characteristics['цена со скидкой'];
            $eurPos = strpos($discountPrice, 'EUR');
            $priceText = substr($discountPrice, 0, $eurPos);
        } elseif (isset($characteristics['цена'])) {
            $price = $characteristics['цена'];
            $eurPos = strpos($price, 'EUR');
            $priceText = substr($price, 0, $eurPos);
        }
        return $priceText;
    }

    private function extractSellerPhone($initUrl, $sellerPhoneElement)
    {
        //todo: Научиться извлекать номер продавца
        return null;

        //https://rus.auto24.ee/services/data_json.php?q=uv_telnr&k=e9cd360...
        $q = $sellerPhoneElement->attr('data-action');
        $k = $sellerPhoneElement->attr('data-key');
        $url = "https://rus.auto24.ee/services/data_json.php?q={$q}&k={$k}";

        $response = CurlHelper::crawlUrl($initUrl, false);
        $response = CurlHelper::crawlUrl($url, false);
        $content = json_decode($response);
        if ($content) {
            $result = @$content->q->response->value;
            return $this->makeNumber($result);
        }

        return null;
    }

    private function extractHasServiceBook(array $characteristics)
    {
        return (isset($characteristics['показ одометра']) && mb_strpos($characteristics['показ одометра'],
                'книга обслуживания') === false) ? 0 : 1;
    }

    private function extractRegNumber(array $characteristics)
    {
        //todo: Научиться извлекать регистрационный номер
        return null;
    }

    public function isExcluded(?string $category)
    {
        return in_array($category, [
            'автобус',
            'автожильё',
            'дача-прицеп',
            'агротехника',
            'аукционные автомобили',
            'прицеп',
            'мототехника',
            'надводный транспорт',
            'строительная техника',
            'лесная техника',
            'грузовой автомобиль',
            'коммунальная техника',
            'другое',
            'гоночные машины'
        ]);
    }

    public static function markCarAdAsRemovedByUrl($url)
    {
        $carAd = CarAd::findOne(['source_url' => $url]);
        if ($carAd) {
            $carPrice = CarPrice::find()
                ->where([
                    'car_ad_id' => $carAd->id
                ])->orderBy(['updated_at' => SORT_DESC])
                ->one();
            return $carAd->addRemovedStatusRecord($carPrice);
        }

        return false;
    }

    /**
     * @param string|UriInterface $url
     * @return bool
     */
    public static function isIndexPage($url): bool
    {
        $urlToCheck = (string)$url;
        $pageParam = "ak=";
        $isPagingLink = mb_strpos($urlToCheck, $pageParam) !== false;
        $isRussianLink = mb_strpos($urlToCheck, 'rus.auto24.ee') !== false;
        return $isRussianLink && $isPagingLink;
    }

    protected function extractTax(DomCrawler $domCrawler)
    {
        $textValue = $domCrawler->filter('.vat-value')->count() > 0 ? $domCrawler->filter('.vat-value')->text() : null;
        if ($textValue == 'содержит НДС 20%') {
            return Constants::TAX_INCLUDED;
        }
        if ($textValue == 'НДС 0% (НДС не добавляется)') {
            return Constants::TAX_NOT_INCLUDED;
        }

        return null;
    }

    /**
     * @param array $breadcrumbs
     * @return mixed
     */
    protected function detectCarModel(string $mark, array $breadcrumbs)
    {
        $possibleModel = $breadcrumbs[4];
        $parts = explode("-", $possibleModel);
        if ((isset($parts[1])) && (sizeof($parts) == 2) && (is_numeric($parts[0])) && (is_numeric($parts[1]))) {
            $model = end($breadcrumbs);
            $model = trim(str_replace($mark, '', $model));
        } else {
            $model = trim($possibleModel);
        }

        return $model;
    }

    private function isOutroad($typeOfBodyType)
    {
        $typeOfBodyType = trim($typeOfBodyType);
        return mb_strtolower($typeOfBodyType) == 'внедорожник';
    }

    private function normalizeBodyType($bodyType)
    {
        $possibleBodyTypes = [
            'седан',
            'хетчбек',
            'универсал',
            'объемный универсал',
            'купе',
            'кабриолет',
            'пикап',
            'лимузин',
            'грузовой микроавтобус'
        ];
        $bodyType = trim(mb_strtolower($bodyType));
        foreach ($possibleBodyTypes as $possibleBodyType) {
            if (mb_strpos($possibleBodyType, $bodyType) !== false) {
                return trim($bodyType);
            }
        }
        $filepath = Yii::getAlias('@runtime/logs/auto24_body_types.log', $bodyType);
        Logger::logToFile($filepath, $bodyType);

        return null;
    }

    /**
     * @param array $characteristics
     * @return integer|null
     */
    protected function detectBodyTypeId(array $characteristics)
    {
        $typeOfBodyType = isset($characteristics['тип']) ? $characteristics['тип'] : null;
        $bodyType = isset($characteristics['тип кузова']) ? $characteristics['тип кузова'] : false;
        if ($this->isOutroad($typeOfBodyType)) {
            $bodyType = 'Внедорожник';
        } elseif ($bodyType) {
            $bodyType = $this->normalizeBodyType($bodyType);
        }
        if ($bodyType) {
            $bodyTypeId = $this->getOrCreateBodyType($bodyType);
        } else {
            $bodyTypeId = null;
        }

        return $bodyTypeId;
    }

    protected function hasVin(DomCrawler $domCrawler)
    {
        return $domCrawler->filter('.field-tehasetahis .service-trigger')->count() > 0;
    }
}
