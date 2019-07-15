<?php


namespace console\components\crawlers\auto24;

use common\helpers\Logger;
use common\models\Car;
use common\models\CarAd;
use common\models\CarAdStatus;
use common\models\CarPrice;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use yii\helpers\VarDumper;

class ActualizationCrawler extends CarCrawler
{
    const IS_INACTIVE_SELECTOR = '#errorMessage';
    const PARAMS_SELECTOR = '.main-data tr';
    const OTHER_INFO = '.other-info';

    const CUR_EUR = 'EUR';
    const CUR_USD = 'USD';
    const CUR_RUB = 'RUB';

    public function crawl($url, $html)
    {
        $domCrawler = new DomCrawler($html);

        $isSold = $domCrawler->filter('.errorMessage')->count() > 0;
        $paramsExists = $domCrawler->filter(self::PARAMS_SELECTOR)->count() > 0;
        $carAd = CarAd::findByAuto24Url($url);
        if (!$carAd) {
            Logger::logError("CarAd with url $url not found");
            return false;
        }
        if ($isSold || !$paramsExists) {
            $carPrice = CarPrice::find()->where(['car_ad_id' => $carAd->id])->orderBy(['updated_at' => SORT_DESC])->one();
            if ($carAd) {
                $carAd->addRemovedStatusRecord($carPrice);
                Logger::log("Url $url marked as INACTIVE", Logger::COLOR_GREEN);
            }
            return false;
        }
        if ($domCrawler->filter('#navi-links')->count() > 0) {
            $breadcrumbs = array_map('trim', explode("»", $domCrawler->filter('#navi-links')->text()));
            $mark = $breadcrumbs[3];
            $model = $this->detectCarModel($mark, $breadcrumbs);
            list($markId, $modelId) = $this->getOrCreateMarkModel($mark, $model);
            Logger::log("[$url] mark: $mark($markId), model: $model($modelId)");
            $car = Car::findOne(['id' => $carAd->car_id]);
            if ($car) {
                $car->car_mark_id = $markId;
                $car->car_model_id = $modelId;
                $car->save(false, ['car_mark_id', 'car_model_id']);
            } else {
                Logger::log("Car for CarAd.id={$carAd->car_id} not found", Logger::COLOR_GREEN);
            }
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
        $carAd->car->updateAttributes([
            'tax' => $this->extractTax($domCrawler)
        ]);
        if (sizeof($breadcrumbs) < 5) {
            Logger::logError("Something wrong with auto24 ad, skip", ['url' => $url]);
            return false;
        }
        $params = $domCrawler->filter(self::PARAMS_SELECTOR)->each(function (DomCrawler $node, $i) {
            return $node->text();
        });
        $characteristics = [];
        foreach ($params as $row) {
            $row = preg_replace('/\s+/S', " ", $row);;
            $parts = explode(":", $row);
            if (isset($parts[0]) && isset($parts[1])) {
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
        $mileage = $this->extractMileage($characteristics);
        $engineType = $this->extractEngineType($characteristics);
        if ($engineType) {
            $carAd->car->updateAttributes(['engine_type' => $engineType]);
        }

        $this->checkPrice($price);

        if (!$carAd) {
            Logger::logError("CarAd with url $url not found");
            return;
        }

        $bodyTypeId = $this->detectBodyTypeId($characteristics);
        if (($bodyTypeId) && ($carAd->car->car_body_type_id != $bodyTypeId)) {
            $carAd->car->car_body_type_id = $bodyTypeId;
            $carAd->car->save();
        }

        $carPrice = CarPrice::find()
            ->where([
                'car_ad_id' => $carAd->id,
                'currency' => $currency
            ])->orderBy(['updated_at' => SORT_DESC])
            ->one();

        $this->fixCarPrice($carPrice, $price, $mileage);

        $changedPrice = $carPrice && ($carPrice->price != $price);
        $changedMileage = $carPrice && ($carPrice->mileage != $mileage);

        if ($changedPrice || $changedMileage) {
            $carPrice = $this->createNewCarPrice($carAd, $price, $currency, $mileage);
            $carAd->addUpdatedStatusRecord($carPrice, $changedPrice, $changedMileage);
            Logger::log("For url $url changed price: " . VarDumper::dumpAsString($changedPrice) . ", changed mileage: " . VarDumper::dumpAsString($changedMileage),
                Logger::COLOR_GREEN);
        } elseif (!$carPrice) {
            $carPrice = $this->createNewCarPrice($carAd, $price, $currency, $mileage);
            $carAd->addNewStatusRecord($carPrice);
        }
        $this->handleCarPriceAndMileage($carAd->car, $carPrice, $carAd);
        $carAd->car->save();

        $isInactiveText = $domCrawler->filter(self::IS_INACTIVE_SELECTOR)->count() > 0 ? trim($domCrawler->filter(self::IS_INACTIVE_SELECTOR)->first()->text()) : '';
        if ($isInactiveText === 'Объявление не активное!') {
            $carAd->addRemovedStatusRecord($carPrice);
            Logger::log("Url $url marked as INACTIVE", Logger::COLOR_GREEN);
        } else {
            if ($carAd->current_status == CarAdStatus::STATUS_INACTIVE) {
                Logger::log("Url $url marked as ACTIVE", Logger::COLOR_GREEN);
                $carAd->addRenewedStatusRecord($carPrice);
            }
        }
    }
}