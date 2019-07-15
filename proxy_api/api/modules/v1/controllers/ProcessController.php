<?php

namespace api\modules\v1\controllers;

use common\models\ClientProjectAccount;
use common\models\LogRequest;
use yii\web\HttpException;

class ProcessController extends Controller
{
    public function actionRequest()
    {
        $request = \Yii::$app->request;
        $headers = $this->normalizeHeaders($request->getHeaders()->toArray());

        $authorizationRow = $this->checkAuthorizationHeader($headers);
        if ($authorizationRow === false) {
            throw new HttpException(403, 'Access denied');
        }
        if (strlen($authorizationRow['ip']) > 0) {
            $isAccessPossible = $this->checkAccessByIp($authorizationRow['ip']);
            if (!$isAccessPossible) {
                $userIp = \Yii::$app->request->getUserIP();
                throw new HttpException(403, 'Access denied for ' . $userIp);
            }
        }
        if (!$this->isTestRequest()) {
            $limits = $this->checkLimit($authorizationRow);
            if (!$limits) {
                throw new HttpException(429, 'Too Many Requests');
            }
        }

        $requestMethod = $request->method;
        $cleanUrl = $this->cleanifyUrl($request->getAbsoluteUrl());
        $urlParams = $request->getQueryParams();

        $logRequest = $this->buildLogRequest($requestMethod, $headers, $cleanUrl, $urlParams, $request, $authorizationRow);
        $logRequest->save(false);

        if (!$this->isTestRequest()) {
            $this->increaseRequestCounter($authorizationRow);
        }

        switch ($authorizationRow['ad_platform_name']) {
            case ClientProjectAccount::PLATFORM_YANDEX:
                /**
                 * @var \api\components\YandexRequester $yandexRequester
                 */
                $yandexRequester = \Yii::$app->yandexRequester;
                try {
                    $yandexRequester->prepare([
                        'logRequest' => $logRequest,
                        'login' => $authorizationRow['ad_platform_login'],
                        'password' => $authorizationRow['ad_platform_password'],
                    ]);
                    $yandexRequester->run();
                } catch (\HttpException $e) {
                    throw new HttpException($e->statusCode, $e->getMessage());
                } catch (\Exception $e) {
                    throw new HttpException(500, $e->getMessage());
                }
                break;
            case ClientProjectAccount::PLATFORM_GOOGLE:
                /**
                 * @var \api\components\GoogleRequester $googleRequester
                 */
                $googleRequester = \Yii::$app->googleRequester;
                try {
                    $googleRequester->prepare([
                        'logRequest' => $logRequest,
                        'login' => $authorizationRow['ad_platform_login'],
                        'password' => $authorizationRow['ad_platform_password'],
                        'token' => $authorizationRow['google_token'],
                        'token_expire_time' => $authorizationRow['google_token_expire_time'],
                        'authorization_row_id' => $authorizationRow['id']
                    ]);
                    $googleRequester->run();
                } catch (\Exception $e) {
                    throw new HttpException(500, $e->getMessage());
                }
                break;
            case ClientProjectAccount::PLATFORM_VK:
                /**
                 * @var \api\components\VkRequester vkRequester
                 */
                $vkRequester = \Yii::$app->vkRequester;
                try {
                    $vkRequester->prepare([
                        'logRequest' => $logRequest,
                        'password' => $authorizationRow['ad_platform_password'],
                        'urlParams' => $urlParams
                    ]);
                    $vkRequester->run();
                } catch (\HttpException $e) {
                    throw new HttpException($e->statusCode, $e->getMessage());
                } catch (\Exception $e) {
                    throw new HttpException(500, $e->getMessage());
                }
                break;
            case ClientProjectAccount::PLATFORM_FACEBOOK:
                /**
                 * @var \api\components\FacebookRequester facebookRequester
                 */
                $facebookRequester = \Yii::$app->facebookRequester;
                try {
                    $facebookRequester->prepare([
                        'logRequest' => $logRequest,
                        'password' => $authorizationRow['ad_platform_password'],
                        'urlParams' => $urlParams
                    ]);
                    $facebookRequester->run();
                } catch (\HttpException $e) {
                    throw new HttpException($e->statusCode, $e->getMessage());
                } catch (\Exception $e) {
                    throw new HttpException(500, $e->getMessage());
                }
                break;
            default:
                throw new HttpException(400, 'Bad params');
        }
    }

    private function normalizeHeaders($headers)
    {
        $result = [];
        foreach ($headers as $headerName => $values) {
            if (is_scalar($values)) {
                $result[$headerName] = $values;
            } else if (is_array($values)) {
                $result[$headerName] = reset($values);
            }
        }

        return $result;
    }

    private function cleanifyUrl($url)
    {
        $apiUrl = \Yii::$app->params['apiUrl'];
        $apiUrl = str_replace($apiUrl, '', $url);
        $questionMark = strpos($apiUrl, '?');
        if ($questionMark !== false) {
            $apiUrl = substr($apiUrl, 0, $questionMark);
        }

        return $apiUrl;
    }

    private function checkAccessByIp($ip)
    {
        $clientIp = \Yii::$app->request->getUserIP();
        if (strpos($ip, $clientIp) !== false) {
            return true;
        }

        return false;
    }

    private function checkAuthorizationHeader($headers)
    {
        if (!isset($headers['authorization'])) {
            return false;
        }
        if (!isset($headers['platform'])) {
            return false;
        }

        $authorizationKey = $headers['authorization'];
        $platformName = $headers['platform'];

        $row = (new \yii\db\Query())
            ->select([
                'id',
                'client_id',
                'client_project_id',
                'ad_platform_name',
                'ad_platform_login',
                'ad_platform_password',
                'ip',
                'google_token',
                'google_token_expire_time',
                'req_daily_limit',
                'req_done_today'
            ])
            ->from('client_project_account')
            ->where('api_key = :api_key AND ad_platform_name = :ad_platform_name', [
                ':api_key' => $authorizationKey,
                ':ad_platform_name' => $platformName
            ])
            ->createCommand()
            ->queryOne();

        return $row;
    }

    private function checkLimit($authRow)
    {
        return $authRow['req_done_today'] < $authRow['req_daily_limit'];
    }

    private function increaseRequestCounter($authRow)
    {
        $sql = "UPDATE client_project_account SET req_done_today = req_done_today + 1 WHERE id = " . intval($authRow['id']);
        $connection = \Yii::$app->db;
        $connection->createCommand($sql)->execute();
    }

    private function isTestRequest()
    {
        $clientIp = \Yii::$app->request->getUserIP();
        $ourIps = [
            '127.0.0.1',
            '178.162.60.29',
            '46.4.32.139',
            '151.248.116.29',
            '188.244.38.151'
        ];

        return in_array($clientIp, $ourIps);
    }

    /**
     * @param $requestMethod
     * @param array $headers
     * @param $cleanUrl
     * @param array $urlParams
     * @param $request
     * @param $authorizationRow
     * @return LogRequest
     */
    private function buildLogRequest($requestMethod, array $headers, $cleanUrl, array $urlParams, $request, $authorizationRow)
    {
        $logRequest = new LogRequest();
        $logRequest->request_method = $requestMethod;
        $logRequest->headers = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $logRequest->url = $cleanUrl;
        $logRequest->url_params = json_encode($urlParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $logRequest->request_body = $request->getRawBody();
        $logRequest->client_id = $authorizationRow['client_id'];
        $logRequest->client_project_id = $authorizationRow['client_project_id'];
        $logRequest->client_project_account_id = $authorizationRow['id'];
        $logRequest->ad_platform_name = $authorizationRow['ad_platform_name'];
        $logRequest->req_ip = \Yii::$app->request->getUserIP();
        return $logRequest;
    }
}