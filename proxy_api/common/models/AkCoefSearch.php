<?php

namespace common\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use common\models\Client;

/**
 * AkCoefSearch represents the model behind the search form of `common\models\AkCoef`.
 */
class AkCoefSearch extends AkCoef
{
    public $periodStart;
    public $periodEnd;
    public $campaignId;
    public $isActive;

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['periodStart', 'periodEnd'], 'string'],
            [['campaignId'], 'safe'],
            [['isActive'], 'boolean']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     *
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $query = AkCoef::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params, '');

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        if ($this->periodStart) {
            $periodStart = date('Y-m-d 00:00:00', strtotime($this->periodStart));
            $query->andWhere(['>=', 'start_time', $periodStart]);
        }

        if ($this->periodEnd) {
            $periodEnd = date('Y-m-d 23:59:59', strtotime($this->periodEnd));
            $query->andWhere(['<=', 'start_time', $periodEnd]);
        }

        if ($this->campaignId) {
            $query->andWhere(['=', 'ak_campaign_mapping_id', $this->campaignId]);
        }

        if ($this->isActive) {
            $query->andWhere(['=', 'is_active', $this->isActive]);
        }

        $query->orderBy(['start_time' => SORT_DESC, 'created_at' => SORT_DESC]);

        return $dataProvider;
    }
}
