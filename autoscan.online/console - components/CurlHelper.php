<?php


namespace console\components;

use common\helpers\Logger;

class CurlHelper
{
    const CACHE_EXPIRATION = 300;

    public static function crawlUrl($url, $useProxy = true, $useCache = true)
    {
        $cacheKey = "CurlHelper::crawlUrl-$url";
        if ($useCache && \Yii::$app->cache->exists($cacheKey)) {
            return \Yii::$app->cache->get($cacheKey);
        }
        $maxRepeat = 5;
        $counter = 0;
        do {
            if ($counter > $maxRepeat) {
                break;
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            if ($useProxy) {
                curl_setopt($ch, CURLOPT_PROXY, \Yii::$app->params['proxy']['CURLOPT_PROXY']);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, \Yii::$app->params['proxy']['CURLOPT_PROXYUSERPWD']);
            }
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, self::getHeaders());
            curl_setopt($ch, CURLOPT_USERAGENT, self::getUserAgent());
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $html = curl_exec($ch);
            curl_close($ch);
            $counter++;
        } while (is_bool($html));

        if (is_bool($html)) {
            \Yii::$app->cache->delete($cacheKey);
            throw new \Exception("Could not download html");
        }

        if ($useCache) {
            \Yii::$app->cache->set($cacheKey, $html, self::CACHE_EXPIRATION);
        }
        return $html;
    }

    public static function post($url, $headers = [], $params = [], $cookieFilePath = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFilePath);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFilePath);
        curl_setopt($ch, CURLOPT_PROXY, \Yii::$app->params['proxy']['CURLOPT_PROXY']);
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, \Yii::$app->params['proxy']['CURLOPT_PROXYUSERPWD']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if (sizeof($headers) == 0) {
            $headers = self::getHeaders();
        } else {
            $headers = self::convertArrayToKeyValue($headers);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, self::getUserAgent());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $serverOutput = curl_exec($ch);
        curl_close($ch);

        return $serverOutput;
    }

    private static function getHeaders()
    {
        return self::convertArrayToKeyValue(self::getHeadersAsArray());
    }

    private static function getHeadersAsArray()
    {
        return [
            'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng;',
            'accept-encoding' => 'deflate, br',
            'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'cache-control' => 'no-cache',
            'pragma' => 'no-cache',
        ];
    }

    /**
     * @return string
     */
    private static function getUserAgent(): string
    {
        return "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.86 Safari/537.36";
    }

    public static function getRedirectUrl($sourceUrl, $useProxy = true)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $sourceUrl);
        if ($useProxy) {
            curl_setopt($ch, CURLOPT_PROXY, \Yii::$app->params['proxy']['CURLOPT_PROXY']);
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, \Yii::$app->params['proxy']['CURLOPT_PROXYUSERPWD']);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::getHeaders());
        curl_setopt($ch, CURLOPT_USERAGENT, self::getUserAgent());

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        $locationHeader = self::getLocationHeaderFromCurlResponse($response, $sourceUrl);
        curl_close($ch);

        return $locationHeader;
    }

    public static function getHttpCodeOfUrl($url, $useProxy = true)
    {
        $handle = curl_init($url);
        if ($useProxy) {
            curl_setopt($handle, CURLOPT_PROXY, \Yii::$app->params['proxy']['CURLOPT_PROXY']);
            curl_setopt($handle, CURLOPT_PROXYUSERPWD, \Yii::$app->params['proxy']['CURLOPT_PROXYUSERPWD']);
        }
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_NOBODY, true);
        curl_exec($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return $httpCode;
    }

    protected static function getLocationHeaderFromCurlResponse($response, $sourceUrl)
    {
        $headers = [];
        $delimiter = "\r\n\r\n";

        // check if the 100 Continue header exists
        while (preg_match('#^HTTP/[0-9\\.]+\s+100\s+Continue#i', $response)) {
            $tmp = explode($delimiter, $response, 2); // grab the 100 Continue header
            if (isset($tmp[1])) {
                $response = $tmp[1]; // update the response, purging the most recent 100 Continue header
            } else {
                $response = null;
                Logger::logError('null after http continue header processed', ['url' => $sourceUrl]);
            }
        }

        $parts = explode($delimiter, $response);
        foreach ($parts as $header_text) {
            foreach (explode("\r\n", $header_text) as $i => $line) {
                if ($i === 0) {
                    continue;
                } else {
                    if (strpos($line, ': ') === false) {
                        continue;
                    }
                    list ($key, $value) = explode(': ', $line);
                    if (mb_strtolower($key) == 'location') {
                        return $value;
                    }
                }
            }
        }

        return false;
    }

    private static function convertArrayToKeyValue($array)
    {
        $result = [];
        foreach ($array as $key => $value) {
            $result[] = "$key: $value";
        }

        return $result;
    }
}