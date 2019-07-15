<?php

namespace common\models;

use admin\behaviors\CommonAttributeBehavior;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "campaign".
 *
 * @property int $id
 * @property int $client_project_account_id
 * @property string $campaign_name
 * @property string $ad_platform_id
 * @property int $created_at
 * @property int $updated_at
 * @property int $deleted_at
 * @property int $status
 * @property string $currency
 * @property ClientProjectAccount $clientProjectAccount
 */
class Campaign extends \yii\db\ActiveRecord
{
    const STATUS_INACTIVE = 0;
    const STATUS_ACTIVE = 1;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'campaign';
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
            [['id', 'client_project_account_id', 'created_at', 'updated_at', 'deleted_at', 'status'], 'integer'],
            [['campaign_name','ad_platform_id','currency'], 'string']
        ];
    }


    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'client_project_account_id' => 'Client Project Account ID',
            'campaign_name' => 'Название кампании',
            'ad_platform_id' => 'Id кампании в рекламной площадке',
            'created_at' => 'Created At',
            'createdAtLabel' => 'Дата добавления',
            'updated_at' => 'Updated At',
            'deleted_at' => 'Deleted At',
            'status' => 'Статус',
            'statusLabel' => 'Статус',
            'currency' => 'Валюта'
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getClientProjectAccount()
    {
        return $this->hasOne(ClientProjectAccount::className(), ['id' => 'client_project_account_id']);
    }

}
