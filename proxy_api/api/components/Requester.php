<?php

namespace api\components;

use common\models\LogRequest;
use yii\web\HttpException;

abstract class Requester extends \yii\base\Component
{
    const REQUEST_TIMEOUT = 600;

    /**
     * @var LogRequest
     */
    public $logRequest;
    public $request;
    public $login;
    public $password;

    private $isPrepared = false;

    protected function saveOriginalResponseBodyToFile($responseBody, $responseHeaders)
    {
        $ext = $this->detectExtensionByHeaders($responseHeaders);
        $filename = $this->getOriginalResponseFileName($ext);
        return $this->saveResponse($responseBody, $filename);
    }

    protected function saveModifiedResponseBodyToFile($responseBody, $responseHeaders)
    {
        $ext = $this->detectExtensionByHeaders($responseHeaders);
        $filename = $this->getModifiedResponseFileName($ext);
        return $this->saveResponse($responseBody, $filename);
    }

    protected function prepareHeaders($headers)
    {
        $result = [];
        foreach ($headers as $k => $v) {
            $result[] = "$k: $v";
        }

        return $result;
    }

    protected function getHeadersFromCurlResponse($response)
    {
        $headers = [];
        $delimiter = "\r\n\r\n";

        // check if the 100 Continue header exists
        while ( preg_match('#^HTTP/[0-9\\.]+\s+100\s+Continue#i', $response) ) {
            $tmp = explode($delimiter, $response,2); // grab the 100 Continue header
            $response = $tmp[1]; // update the response, purging the most recent 100 Continue header
        } // repeat

        $header_text = substr($response, 0, strpos($response, $delimiter));

        foreach (explode("\r\n", $header_text) as $i => $line) {
            if ($i === 0) {
                //$headers['http_code'] = $line;
            } else {
                list ($key, $value) = explode(': ', $line);
                $headers[$key] = $value;
            }
        }

        return $headers;
    }

    abstract protected function getStartOfUrl();

    protected function buildUrl()
    {
        $url = $this->getStartOfUrl() . $this->logRequest->url;
        $query = '';
        $url_params = (array)json_decode($this->logRequest->url_params);
        if (sizeof($url_params) > 0) {
            $query = '?' . http_build_query($url_params);
        }

        return $url . $query;
    }

    protected function cleanHeaders()
    {
        $headers = (array)json_decode($this->logRequest->headers);
        unset($headers['authorization']);
        unset($headers['platform']);
        unset($headers['connection']);
        unset($headers['connect-time']);
        unset($headers['content-length']);

        return $headers;
    }

    /**
     * @param $ext
     * @return string
     */
    protected function getOriginalResponseFileName($ext)
    {
        return $this->logRequest->id . '_original.' . $ext;
    }

    /**
     * @param $ext
     * @return string
     */
    protected function getModifiedResponseFileName($ext)
    {
        return $this->logRequest->id . '_modified.' . $ext;
    }


    /**
     * @param $responseHeaders
     * @return string
     */
    protected function detectExtensionByHeaders($responseHeaders)
    {
        $ext = 'txt';
        foreach ($responseHeaders as $headerName => $headerValue) {
            $normalizedHeaderName = strtolower($headerName);
            $normalizedHeaderValue = strtolower($headerValue);
            if (($normalizedHeaderName == 'content-encoding') && ($normalizedHeaderValue == 'gzip')) {
                $ext = 'txt.gz';
            }
        }
        return $ext;
    }

    /**
     * @param $responseBody
     * @param $filename
     * @return string
     */
    protected function saveResponse($responseBody, $filename)
    {
        $path = \Yii::getAlias('@runtime/response') . "/" . date("Y/m/d");
        if (!is_dir($path)) {
            $pathCreated = mkdir($path, 0755, true);
        } else {
            $pathCreated = true;
        }
        if ($pathCreated) {
            file_put_contents($path . "/" . $filename, $responseBody);
            return $path . "/" . $filename;
        }

        return 'Error while saving file. File size was ' . sizeof($responseBody);
    }
}