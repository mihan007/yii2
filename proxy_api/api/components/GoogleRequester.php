<?php

namespace api\components;

use common\models\LogRequest;
use yii\web\HttpException;

class GoogleRequester extends Requester
{
    // Required AdWords API properties. Details can be found at:
    // https://developers.google.com/adwords/api/docs/guides/basic-concepts#soap_and_xml
    const DEVELOPER_TOKEN = 'DEVELOPER_TOKEN';

    const DEVELOPER_TOKEN_PLACEHOLDER = '%DEVELOPER_TOKEN%';
    const CLIENT_CUSTOMER_ID_PLACEHOLDER = '%CLIENT_CUSTOMER_ID%';

    // For installed application or web application flow.
    const AUTH_CODE = '4/AUTH_CODE';
    const CLIENT_ID = "CLIENT_ID";
    const CLIENT_SECRET = "CLIENT_SECRET";
    const REFRESH_TOKEN = "REFRESH_TOKEN";
    const REDIRECT_URI = 'REDIRECT_URI';
    const GRANT_TYPE = 'refresh_token';
    const REQUEST_NEW_TOKEN_SECONDS = 60; //if left less than 60 seconds request new token

    protected function getStartOfUrl()
    {
        return 'https://adwords.google.com/';
    }

    /**
     * @var LogRequest
     */
    public $logRequest;
    public $request;
    public $login;
    public $password;

    private $access_token;

    private $isPrepared = false;

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

        $shouldWeRequestNewToken = empty($params['token']) || ($params['token_expire_time'] <= time()) || ($params['token_expire_time'] - time() < self::REQUEST_NEW_TOKEN_SECONDS);
        if ($shouldWeRequestNewToken) {
            if ($this->getAccessToken($params['authorization_row_id'])) {
                $this->isPrepared = true;
            }
        } else {
            $this->access_token = $params['token'];
            $this->isPrepared = true;
        }
    }

    private function getAccessToken($authorizationRowId)
    {
        $url = 'https://accounts.google.com/o/oauth2/token';
        $post = [
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => self::REFRESH_TOKEN,
            'grant_type' => self::GRANT_TYPE
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);
        $result = json_decode(curl_exec($curl));
        if ($result === false) {
            return false;
        } else {
            $this->access_token = $result->access_token;
            \Yii::$app->db->createCommand()
                ->update('client_project_account', [
                    'google_token' => $result->access_token,
                    'google_token_expire_time' => time() + $result->expires_in
                ],
                    'id = '.(int)$authorizationRowId)
                ->execute();
            return true;
        }
    }

    private function prepareBody($body)
    {
        $body = str_replace(self::DEVELOPER_TOKEN_PLACEHOLDER, self::DEVELOPER_TOKEN, $body);
        $body = str_replace(self::CLIENT_CUSTOMER_ID_PLACEHOLDER, $this->password, $body);
        return $body;
    }

    /**
     * @see https://tech.yandex.ru/direct/doc/examples-v5/php5-curl-campaigns-docpage/
     */
    public function run()
    {
        if (!$this->isPrepared) {
            throw new Exception('Please prepare GoogleRequester before run');
        }

        $url = $this->buildUrl();
        $token = $this->access_token;
        $initialHeaders = $this->cleanHeaders();

        $initialHeaders['Authorization'] = "Bearer $token"; // OAuth-токен. Использование слова Bearer обязательно

        $headers = $this->prepareHeaders($initialHeaders);
        $body = $this->prepareBody($this->logRequest->request_body);

        $requestStartTime = time();
        // Инициализация cURL
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        if ($this->logRequest->request_method == 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
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
                $response->headers->add($headerName, $headerValue);
            }
            $response->content = $modifiedResponseBody;
            $response->send();
            exit;
        }
    }

    protected function prepareHeaders($headers)
    {
        $validHeaders = [
            'Authorization',
            'developertoken',
            'clientcustomerid',
            'skipcolumnheader',
            'skipreportheader',
            'skipreportsummary',
            'userawenumvalues',
            'host',
            'user-agent',
            'content-type'
        ];
        $result = [];
        foreach ($headers as $k => $v) {
            if ($k == 'host') {
                $result[] = "$k: adwords.google.com";
                continue;
            }
            if (!in_array($k, $validHeaders)) {
                continue;
            }
            $newV = str_replace(self::DEVELOPER_TOKEN_PLACEHOLDER, self::DEVELOPER_TOKEN, $v);
            $newV = str_replace(self::CLIENT_CUSTOMER_ID_PLACEHOLDER, $this->password, $newV);
            $result[] = "$k: $newV";
        }

        return $result;
    }
}