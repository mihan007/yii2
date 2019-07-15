<?php

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "ak_campaign_mapping".
 *
 * @property int $id
 * @property string $our_campaign_name
 * @property string $yandex_client_project_account_id
 * @property string $google_client_project_account_id
 * @property string $vk_client_project_account_id
 * @property string $fb_client_project_account_id
 * @property int $created_at
 * @property int $updated_at
 *
 * @property AkCoef[] $akCoefs
 */
class AkCampaignMapping extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'ak_campaign_mapping';
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::className()
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['created_at', 'updated_at'], 'integer'],
            [['our_campaign_name', 'yandex_campaign_name', 'google_campaign_name', 'vk_campaign_name', 'fb_campaign_name'], 'string', 'max' => 255]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'our_campaign_name' => 'Our Campaign Name',
            'yandex_campaign_name' => 'Yandex Campaign Name',
            'google_campaign_name' => 'Google Campaign Name',
            'vk_campaign_name' => 'Vk Campaign Name',
            'fb_campaign_name' => 'Fb Campaign Name',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAkCoefs()
    {
        return $this->hasMany(AkCoef::className(), ['ak_campaign_mapping_id' => 'id']);
    }

    public function getYandexLongName()
    {
        if ($this->yandex_client_project_account_id) {
            $clientProjectAccount = ClientProjectAccount::findOne($this->yandex_client_project_account_id);
            return $clientProjectAccount->getLongName();
        }
    }

    public function getGoogleLongName()
    {
        if ($this->google_client_project_account_id) {
            $clientProjectAccount = ClientProjectAccount::findOne($this->google_client_project_account_id);
            return $clientProjectAccount->getLongName();
        }
    }

    public function getVkLongName()
    {
        if ($this->vk_client_project_account_id) {
            $clientProjectAccount = ClientProjectAccount::findOne($this->vk_client_project_account_id);
            return $clientProjectAccount->getLongName();
        }
    }

    public function getFbLongName()
    {
        if ($this->fb_client_project_account_id) {
            $clientProjectAccount = ClientProjectAccount::findOne($this->fb_client_project_account_id);
            return $clientProjectAccount->getLongName();
        }
    }
}
