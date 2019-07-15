<?php

namespace common\models;

use admin\behaviors\CommonAttributeBehavior;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\data\ActiveDataProvider;

/**
 * This is the model class for table "client_project_account".
 *
 * @property int $id
 * @property int $client_id
 * @property int $client_project_id
 * @property string $name
 * @property string $description
 * @property string $ad_platform_name
 * @property string $ad_platform_login
 * @property string $ad_platform_password
 * @property string $api_key
 * @property int $created_at
 * @property int $updated_at
 * @property int $deleted_at
 * @property int $status
 * @property string $ip
 * @property string $google_token
 * @property string $google_token_expire_time
 * @property string $req_done_today
 * @property string $req_daily_limit
 *
 * @property Client $client
 * @property ClientProject $clientProject
 * @property Campaign[] $campaigns
 */
class ClientProjectAccount extends \yii\db\ActiveRecord
{
    const STATUS_INACTIVE = 0;
    const STATUS_ACTIVE = 1;

    const PLATFORM_YANDEX = 'yandex';
    const PLATFORM_GOOGLE = 'google';
    const PLATFORM_VK = 'vk';
    const PLATFORM_FACEBOOK = 'facebook';

    public $campaigns;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'client_project_account';
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
            [['client_id', 'client_project_id', 'created_at', 'updated_at', 'deleted_at', 'status', 'req_daily_limit', 'req_done_today'], 'integer'],
            [['description'], 'string'],
            [['ad_platform_name', 'ad_platform_login', 'ad_platform_password', 'api_key'], 'required'],
            [['name', 'ad_platform_name', 'ad_platform_login', 'ad_platform_password', 'ip'], 'string', 'max' => 255],
            [['api_key'], 'string', 'max' => 48],
            [['client_id'], 'exist', 'skipOnError' => true, 'targetClass' => Client::className(), 'targetAttribute' => ['client_id' => 'id']],
            [['client_project_id'], 'exist', 'skipOnError' => true, 'targetClass' => ClientProject::className(), 'targetAttribute' => ['client_project_id' => 'id']],
            ['status', 'default', 'value' => self::STATUS_ACTIVE],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_INACTIVE]],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'client_id' => 'Client ID',
            'client_project_id' => 'Client Project ID',
            'name' => 'Название',
            'description' => 'Комментарий',
            'ad_platform_name' => 'Площадка',
            'adPlatformNameLabel' => 'Площадка',
            'ad_platform_login' => 'Логин площадки',
            'ad_platform_password' => 'Пароль площадки (токен/идентификатор клиента)',
            'api_key' => 'Api Key',
            'created_at' => 'Created At',
            'createdAtLabel' => 'Дата добавления',
            'updated_at' => 'Updated At',
            'deleted_at' => 'Deleted At',
            'status' => 'Статус',
            'statusLabel' => 'Статус',
            'ip' => 'IP адреса через запятую, если доступ ограничен. Оставьте пустым, если ограничение по IP не нужно',
            'ipLabel' => 'Ограничения по IP',
            'req_daily_limit' => 'Максимальное количество запросов в день',
            'req_done_today' => 'Текущее количество запросов за день',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getClient()
    {
        return $this->hasOne(Client::className(), ['id' => 'client_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getClientProject()
    {
        return $this->hasOne(ClientProject::className(), ['id' => 'client_project_id']);
    }

    public function getAdPlatformNameLabel()
    {
        switch ($this->ad_platform_name) {
            case self::PLATFORM_YANDEX:
                return 'Яндекс.Директ';
            case self::PLATFORM_GOOGLE:
                return 'Google.AdWords';
            case self::PLATFORM_VK:
                return 'VK.Targeting';
            case self::PLATFORM_FACEBOOK:
                return 'Facebook.Insights';
        }
    }

    public function getIpLabel()
    {
        return $this->ip;
    }

    /**
     * @return Coef|bool
     */
    public function getActualCoef()
    {
        $model = Coef::find()
            ->where(['client_project_account_id' => $this->id])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!$model) {
            $model = new Coef();
            $model->client_project_account_id = $this->id;
            $model->ak_fix = 0;
            $model->ak_bonus = 0;
            $model->nds = Coef::CURRENT_NDS;
            $model->tech = 0;
            $model->created_at = time();
            $model->save();
        }

        return $model;
    }

    public function getCoefDataProvider($params)
    {
        $searchModel = new CoefSearch();
        $params['client_project_account_id'] = $this->id;
        return $searchModel->search($params);
    }

    public function getLongName()
    {
        $clientName = $this->client->name;
        $projectName = $this->clientProject->name;
        $ownName = $this->name;

        return "{$clientName} / {$projectName} / {$ownName} ({$this->ad_platform_login})";
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCampaigns()
    {
        return $this->hasMany(Campaign::className(), ['client_project_account_id' => 'id']);
    }

}
