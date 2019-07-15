<?php


namespace console\components\crawlers;


use common\helpers\Constants;
use common\models\Car;
use common\models\CarAd;
use common\models\CarBodyType;
use common\models\CarPrice;
use common\models\Country;

class CarsCrawler
{
    protected $changeUpdatedForCar;

    public function __construct($changeUpdatedForCar = true)
    {
        $this->changeUpdatedForCar = $changeUpdatedForCar;
    }

    /**
     * @param $string
     * @return string|string[]|null
     */
    public function makeNumber($string)
    {
        $string = preg_replace('/[^0-9\.]/', '', $string);
        if (substr($string, -1) == '.') {
            $string .= '00';
        }
        return $string;
    }

    /**
     * @param Car $car
     * @param CarPrice $carPrice
     * @param CarAd $carAd
     */
    protected function handleCarPriceAndMileage($car, CarPrice $carPrice, CarAd $carAd): void
    {
        $car->updateAttributes([
            'current_price' => $carPrice->price,
            'current_mileage' => $carPrice->mileage
        ]);
        if (!$carAd->first_price) {
            $carAd->updateAttributes([
                'first_price' => $carPrice->price
            ]);
        }
        if (!$carAd->first_mileage) {
            $carAd->updateAttributes([
                'first_mileage' => $carPrice->mileage
            ]);
        }
        if (!$car->isNewRecord) {
            $prevCarPrice = CarPrice::find()
                ->where(['car_ad_id' => $carAd->id])
                ->andWhere(['!=', 'id', $carPrice->id])
                ->orderBy(['created_at' => SORT_DESC])
                ->limit(1)
                ->one();
            if ($prevCarPrice) {
                $attributesToSave = [];
                $car->prev_price_deviation = $car->current_price - $prevCarPrice->price;
                if (abs($car->prev_price_deviation) < 1e6) {
                    $attributesToSave[] = 'prev_price_deviation';
                }
                $car->prev_mileage_deviation = $car->current_mileage - $prevCarPrice->mileage;
                if (abs($car->prev_mileage_deviation) < 1e6) {
                    $attributesToSave[] = 'prev_mileage_deviation';
                }
                if (sizeof($attributesToSave)>0) {
                    $car->save(false, $attributesToSave);
                }
            }
        }
    }

    /**
     * @param string|null $vin
     * @param $carAd
     * @return array
     */
    protected function getOrCreateCarModel(?string $vin, $carAd): array
    {
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
        if (!$isNewCar && !$this->changeUpdatedForCar) {
            $car->detachBehavior('timestamp');
        }
        /**
         * @var Car $car
         */
        return array($car, $isNewCar);
    }

    /**
     * @param CarAd $carAd
     * @param string $currency
     * @param $price
     * @param $mileage
     * @return array|CarPrice|\yii\db\ActiveRecord|null
     */
    protected function handleCarPriceChanges($carAd, string $currency, $price, $mileage, $source)
    {
        $carPrice = CarPrice::find()
            ->where([
                'car_ad_id' => $carAd->id,
                'currency' => $currency
            ])->orderBy(['updated_at' => SORT_DESC])
            ->one();

        $this->fixCarPrice($carPrice, $price, $mileage);

        if ($price) {
            $changedPrice = $carPrice && ($carPrice->price != $price);
        } else {
            $changedPrice = false;
            if ($carPrice) {
                $price = $carPrice->price;
            }
        }
        if ($mileage) {
            $changedMileage = $carPrice && ($carPrice->mileage != $mileage);
        } else {
            $changedMileage = false;
            if ($carPrice) {
                $mileage = $carPrice->mileage;
            }
        }

        if ($changedPrice || $changedMileage) {
            $this->changeUpdatedForCar = true;
            $carPrice = $this->createNewCarPrice($carAd, $price, $currency, $mileage, $source);
            $carAd->addUpdatedStatusRecord($carPrice, $changedPrice, $changedMileage);
        } elseif (!$carPrice) {
            $carPrice = $this->createNewCarPrice($carAd, $price, $currency, $mileage, $source);
            $carAd->addNewStatusRecord($carPrice);
        }
        return $carPrice;
    }

    /**
     * @param CarAd $carAd
     * @param $price
     * @param $currency
     * @param $mileage
     * @return CarPrice
     */
    private function createNewCarPrice($carAd, $price, $currency, $mileage, $source): CarPrice
    {
        $carPrice = new CarPrice();
        $carPrice->price = $price;
        $carPrice->mileage = $mileage;
        $carPrice->currency = $currency;
        $carPrice->car_ad_id = $carAd->id;
        $carPrice->car_id = $carAd->car_id;
        $carPrice->source = $source;
        $carPrice->save(false);

        return $carPrice;
    }

    /**
     * @param $carPrice
     * @param $price
     * @param $mileage
     */
    protected function fixCarPrice(CarPrice $carPrice, $price, $mileage): void
    {
        if ($carPrice) {
            if (!$carPrice->price && $price) {
                $carPrice->updateAttributes(['price' => $price]);
            }
            if (!$carPrice->mileage && $mileage) {
                $carPrice->updateAttributes(['mileage' => $mileage]);
            }
        }
    }

    protected function checkPrice($price)
    {
        if (is_numeric($price) && ($price >= 1e6)) {
            throw new \Exception("Something wrong with price", ['price' => $price]);
        }
    }

    /**
     * @param $result
     * @return string
     */
    protected function detectEngineType($result): string
    {
        $normalized = mb_strtolower($result);
        $isDiesel = strpos($normalized, 'диз') !== false;
        $isPetrolium = strpos($normalized, 'бенз') !== false;
        $isElectro = strpos($normalized, 'электр') !== false;
        $isHybrid = (strpos($normalized, 'hyb') !== false) || (strpos($normalized, 'гиб') !== false);
        if ($isHybrid) {
            return Constants::ENGINE_TYPE_HYBRID;
        }
        if ($isPetrolium && $isElectro) {
            return Constants::ENGINE_TYPE_HYBRID;
        }
        if ($isDiesel && $isElectro) {
            return Constants::ENGINE_TYPE_HYBRID;
        }
        if ($isDiesel) {
            return Constants::ENGINE_TYPE_DIESEL;
        }
        if ($isPetrolium) {
            return Constants::ENGINE_TYPE_GASOLINE;
        }
        if ($isElectro) {
            return Constants::ENGINE_TYPE_ELECTRO;
        }

        return null;
    }

    protected function getOrCreateBodyType($bodyType)
    {
        $bodyType = trim($bodyType);
        $carBodyType = CarBodyType::findOne(['name' => $bodyType]);
        if (!$carBodyType) {
            $carBodyType = new CarBodyType();
            $carBodyType->name = $bodyType;
            $carBodyType->save(false);
        }

        return $carBodyType->id;
    }
}