<?php

namespace common\models;

use admin\behaviors\CommonAttributeBehavior;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\web\HttpException;

/**
 * This is the model class for table "log_request".
 *
 * @property int $id
 * @property string $request_method
 * @property string $headers
 * @property string $url
 * @property array $url_params
 * @property int $response_code
 * @property array $response_headers
 * @property string $response_body_original
 * @property string $response_body_modified
 * @property int $created_at
 * @property int $updated_at
 * @property int $response_time
 * @property int $client_id
 * @property int $client_project_id
 * @property int $client_project_account_id
 * @property string $ad_platform_name
 * @property string $request_body
 * @property string $req_ip
 *
 * @property string $responseBodyModified
 */
class LogRequest extends \yii\db\ActiveRecord
{
    const ITEMS_PER_PAGE = 50;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'log_request';
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::className(),
            CommonAttributeBehavior::className()
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['headers', 'url_params', 'response_headers'], 'safe'],
            [['response_code', 'created_at', 'updated_at', 'response_time', 'client_id', 'client_project_id', 'client_project_account_id'], 'integer'],
            [['response_body', 'request_body'], 'string'],
            [['ad_platform_name'], 'required'],
            [['request_method', 'ad_platform_name'], 'string', 'max' => 10],
            [['url'], 'string', 'max' => 2048],
            [['req_ip'], 'string', 'max' => 16],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'request_method' => 'Request Method',
            'headers' => 'Headers',
            'url' => 'Url',
            'url_params' => 'Url Params',
            'response_code' => 'http код ответа',
            'response_headers' => 'Response Headers',
            'response_body' => 'Response Body',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'response_time' => 'Response Time',
            'client_id' => 'Client ID',
            'client_project_id' => 'Client Project ID',
            'client_project_account_id' => 'Client Project Account ID',
            'ad_platform_name' => 'Ad Platform Name',
            'request_body' => 'Request Body',
            'createdAtLabel' => 'Дата запроса',
            'responseReadableSize' => 'Размер ответа',
            'gzipped' => 'gzipped',
            'req_ip' => 'IP',
            'sourceIpAddress' => 'Местоположение'
        ];
    }

    public function getOriginalFileContent()
    {
        $fileContent = file_get_contents($this->response_body_original);
        return $fileContent;
    }

    public function getResponseBodyModified()
    {
        $processorName = $this->buildProcessorClassName();
        $lRequestMethod = strtolower($this->request_method);
        $processorFile = Yii::getAlias("@common/processors/{$this->ad_platform_name}/{$lRequestMethod}/{$processorName}.php");
        if (is_file($processorFile)) {
            require_once $processorFile;
            $fullClassName = "common\processors\\" . $this->ad_platform_name . "\\" . $lRequestMethod . "\\" . $processorName;
            $processorClass = new $fullClassName;
            $processorClass->request = $this;
            if (!$processorClass->ifRequestValid()) {
                throw new HttpException(400, 'Bad Request');
            }
            $result = $processorClass->process();
        } else {
            throw new HttpException(405, 'Method not allowed');
        }

        return $result;
    }

    private function buildProcessorClassName()
    {
        $name = preg_replace(" /[^A-Za-z0-9]/", '_', $this->url);
        return ucfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
    }

    private function humanFilesize($size, $precision = 2)
    {
        static $units = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
        $step = 1024;
        $i = 0;
        while (($size / $step) > 0.9) {
            $size = $size / $step;
            $i++;
        }
        return round($size, $precision)." ".$units[$i];
    }

    public function getResponseReadableSize()
    {
        return $this->humanFilesize($this->getResponseFileSize(), 2);
    }

    public function getModifiedResponseReadableSize()
    {
        return $this->humanFilesize($this->getModifiedResponseFileSize(), 2);
    }

    public function getResponseFileSize()
    {
        if (file_exists($this->response_body_original)) {
            return filesize($this->response_body_original);
        }
        return 0;
    }

    public function getModifiedResponseFileSize()
    {
        if (file_exists($this->response_body_modified)) {
            return filesize($this->response_body_modified);
        }
        return 0;
    }

    public function getGzipped()
    {
        $end = substr($this->response_body_original, -2);
        return ($end == 'gz') ? 'Да' : 'Нет';
    }

    public function getFormattedHeaders()
    {
        $headers = json_decode($this->headers);
        return json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function getSourceIpAddress()
    {
        $ip = Yii::$app->geoip->ip($this->req_ip);

        $parts = [];
        $parts[] = $ip->country;
        $parts[] = $ip->city;

        $parts = array_filter($parts);

        return implode(", ", $parts);
    }
}
