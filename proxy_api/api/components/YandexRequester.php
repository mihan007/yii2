<?php

namespace api\components;

use common\models\LogRequest;
use yii\web\HttpException;

class YandexRequester extends Requester
{
    /**
     * @var LogRequest
     */
    public $logRequest;
    public $request;
    public $login;
    public $password;

    private $isPrepared = false;

    protected function getStartOfUrl()
    {
        return 'https://api.direct.yandex.com/';
    }

    public function prepare($params)
    {
        if (!isset($params['logRequest'])) {
            throw new Exception('Please pass logRequest');
        }
        if (!$params['logRequest'] instanceof \common\models\LogRequest) {
            throw new Exception('logRequest should be instance of LogRequest');
        }
        if (!isset($params['login'])) {
            throw new Exception('Please pass login');
        }
        if (!isset($params['password'])) {
            throw new Exception('Please pass password');
        }

        $this->logRequest = $params['logRequest'];
        $this->login = $params['login'];
        $this->password = $params['password'];
        $this->isPrepared = true;
    }

    /**
     * @see https://tech.yandex.ru/direct/doc/examples-v5/php5-curl-campaigns-docpage/
     */
    public function run()
    {
        if (!$this->isPrepared) {
            throw new Exception('Please prepare YandexRequester before run');
        }

        $url = $this->buildUrl();
        $token = $this->password;
        $clientLogin = $this->login;
        $initialHeaders = $this->cleanHeaders();

        $initialHeaders['Authorization'] = "Bearer $token"; // OAuth-токен. Использование слова Bearer обязательно
        $initialHeaders['Client-Login'] = $clientLogin;   // Логин клиента рекламного агентства

        $headers = $this->prepareHeaders($initialHeaders);

        $body = $this->logRequest->request_body;

        $requestStartTime = time();
        // Инициализация cURL
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        if ($this->logRequest->request_method == 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);
        $result = curl_exec($curl);

        $requestEndTime = time();
        $proccessingTime = $requestEndTime - $requestStartTime;
        $this->logRequest->response_time = $proccessingTime;

        if (!$result) {
            $responseError = 'Ошибка cURL: ' . curl_errno($curl) . ' - ' . curl_error($curl);
            $this->logRequest->response_code = 0;
            $this->logRequest->response_body_original = $responseError;
            $this->logRequest->save(false);

            curl_close($curl);

            throw new HttpException(500, $responseError);
        } else {
            // Разделение HTTP-заголовков и тела ответа
            $responseHeadersSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $responseHeaders = $this->getHeadersFromCurlResponse($result);
            $responseBody = substr($result, $responseHeadersSize);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            $this->logRequest->response_code = $httpCode;
            $this->logRequest->response_headers = json_encode($responseHeaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->logRequest->response_body_original = $this->saveOriginalResponseBodyToFile($responseBody, $responseHeaders);
            $modifiedResponseBody = $this->logRequest->responseBodyModified;
            $this->logRequest->response_body_modified = $this->saveModifiedResponseBodyToFile($modifiedResponseBody, $responseHeaders);
            $this->logRequest->save(false);

            curl_close($curl);

            $response = \Yii::$app->response;
            $response->setStatusCode($httpCode);
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
            $response->content = $modifiedResponseBody;
            $response->send();
            exit;
        }
    }
}