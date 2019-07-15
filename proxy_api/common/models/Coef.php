<?php

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "coef".
 *
 * @property int $id
 * @property int $client_project_account_id
 * @property int $ak_fix
 * @property int $ak_bonus
 * @property int $nds
 * @property int $tech
 * @property int $created_at
 * @property int $updated_at
 *
 * @property ClientProjectAccount $clientProjectAccount
 */
class Coef extends \yii\db\ActiveRecord
{
    const CURRENT_NDS = 2000;
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'coef';
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
            [['client_project_account_id', 'ak_fix', 'ak_bonus', 'nds', 'tech', 'created_at', 'updated_at'], 'integer'],
            [['client_project_account_id'], 'required'],
            [['client_project_account_id'], 'exist', 'skipOnError' => true, 'targetClass' => ClientProjectAccount::className(), 'targetAttribute' => ['client_project_account_id' => 'id']],
            [['akFixInput', 'akBonusInput', 'ndsInput', 'techInput'], 'required', 'on' => 'add']
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
            'ak_fix' => 'Ak Fix',
            'akFix' => 'АК, фикс',
            'akFixInput' => 'АК, фикс',
            'akBonus' => 'АК, бонус',
            'akBonusInput' => 'АК, бонус',
            'ndsReadable' => 'НДС',
            'ndsInput' => 'НДС',
            'techReadable' => 'Тех. коэф',
            'techInput' => 'Тех. коэф',
            'created_at' => 'Created At',
            'createdAtLabel' => 'Дата установки',
            'commonReadable' => 'Итоговый коэффициет',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getClientProjectAccount()
    {
        return $this->hasOne(ClientProjectAccount::className(), ['id' => 'client_project_account_id']);
    }

    public function getCreatedAtLabel()
    {
        return \Yii::$app->formatter->format($this->created_at, 'datetime');
    }

    public function getAkFix()
    {
        return number_format($this->ak_fix / 100, 2) . "%";
    }

    public function getAkBonus()
    {
        return number_format($this->ak_bonus / 100, 2) . "%";
    }

    public function getNdsReadable()
    {
        return number_format($this->nds / 100, 2) . "%";
    }

    public function getTechReadable()
    {
        return number_format($this->tech / 100, 2) . "%";
    }

    public function getCommon($useNds = true)
    {
        $result = (1 + $this->ak_fix/10000 + $this->ak_bonus/10000) * (1 + $this->tech/10000);
        if ($useNds) {
            $result = $result * (1 + $this->nds/10000);
        }
        return $result;
    }

    public function getCommonReadable()
    {
        return number_format($this->getCommon() * 100, 2) . "%";
    }

    public function getAkFixInput()
    {
        return number_format($this->ak_fix / 100, 2);
    }

    public function setAkFixInput($val)
    {
        $this->ak_fix = intval($val * 100);
    }

    public function getAkBonusInput()
    {
        return number_format($this->ak_fix / 100, 2);
    }

    public function setAkBonusInput($val)
    {
        $this->ak_bonus = intval($val * 100);
    }

    public function getNdsInput()
    {
        return number_format(Coef::CURRENT_NDS / 100, 2);
    }

    public function setNdsInput($val)
    {
        $this->nds = intval($val*100);
    }

    public function getTechInput()
    {
        return number_format($this->tech / 100, 2);
    }

    public function setTechInput($val)
    {
        $this->tech = intval($val*100);
    }
}