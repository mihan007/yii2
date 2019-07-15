<?php

namespace console\components;

use common\helpers\StringHelper;

class Url
{
    /**
     * @var null|string
     */
    public $scheme;

    /**
     * @var null|string
     */
    public $host;

    /**
     * @var null|string
     */
    public $path;

    /**
     * @param $url
     *
     * @return static
     */
    public static function create($url)
    {
        return new static($url);
    }

    /**
     * Url constructor.
     *
     * @param $url
     */
    public function __construct($url)
    {
        $urlProperties = parse_url($url);

        foreach (['scheme', 'host', 'path'] as $property) {
            if (isset($urlProperties[$property])) {
                $this->$property = strtolower($urlProperties[$property]);
            }
        }
    }

    public static function proxyUrl()
    {
        return \Yii::$app->params['proxy']['protocol'] . "://"
            . \Yii::$app->params['proxy']['username'] . ':'
            . \Yii::$app->params['proxy']['password'] . '@'
            . \Yii::$app->params['proxy']['uri'] . ':'
            . \Yii::$app->params['proxy']['port'];
    }

    /**
     * Determine if the url is relative.
     *
     * @return bool
     */
    public function isRelative()
    {
        return is_null($this->host);
    }

    /**
     * Determine if the url is protocol independent.
     *
     * @return bool
     */
    public function isProtocolIndependent()
    {
        return is_null($this->scheme);
    }

    /**
     * Determine if this is a mailto-link.
     *
     * @return bool
     */
    public function isEmailUrl()
    {
        return $this->scheme === 'mailto';
    }

    /**
     * Set the scheme.
     *
     * @param string $scheme
     *
     * @return $this
     */
    public function setScheme($scheme)
    {
        $this->scheme = $scheme;

        return $this;
    }

    /**
     * Set the host.
     *
     * @param string $host
     *
     * @return $this
     */
    public function setHost($host)
    {
        $this->host = $host;

        return $this;
    }

    /**
     * Remove the fragment.
     *
     * @return $this
     */
    public function removeFragment()
    {
        $this->path = explode('#', $this->path)[0];

        return $this;
    }

    /**
     * Convert the url to string.
     *
     * @return string
     */
    public function __toString()
    {
        $path = self::starts_with($this->path, '/') ? substr($this->path, 1) : $this->path;

        return "{$this->scheme}://{$this->host}/{$path}";
    }

    public static function starts_with($string, $query)
    {
        return substr($string, 0, strlen($query)) === $query;
    }

    public static function ends_with($string, $query)
    {
        return StringHelper::ends_with($string, $query);
    }
}