<?php


namespace console\components\crawlers\ss;


use Symfony\Component\DomCrawler\Crawler;

class BlockAllCarsCrawler
{
    const MARK_CONTAINER = 'div[align=right]';

    public function getMarkUrls($html)
    {
        $domCrawler = new Crawler($html);
        $urls = $domCrawler->filter(self::MARK_CONTAINER)->filter('a')->each(function ($a) {
            $urlPrefix = "https://www.ss.com";
            return $urlPrefix.$a->attr('href');
        });

        return collect($urls)->filter(function ($el) {
            return !in_array($el, $this->exclusions());
        })->all();
    }

    private function exclusions()
    {
        return [
            "https://www.ss.com/ru/transport/cars/exchange/",
            "https://www.ss.com/ru/transport/service-centers-and-checkup/",
            "https://www.ss.com/ru/transport/service-centers-and-checkup/transportation-and-evacuation/",
            "https://www.ss.com/ru/transport/transports-rent/",
            "https://www.ss.com/ru/transport/spare-parts/",
            "https://www.ss.com/ru/transport/other/trailers/",
            "https://www.ss.com/ru/transport/other/transport-for-invalids/",
            "https://www.ss.com/ru/transport/service-centers-and-checkup/tuning/",
            "https://www.ss.com/ru/transport/spare-parts/trunks-wheels/",
        ];
    }
}