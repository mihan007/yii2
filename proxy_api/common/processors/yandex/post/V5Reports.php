<?php

namespace common\processors\yandex\post;

use common\processors\CommonProcessor;
use yii\web\HttpException;

class V5Reports extends CommonProcessor
{
    private $arrBody;
    private $dateColumnIndex = false;
    private $campaignIdColumnIndex = false;

    public function process()
    {
        $moneyFields = $this->grabIndexOfMoneyFieldsFromRequestBody();
        $originalContent = $this->request->getOriginalFileContent();
        $moneyInMicros = $this->detectIsMoneyInMicros();
        $hasReportHeader = $this->detectIfHasColumnHeader();
        $hasColumnHeader = $this->detectIfHasReportHeader();
        $hasReportSummary = $this->detectIfHasSummary();
        $modifiedContent = $this->applyCoefToMoneyFields($originalContent, $moneyFields, $moneyInMicros, $hasReportHeader, $hasColumnHeader, $hasReportSummary);

        return $modifiedContent;
    }

    public function ifRequestValid()
    {
        $this->arrBody = $this->xmlToArr($this->request->request_body);
        $ifDateAtReportPresented = strpos($this->request->request_body, '<FieldNames>Date</FieldNames>') !== false;
        $ifVatNotIncluded = (isset($this->arrBody['ReportDefinition'][0]['IncludeVAT'][0]) && (strtolower($this->arrBody['ReportDefinition'][0]['IncludeVAT'][0]) == 'no'));

        return $ifDateAtReportPresented && $ifVatNotIncluded;
    }

    private function detectIsMoneyInMicros()
    {
        $headers = (array)json_decode($this->request->headers);
        return isset($headers['returnmoneyinmicros']) && $headers['returnmoneyinmicros'] == "false";
    }

    private function grabIndexOfMoneyFieldsFromRequestBody()
    {
        $fieldNames = isset($this->arrBody['ReportDefinition'][0]['FieldNames']) ? $this->arrBody['ReportDefinition'][0]['FieldNames'] : false;
        $moneyFields = [
            'GoalsRoi',
            'AvgCpc',
            'AvgCpm',
            'Cost',
            'CostPerConversion',
            'Revenue'
        ];
        $result = [];
        if ($fieldNames) {
            foreach ($fieldNames as $ind => $fieldName) {
                if (in_array($fieldName, $moneyFields)) {
                    $result[$fieldName] = $ind;
                }
            }
        }

        return $result;
    }

    private function applyCoefToMoneyFields($originalContent, $moneyFields, $moneyInMicros, $hasReportHeader, $hasColumnHeader, $hasReportSummary)
    {
        $rows = explode(PHP_EOL, $originalContent);
        $result = [];
        $coefs = [];
        if ($hasReportSummary) {
            $minus = 2;
        } else {
            $minus = 1;
        }
        $rowsCount = sizeof($rows);
        foreach ($rows as $i => $row) {
            if ($i == 0) {
                if ($hasColumnHeader || $hasReportHeader) {
                    $result[] = $row;
                    continue;
                }
            } elseif ($i == 1) {
                if ($hasColumnHeader && $hasReportHeader) {
                    $result[] = $row;
                    continue;
                }
            } elseif ($i >= $rowsCount - $minus) {
                $result[] = $row;
                continue;
            }
            $columns = explode("\t", $row);
            $reportDate = $this->detectReportDate($columns);
            $reportCampaignId = $this->detectReportCampaignId($columns);
            if ($this->isIgnored($reportCampaignId)) {
                continue;
            }
            if (!$reportDate) {
                $coef = 1;
            } elseif (!isset($coefs[$reportDate])) {
                $coef = $this->getCoef($reportDate, $this->request->client_project_account_id);
                $coefs[$reportDate] = $coef;
            } else {
                $coef = $coefs[$reportDate];
            }
            if ($reportDate && !$coef) {
                throw new HttpException(400, 'Bad Request');
            }
            $newColumns = $columns;
            foreach ($moneyFields as $fieldName => $ind) {
                if ($this->isFieldToBlock($fieldName)) {
                    if ($moneyInMicros) {
                        $newColumns[$ind] = "0.00";
                    } else {
                        $newColumns[$ind] = "0";
                    }
                }
                if (isset($columns[$ind]) && is_numeric($columns[$ind])) {
                    if ($moneyInMicros) {
                        $newColumns[$ind] = number_format($coef * $columns[$ind], 2, '.', '');
                    } else {
                        $newColumns[$ind] = intval($coef * $columns[$ind]);
                    }
                }
            }
            $result[] = implode("\t", $newColumns);
        }

        return implode(PHP_EOL, $result);
    }

    private function isFieldToBlock($fieldName)
    {
        $block = [
            'GoalsRoi',
            'Revenue'
        ];
        $normalizedBlock = array_map('strtolower', $block);
        $normalizedField = strtolower($fieldName);
        return in_array($normalizedField, $normalizedBlock);
    }

    private function detectReportDate($columns)
    {
        if ($this->dateColumnIndex === false) {
            $fieldNames = isset($this->arrBody['ReportDefinition'][0]['FieldNames']) ? $this->arrBody['ReportDefinition'][0]['FieldNames'] : false;
            foreach ($fieldNames as $ind => $fieldName) {
                if ($fieldName == 'Date') {
                    $this->dateColumnIndex = $ind;
                    break;
                }
            }
        }

        if ($this->dateColumnIndex !== false) {
            return $columns[$this->dateColumnIndex];
        }

        return false;
    }

    private function detectReportCampaignId($columns)
    {
        if ($this->campaignIdColumnIndex === false) {
            $fieldNames = isset($this->arrBody['ReportDefinition'][0]['FieldNames']) ? $this->arrBody['ReportDefinition'][0]['FieldNames'] : false;
            foreach ($fieldNames as $ind => $fieldName) {
                if ($fieldName == 'CampaignId') {
                    $this->campaignIdColumnIndex = $ind;
                    break;
                }
            }
        }

        if ($this->campaignIdColumnIndex !== false) {
            return $columns[$this->campaignIdColumnIndex];
        }

        return false;
    }

    private function isIgnored($reportCampaignId)
    {
        return in_array($reportCampaignId, \Yii::$app->params['ignoredYandexCampaignIds']);
    }
}