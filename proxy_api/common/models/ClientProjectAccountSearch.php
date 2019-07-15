<?php

namespace common\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use common\models\ClientProjectAccount;

/**
 * ClientProjectAccountSearch represents the model behind the search form of `common\models\ClientProjectAccount`.
 */
class ClientProjectAccountSearch extends ClientProjectAccount
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'client_id', 'client_project_id', 'created_at', 'updated_at', 'deleted_at', 'status'], 'integer'],
            [['name', 'description', 'ad_platform_name', 'ad_platform_login', 'ad_platform_password', 'api_key'], 'safe'],
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
        $query = ClientProjectAccount::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client_project_id' => $this->client_project_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'status' => $this->status,
        ]);

        $query->andFilterWhere(['like', 'name', $this->name])
            ->andFilterWhere(['like', 'description', $this->description])
            ->andFilterWhere(['like', 'ad_platform_name', $this->ad_platform_name])
            ->andFilterWhere(['like', 'ad_platform_login', $this->ad_platform_login])
            ->andFilterWhere(['like', 'ad_platform_password', $this->ad_platform_password])
            ->andFilterWhere(['like', 'api_key', $this->api_key]);

        return $dataProvider;
    }
}
