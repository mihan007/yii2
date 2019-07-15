<?php

namespace common\processors\vk\post;

use common\processors\CommonProcessor;
use yii\web\HttpException;

class MethodAdsGetAds extends CommonProcessor
{

    public function ifRequestValid()
    {
        return true;
    }

    function process()
    {
        $coef = $this->getCoef(date('Y-m-d 00:00:00'), $this->request->client_project_account_id);
        if (!$coef) {
            throw new HttpException(400, 'Bad Request');
        }

        $json = json_decode($this->request->getOriginalFileContent());
        foreach ($json->{'response'} as $value) {
            if (isset($value->{'cpm'})) {
                $value->{'cpm'} = $this->getSpendWithAKRound($value->{'cpm'}, $coef);
            }
            if (isset($value->{'cpc'})) {
                $value->{'cpc'} = $this->getSpendWithAKRound($value->{'cpc'}, $coef);
            }
        }
        return json_encode($json, JSON_UNESCAPED_UNICODE);
    }
}