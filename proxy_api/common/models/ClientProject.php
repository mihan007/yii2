<?php

namespace common\models;

use admin\behaviors\CommonAttributeBehavior;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\data\ActiveDataProvider;

/**
 * This is the model class for table "client_project".
 *
 * @property int $id
 * @property int $client_id
 * @property string $name
 * @property string $description
 * @property int $created_at
 * @property int $updated_at
 * @property int $deleted_at
 * @property int $status
 *
 * @property Client $client
 * @property ActiveDataProvider $accountDataProvider
 */
class ClientProject extends \yii\db\ActiveRecord
{
    const STATUS_INACTIVE = 0;
    const STATUS_ACTIVE = 1;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'client_project';
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
            [['client_id', 'created_at', 'updated_at', 'deleted_at', 'status'], 'integer'],
            [['description'], 'string'],
            [['name'], 'string', 'max' => 255],
            [['client_id'], 'exist', 'skipOnError' => true, 'targetClass' => Client::className(), 'targetAttribute' => ['client_id' => 'id']],
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
            'name' => 'Название',
            'description' => 'Комментарий',
            'created_at' => 'Дата добавления',
            'createdAtLabel' => 'Дата добавления',
            'updated_at' => 'Updated At',
            'deleted_at' => 'Deleted At',
            'status' => 'Статус',
            'statusLabel' => 'Статус',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getClient()
    {
        return $this->hasOne(Client::className(), ['id' => 'client_id']);
    }

    public function getAccountDataProvider()
    {
        $searchModel = new ClientProjectAccountSearch();
        $params['ClientProjectAccountSearch'] = [
            'client_id' => $this->client->id,
            'client_project_id' => $this->id,
            'deleted_at' => 0,
            'status_id' => ClientProjectAccount::STATUS_ACTIVE
        ];
        $dataProvider = $searchModel->search($params);

        return $dataProvider;
    }
}
