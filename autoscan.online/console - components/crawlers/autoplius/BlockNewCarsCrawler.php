<?php


namespace console\components\crawlers\autoplius;

use console\components\Url;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class BlockNewCarsCrawler
{
    const INITIAL_URL = 'https://ru.autoplius.lt/';

    /**
     * @var static
     */
    private $baseUrl;

    public function __construct()
    {
        $this->baseUrl = Url::create(self::INITIAL_URL);
    }

    public function extactCarAdLinksFromHtml($html)
    {
        $currentPageUrls = $this->getAllLinks($html)
            ->map(function (Url $url) {
                return $this->normalizeUrl($url);
            })
            ->filter(function (Url $url) {
                return CarCrawler::isCarUrl($url);
            });
        return $currentPageUrls;
    }

    private function getAllLinks($html)
    {
        $domCrawler = new DomCrawler($html);

        return collect($domCrawler->filterXpath('//a')
            ->extract(['href']))
            ->map(function ($url) {
                return Url::create($url);
            });
    }

    private function normalizeUrl(Url $url)
    {
        if ($url->isRelative()) {
            $url->setScheme($this->baseUrl->scheme)
                ->setHost($this->baseUrl->host);
        }
        if ($url->isProtocolIndependent()) {
            $url->setScheme($this->baseUrl->scheme);
        }
        return $url->removeFragment();
    }
}