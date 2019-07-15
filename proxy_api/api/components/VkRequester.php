<?php

namespace api\components;

use common\models\LogRequest;
use yii\web\HttpException;

class VkRequester extends Requester
{
    const ACCESS_TOKEN = '';
    const USER_ACCOUNT_ID = 'USER_ACCOUNT_ID';
    const VK_API_VERSION = '5.92';

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
        return 'https://api.vk.com/';
    }

    public function prepare($params)
    {
        if (!isset($params['logRequest'])) {
            throw new Exception('Please pass logRequest');
        }
        if (!$params['logRequest'] instanceof \common\models\LogRequest) {
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
     * @see https://tech.yandex.ru/direct/doc/examples-v5/php5-curl-campaigns-docpage/
     */
    public function run()
    {
        if (!$this->isPrepared) {
            throw new Exception('Please prepare VkRequester before run');
        }

        $initialHeaders = $this->cleanHeaders();
        $initialHeaders['Content-Length'] = '0';
        $this->buildQueryParams();
        $url = $this->buildUrl();

        $requestStartTime = time();
        $curl = curl_init();
        $this->buildCurlRequest($curl, $url, $initialHeaders);
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
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            $this->logRequest->response_body_original = $this->saveOriginalResponseBodyToFile($responseBody, $responseHeaders);
            $modifiedResponseBody = $this->logRequest->responseBodyModified;
            $this->saveLogRequest($httpCode, $responseHeaders, $responseBody);

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

    private function buildQueryParams()
    {
        $this->urlParams["account_id"] = self::USER_ACCOUNT_ID;
        $this->urlParams["client_id"] = $this->password;
        $this->urlParams["ids"] = $this->password;
        $this->urlParams["ids_type"] = 'client';
        $this->urlParams["period"] = 'day';
        $this->urlParams["access_token"] = self::ACCESS_TOKEN;
        $this->urlParams["v"] = self::VK_API_VERSION;
    }

    private function buildCurlRequest($curl, $url, array $initialHeaders)
    {
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $initialHeaders);
    }

    private function saveErrorLogRequest($responseError)
    {
        $this->logRequest->response_code = 0;
        $this->logRequest->response_body_original = $responseError;
        $this->logRequest->save(false);
    }

    private function saveLogRequest($httpCode, array $responseHeaders, $responseBody)
    {
        $this->logRequest->response_code = $httpCode;
        $this->logRequest->response_headers = json_encode($responseHeaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->logRequest->response_body_modified = $this->saveModifiedResponseBodyToFile($responseBody, $responseHeaders);
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
}