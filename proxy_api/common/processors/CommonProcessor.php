<?php

namespace common\processors;

use common\models\AkCampaignMapping;
use common\models\AkCoef;
use common\models\ClientProjectAccount;
use common\models\Coef;
use common\models\LogRequest;
use yii\db\Expression;

abstract class CommonProcessor
{
    /**
     * @var LogRequest
     */
    public $request;

    private $nds = null;
    private $tech = null;

    /**
     * @return boolean
     */
    abstract public function ifRequestValid();

    abstract function process();

    protected function getCoef($reportDate, $clientProjectAccountId)
    {
        $this->getCommonCoef();

        $requiredDate = date('Y-m-d 00:00:00', strtotime($reportDate));
        $clientProjectAccount = ClientProjectAccount::findOne($clientProjectAccountId);
        if ($clientProjectAccount) {
            if ($clientProjectAccount->ad_platform_name === ClientProjectAccount::PLATFORM_YANDEX) {
                $akCampaignMapping = AkCampaignMapping::find()
                    ->where(['yandex_client_project_account_id' => $clientProjectAccountId])
                    ->limit(1)
                    ->one();
            } else if ($clientProjectAccount->ad_platform_name === ClientProjectAccount::PLATFORM_GOOGLE) {
                $akCampaignMapping = AkCampaignMapping::find()
                    ->where(['google_client_project_account_id' => $clientProjectAccountId])
                    ->limit(1)
                    ->one();
            } else if ($clientProjectAccount->ad_platform_name === ClientProjectAccount::PLATFORM_VK) {
                $akCampaignMapping = AkCampaignMapping::find()
                    ->where(['vk_client_project_account_id' => $clientProjectAccountId])
                    ->limit(1)
                    ->one();
            } else if ($clientProjectAccount->ad_platform_name === ClientProjectAccount::PLATFORM_FACEBOOK) {
                $akCampaignMapping = AkCampaignMapping::find()
                    ->where(['fb_client_project_account_id' => $clientProjectAccountId])
                    ->limit(1)
                    ->one();
            }

            if ($akCampaignMapping) {
                $akCoef = AkCoef::find()->where(['ak_campaign_mapping_id' => $akCampaignMapping->id])
                    ->andWhere(['<=', 'start_time', $requiredDate])
                    ->andWhere(['>=', 'end_time', $requiredDate])
                    ->orderBy(['created_at' => SORT_DESC])
                    ->limit(1)
                    ->one();

                if ($akCoef) {
                    if ($clientProjectAccount->ad_platform_name === ClientProjectAccount::PLATFORM_YANDEX) {
                        return $akCoef->getYandexCommon() * (1 + $this->nds / 10000) * (1 + $this->tech / 10000);
                    } else if ($clientProjectAccount->ad_platform_name === ClientProjectAccount::PLATFORM_GOOGLE) {
                        return $akCoef->getGoogleCommon() * (1 + $this->nds / 10000) * (1 + $this->tech / 10000);
                    } else if ($clientProjectAccount->ad_platform_name === ClientProjectAccount::PLATFORM_VK) {
                        return $akCoef->getVkCommon() * (1 + $this->tech / 10000);
                    } else if ($clientProjectAccount->ad_platform_name === ClientProjectAccount::PLATFORM_FACEBOOK) {
                        return $akCoef->getFbCommon() * (1 + $this->tech/10000) * (1 + $this->nds/10000);
                    }
                }
            }
        }

        return false;
    }

    private function getCommonCoef()
    {
        if (($this->nds === null) || ($this->tech === null)) {
            $defaultCoef = Coef::find()->
            where(['client_project_account_id' => $this->request->client_project_account_id])
                ->orderBy(['created_at' => SORT_DESC])
                ->one();
            if ($defaultCoef) {
                $this->nds = $defaultCoef->nds;
                $this->tech = $defaultCoef->tech;
            } else {
                $this->nds = Coef::CURRENT_NDS;
                $this->tech = 0;
            }
        }
    }

    protected function domNodesToArray(array $tags, \DOMXPath $xpath)
    {
        $tagNameToArr = [];
        foreach ($tags as $tag) {
            $tagData = [];
            $attrs = $tag->attributes ? iterator_to_array($tag->attributes) : [];
            $subTags = $tag->childNodes ? iterator_to_array($tag->childNodes) : [];
            foreach ($xpath->query('namespace::*', $tag) as $nsNode) {
                // the only way to get xmlns:*, see https://stackoverflow.com/a/2470433/2750743
                if ($tag->hasAttribute($nsNode->nodeName)) {
                    $attrs[] = $nsNode;
                }
            }

            foreach ($attrs as $attr) {
                $tagData[$attr->nodeName] = $attr->nodeValue;
            }
            if (count($subTags) === 1 && $subTags[0] instanceof \DOMText) {
                $text = $subTags[0]->nodeValue;
            } elseif (count($subTags) === 0) {
                $text = '';
            } else {
                // ignore whitespace (and any other text if any) between nodes
                $isNotDomText = function ($node) {
                    return !($node instanceof \DOMText);
                };
                $realNodes = array_filter($subTags, $isNotDomText);
                $subTagNameToArr = $this->domNodesToArray($realNodes, $xpath);
                $tagData = array_merge($tagData, $subTagNameToArr);
                $text = null;
            }
            if (!is_null($text)) {
                if ($attrs) {
                    if ($text) {
                        $tagData['_'] = $text;
                    }
                } else {
                    $tagData = $text;
                }
            }
            $keyName = $tag->nodeName;
            $tagNameToArr[$keyName][] = $tagData;
        }
        return $tagNameToArr;
    }

    protected function xmlToArr($xml)
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $xpath = new \DOMXPath($doc);
        $tags = $doc->childNodes ? iterator_to_array($doc->childNodes) : [];
        return $this->domNodesToArray($tags, $xpath);
    }

    protected function detectIfHasReportHeader()
    {
        $headers = (array)json_decode($this->request->headers);
        return isset($headers['skipcolumnheader']) && $headers['skipcolumnheader'] == "false";
    }

    protected function detectIfHasColumnHeader()
    {
        $headers = (array)json_decode($this->request->headers);
        return isset($headers['skipreportheader']) && $headers['skipreportheader'] == "false";
    }

    protected function detectIfHasSummary()
    {
        $headers = (array)json_decode($this->request->headers);
        return isset($headers['skipreportsummary']) && $headers['skipreportsummary'] == "false";
    }

    protected function processSummary($row, $summary)
    {
        $parts = explode("\t", $row);
        $result = [];
        foreach ($parts as $ind => $part) {
            $flag = false;
            foreach ($summary as $k => $val) {
                if ($ind == $k) {
                    $flag = true;
                    break;
                }
            }
            if ($flag) {
                $result[$ind] = $summary[$ind];
            } else {
                $result[$ind] = $part;
            }
        }

        return implode("\t", $result);
    }

    protected function getSpendWithAKRound($sum, $coef){
        return round($sum * $coef);
    }
}