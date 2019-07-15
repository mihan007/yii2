<?php

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use admin\behaviors\CommonAttributeBehavior;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "client".
 *
 * @property int $id
 * @property string $name
 * @property int $created_at
 * @property int $updated_at
 * @property int $deleted_at
 * @property int $status
 *
 * @property ActiveDataProvider $projectDataProvider
 * @property ActiveDataProvider $akCoefDataProvider
 *
 * @property  ClientProjectAccount[] projectAccounts
 */
class Client extends ActiveRecord
{
    const STATUS_INACTIVE = 0;
    const STATUS_ACTIVE = 1;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'client';
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
            ['status', 'default', 'value' => self::STATUS_ACTIVE],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_INACTIVE]],
            [['name'], 'string', 'max' => 255],
            ['description', 'safe']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'name' => 'Название',
            'created_at' => 'Дата добавления',
            'createdAtLabel' => 'Дата добавления',
            'updated_at' => 'Updated At',
            'deleted_at' => 'Deleted At',
            'description' => 'Комментарий',
            'status' => 'Статус',
            'statusLabel' => 'Статус',
        ];
    }

    public function getProjectAccounts()
    {
        return $this->hasMany(ClientProjectAccount::className(), ['client_id' => 'id']);
    }

    public function getProjectDataProvider()
    {
        $searchModel = new ClientProjectSearch();
        $params['ClientProjectSearch'] = [
            'client_id' => $this->id,
            'deleted_at' => 0,
            'status_id' => ClientProject::STATUS_ACTIVE
        ];
        $dataProvider = $searchModel->search($params);

        return $dataProvider;
    }
}
