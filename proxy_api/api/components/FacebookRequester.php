<?php

namespace api\components;

use common\models\Campaign;
use common\models\LogRequest;
use yii\web\HttpException;

class FacebookRequester extends Requester
{
    /**
     * @var LogRequest
     */
    public $logRequest;
    public $request;
    public $password;
    public $urlParams;

    private $isPrepared = false;

    protected function getStartOfUrl()
    {
        return 'https://graph.facebook.com';
    }

    public function prepare($params)
    {
        if (!isset($params['logRequest'])) {
            throw new Exception('Please pass logRequest');
        }
        if (!$params['logRequest'] instanceof LogRequest) {
            throw new Exception('logRequest should be instance of LogRequest');
        }
        if (!isset($params['password'])) {
            throw new Exception('Please pass password');
        }
        if (!isset($params['urlParams'])) {
            throw new Exception('Please pass urlParams');
        }

        $this->logRequest = $params['logRequest'];
        $this->password = $params['password'];
        $this->urlParams = $params['urlParams'];
        $this->isPrepared = true;
    }

    /**
     * @see https://developers.facebook.com/docs/marketing-api/insights
     */
    public function run()
    {
        $campaigns = Campaign::findAll([
            'client_project_account_id' => $this->logRequest->client_project_account_id,
            'status' => Campaign::STATUS_ACTIVE]);

        if (!$this->isPrepared) {
            throw new Exception('Please prepare FacebookRequester before run');
        }

        $date_from = \Yii::$app->request->getQueryParam('date_from');
        $date_to = \Yii::$app->request->getQueryParam('date_to');
        $level = $this->getLevel();

        $body = $this->buildPostFields($campaigns, $date_from, $date_to, $level);
        $this->logRequest->request_body = implode(", ", $body);
        $curl = $this->buildCurlRequest($campaigns, $date_from, $date_to, $body);

        $requestStartTime = time();
        $result = curl_exec($curl);
        $requestEndTime = time();

        $processingTime = $requestEndTime - $requestStartTime;
        $this->logRequest->response_time = $processingTime;

        if (!$result) {
            $responseError = 'Ошибка cURL: ' . curl_errno($curl) . ' - ' . curl_error($curl);
            $this->saveErrorLogRequest($responseError);
            curl_close($curl);
            throw new HttpException(500, $responseError);
        } else {
            // Разделение HTTP-заголовков и тела ответа
            $responseHeadersSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $responseHeaders = $this->getHeadersFromCurlResponse($result);
            $responseBody = substr($result, $responseHeadersSize);
            $this->logRequest->response_body_original = $this->saveOriginalResponseBodyToFile($responseBody, $responseHeaders);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            $modifiedResponseBody = $this->logRequest->responseBodyModified;
            $this->saveLogRequest($httpCode, $responseHeaders, $responseBody, $modifiedResponseBody);

            curl_close($curl);

            $response = \Yii::$app->response;
            $response->setStatusCode($httpCode);
            $this->setResponseHeaders($response, $responseHeaders, $modifiedResponseBody);
            $response->content = $modifiedResponseBody;
            $response->send();
            exit;
        }
    }

    protected function buildUrl()
    {
        return $this->getStartOfUrl() . $this->logRequest->url . '?' . http_build_query($this->urlParams);
    }

    private function saveLogRequest($httpCode, array $responseHeaders, $responseBody)
    {
        $this->logRequest->response_code = $httpCode;
        $this->logRequest->response_headers = json_encode($responseHeaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->logRequest->response_body_modified = $this->saveModifiedResponseBodyToFile($responseBody, $responseHeaders);
        $this->logRequest->save(false);
    }

    //TODO: вынести общие методы в Requester
    private function saveErrorLogRequest($responseError)
    {
        $this->logRequest->response_code = 0;
        $this->logRequest->response_body_original = $responseError;
        $this->logRequest->save(false);
    }

    private function setResponseHeaders($response, array $responseHeaders, $modifiedResponseBody)
    {
        $response->headers->removeAll();
        foreach ($responseHeaders as $headerName => $headerValue) {
            if ($headerName == 'Transfer-Encoding') {
                continue;
            }
            if ($headerName == 'Content-Length') {
                $headerValue = strlen($modifiedResponseBody);
            }
            $response->headers->add($headerName, $headerValue);
        }
    }

    /**
     * @param $campaigns
     * @param $date_from
     * @param $date_to
     * @param $level
     * @return array
     */
    private function buildPostFields($campaigns, $date_from, $date_to, $level)
    {
        $post = [
            'access_token' => \Yii::$app->params['facebookAccessToken'],
            'batch' => $this->buildFacebookBatchRequestBody($campaigns, $date_from, $date_to, $level)
        ];
        return $post;
    }

    /**
     * @param $campaigns
     * @param $date_from
     * @param $date_to
     * @param $level
     * @return string
     */
    private function buildFacebookBatchRequestBody($campaigns, $date_from, $date_to, $level)
    {
        $batchRequestBody = [];
        $method = "GET";
        $query = array(
            "level" => $level,
            "time_increment" => "1",
            "time_range" => '{"since":"' . $date_from . '","until":"' . $date_to . '"}',
            "fields" => 'campaign_id,ad_id,ad_name,campaign_name,spend,impressions,inline_link_clicks,reach',
            'limit' => 500
        );

        foreach ($campaigns as $campaign) {
            $campaignRequest = [];
            $campaignRequest["method"] = $method;
            $campaignRequest["relative_url"] = \Yii::$app->params['facebookApiVersion'] . "/" . $campaign->ad_platform_id . "/insights?" . http_build_query($query);
            $batchRequestBody[] = $campaignRequest;
        }

        return json_encode($batchRequestBody);
    }

    /**
     * @param array $campaigns
     * @param $date_from
     * @param $date_to
     * @return false|resource
     */
    private function buildCurlRequest(array $campaigns, $date_from, $date_to, $fields)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->getStartOfUrl());
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->cleanHeaders());
        curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
        return $curl;
    }

    private function getLevel(): string
    {
        $level = \Yii::$app->request->getQueryParam('level');
        if($level == null){
            $level = "campaign";
        }
        return $level;
    }
}