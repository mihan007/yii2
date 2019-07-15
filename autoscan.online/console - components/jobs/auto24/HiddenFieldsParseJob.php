<?php

namespace console\components\jobs\auto24;

use common\helpers\Constants;
use common\helpers\Logger;
use common\models\Car;
use console\components\crawlers\auto24\HiddenFieldsCrawler;
use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;

class HiddenFieldsParseJob extends BaseObject implements RetryableJobInterface
{
    public $carId;
    public $carUrl;

    public function execute($queue)
    {
        Logger::log("Parse auto24 field for car #$this->carId with url $this->carUrl");
        $parser = new HiddenFieldsCrawler();
        $results = $parser->getHiddenDataByUrl($this->carUrl);
        foreach ($results as $fieldName => $fieldValue) {
            if (is_string($fieldValue)) {
                $results[$fieldName] = trim($fieldValue);
            } else {
                $results[$fieldName] = $fieldValue;
            }
        }

        $car = Car::findOne(['id' => $this->carId]);
        $saved = [];
        if (isset($results['phone'])) {
            $resultingPhones = implode(',', $results['phone']);
            $car->firstSeller->phone = $resultingPhones;
            $car->firstSeller->save(false, ['phone']);
            $preparedPhones = array_map(function ($phoneString) {
                $clearedText = str_replace('Tel:', '', $phoneString);
                $clearedText = preg_replace('|[\+\.:_\-\(\)\s]|', '', $clearedText);
                $text = preg_replace('|\d|', '', $clearedText);
                $number = preg_replace('|\D|', '', $clearedText);
                if (strlen($number) < 8) {
                    $number = '372' . $number;
                }
                $number = '+' . $number;
                return [$number, $text];
            }, $results['phone']);
            $phones = $car->firstSeller->updatePhones($preparedPhones);
            $car->updateAttributes([
                'first_seller_phone' => implode(", ", $phones)
            ]);
            $saved[] = 'phone';
        }
        if (isset($results['reg_num'])) {
            $car->reg_number = $results['reg_num'];
            $car->save(false, ['reg_number']);
            $saved[] = 'reg_number';
        }
        if (isset($results['vin'])) {
            $car->vin = $results['vin'];
            $car->save(false, ['vin']);
            $saved[] = 'vin';
        } else {
            $car->vin = Constants::VIN_IS_EMPTY;
            $car->save(false, ['vin']);
        }
        $whatWeGot = sizeof($saved)>0 ? implode(", ", $saved) : 'nothing';
        Logger::log("Successful parsed fields for car #$this->carId, parsed: {$whatWeGot}");
    }


    public function getTtr()
    {
        return 5 * 60;
    }

    public function canRetry($attempt, $error)
    {
        return $attempt < 5;
    }
}
