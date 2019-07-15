<?php
    namespace common\processors\vk\post;

    use common\processors\CommonProcessor;
    use yii\web\HttpException;

    class MethodAdsGetStatistics extends CommonProcessor {

        public function ifRequestValid()
        {
            return true;
        }

        function process()
        {
            $json = json_decode($this->request->getOriginalFileContent());
            $response = $json->{'response'}[0];
            unset($response->{'id'}); //vk client id, it's secret
            foreach ($response->{'stats'} as $value){
                $reportDate = $value->{'day'};
                $coef = $this->getCoef($reportDate, $this->request->client_project_account_id);
                if(!$coef){
                    throw new HttpException(400, 'Bad Request');
                }
                $value->{'spent'} = $this->getSpendWithAKRound($value->{'spent'}, $coef);
            }
            return json_encode($json, JSON_UNESCAPED_UNICODE);
        }
    }