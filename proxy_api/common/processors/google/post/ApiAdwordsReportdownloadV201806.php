<?php

namespace common\processors\google\post;

use \common\processors\CommonProcessor;
use yii\web\HttpException;

class ApiAdwordsReportdownloadV201806 extends CommonProcessor
{
    private $fields = [];
    private $format;
    private $normalizedMoneyFields;
    private $normalizedBlockFields;

    private function parseBody()
    {
        parse_str($this->request->request_body, $body);
        if (isset($body['__fmt'])) {
            $this->format = strtolower($body['__fmt']);
        }
        if (isset($body['__rdquery'])) {
            $parts = explode(" ", $body['__rdquery']);
            $startIndex = false;
            $endIndex = false;
            foreach ($parts as $i => $part) {
                if (strtolower($part) == 'select') {
                    $startIndex = $i + 1;
                } elseif (strtolower($part) == 'from') {
                    $endIndex = $i - 1;
                    break;
                }
            }
            $fieldsArr = array_slice($parts, $startIndex, $endIndex - $startIndex + 1);
            foreach ($fieldsArr as $fieldName) {
                $this->fields[] = strtolower(trim($fieldName, ", "));
            }
        }
    }

    /**
     * @return boolean
     */
    public function ifRequestValid()
    {
        $this->parseBody();
        $isCorrectFormat = $this->isFormatSupported();
        $isMoneyFieldsExists = $this->isMoneyFieldsExists();
        $isAllRequiredFieldsExists = ($isMoneyFieldsExists && in_array('date', $this->fields)) || true;

        return $isCorrectFormat && $isAllRequiredFieldsExists;
    }

    private function isMoneyFieldsExists()
    {
        foreach ($this->fields as $field) {
            if (in_array($field, $this->getMoneyFields())) {
                return true;
            }
        }

        return false;
    }

    private function isFormatSupported()
    {
        $supported = [
            'tsv'
        ];
        return in_array($this->format, $supported);
    }

    function process()
    {
        $originalContent = $this->request->getOriginalFileContent();
        $hasReportHeader = $this->detectIfHasColumnHeader();
        $hasColumnHeader = $this->detectIfHasReportHeader();
        $hasReportSummary = $this->detectIfHasSummary();
        $modifiedContent = $this->applyCoefToMoneyFields($originalContent, $hasReportHeader, $hasColumnHeader, $hasReportSummary);

        return $modifiedContent;
    }

    private function applyCoefToMoneyFields($originalContent, $hasReportHeader, $hasColumnHeader, $hasReportSummary)
    {
        $moneyFields = $this->getMoneyFieldsIndex();
        $rows = explode(PHP_EOL, $originalContent);
        $result = [];
        $coefs = [];
        $rowsCount = sizeof($rows);
        $summaryProcessed = false;
        $summary = [];
        if ($hasReportSummary) {
            $minus = 2;
        } else {
            $minus = 1;
        }
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
                if ($hasReportSummary && !$summaryProcessed) {
                    $result[] = $this->processSummary($row, $summary);
                    $summaryProcessed = true;
                } else {
                    $result[] = $row;
                }
                continue;
            }
            $columns = explode("\t", $row);
            $newColumns = [];
            foreach ($columns as $k => $val) {
                if ($this->isFieldToBlock($this->fields[$k])) {
                    $newColumns[] = 0;
                } else {
                    $newColumns[] = $val;
                }
            }
            $reportDateIndex = array_search('date', $this->fields);
            if ($reportDateIndex !== false) {
                $reportDate = $columns[$reportDateIndex];
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
                foreach ($moneyFields as $fieldName => $ind) {
                    if (isset($columns[$ind]) && is_numeric($columns[$ind])) {
                        $newColumns[$ind] = intval($coef * $columns[$ind]);
                        if (!isset($summary[$ind])) {
                            $summary[$ind] = 0;
                        }
                        $summary[$ind] += $newColumns[$ind];
                    }
                }
            }
            $result[] = implode("\t", $newColumns);
        }

        return implode(PHP_EOL, $result);
    }

    private function isFieldToBlock($fieldName)
    {
        if (!$this->normalizedBlockFields) {
            $block = [
                "AdGroupDesktopBidModifier",
                "AdGroupMobileBidModifier",
                "AdGroupTabletBidModifier",
                "AllConversionValue",
                "AttributeValues",
                "BiddingStrategyId",
                "BiddingStrategyName",
                "BiddingStrategySource",
                "BiddingStrategyType",
                "BidModifier",
                "CampaignDesktopBidModifier",
                "CampaignMobileBidModifier",
                "CampaignTabletBidModifier",
                "ClickAssistedConversionValue",
                "ContentBidCriterionTypeGroup",
                "ConversionValue",
                "CurrentModelAttributedConversionValue",
                "ImpressionAssistedConversionValue",
                "IsBidOnPath",
                "MaximizeConversionValueTargetRoas",
                "PageOnePromotedBidCeiling",
                "PageOnePromotedBidChangesForRaisesOnly",
                "PageOnePromotedBidModifier",
                "PageOnePromotedRaiseBidWhenBudgetConstrained",
                "PageOnePromotedRaiseBidWhenLowQualityScore",
                "TargetCpaBidSource",
                "TargetCpaMaxCpcBidCeiling",
                "TargetCpaMaxCpcBidFloor",
                "TargetOutrankShareBidChangesForRaisesOnly",
                "TargetOutrankShareMaxCpcBidCeiling",
                "TargetOutrankShareRaiseBidWhenLowQualityScore",
                "TargetRoasBidCeiling",
                "TargetRoasBidFloor",
                "TargetSpendBidCeiling",
                "ValuePerAllConversion",
                "ValuePerConversion",
                "ValuePerCurrentModelAttributedConversion"
            ];
            $this->normalizedBlockFields = array_map('strtolower', $block);
        }
        $normalizedField = strtolower($fieldName);
        return in_array($normalizedField, $this->normalizedBlockFields);
    }

    private function getMoneyFieldsIndex()
    {
        $moneyFields = $this->getMoneyFields();
        $result = [];
        foreach ($this->fields as $i => $fieldName) {
            if (in_array($fieldName, $moneyFields)) {
                $result[] = $i;
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    private function getMoneyFields()
    {
        if (!$this->normalizedMoneyFields) {
            $moneyFields = [
                "ActiveViewCpm",
                "ActiveViewMeasurableCost",
                "AverageCost",
                "AverageCpc",
                "AverageCpc",
                "AverageCpe",
                "AverageCpm",
                "AverageCpv",
                "BenchmarkAverageMaxCpc",
                "Cost",
                "CostPerAllConversion",
                "CostPerConversion",
                "CostPerCurrentModelAttributedConversion",
                "CpcBid",
                "CpcBid",
                "CpcBidSource",
                "CpmBid",
                "CpmBidSource",
                "CpvBid",
                "CpvBidSource",
                "EstimatedAddCostAtFirstPositionCpc",
                "FirstPageCpc",
                "FirstPositionCpc",
                "RecommendedBudgetEstimatedChangeInWeeklyCost",
                "TopOfPageCpc"
            ];
            $this->normalizedMoneyFields = array_map('strtolower', $moneyFields);
        }

        return $this->normalizedMoneyFields;
    }
}