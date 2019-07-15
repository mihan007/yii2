<?php


namespace console\components\crawlers\auto24;

use common\exceptions\NoContentException;
use common\helpers\Logger;
use common\models\CarAd;
use common\models\CarPrice;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use jumper423\decaptcha\core\DeCaptchaErrors;
use jumper423\decaptcha\services\AnticaptchaReCaptchaProxeless;
use jumper423\decaptcha\services\RuCaptchaReCaptcha;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Yii;

class HiddenFieldsCrawler
{
    const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116';

    private $httpClient;
    private $baseUri = 'https://rus.auto24.ee/';

    public function __construct()
    {
        $this->httpClient = new Client([
            // Base URI is used with relative requests
            'base_uri' => $this->baseUri,
            // You can set any number of default request options.
            'timeout' => 30.0,
            'cookies' => true,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
            ],
        ]);
    }

    /**
     * @param string $url
     * @param string $key
     * @param string $type
     * @return mixed
     *
     * @throws GuzzleException
     */
    private function getHiddenFieldData(string $url, string $key, string $type)
    {
        $path = 'services/data_json.php?q=uv_' . $type . '&k=' . $key;
        $response = $this->httpClient->request('GET', $path);
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);
        $value = $data['q']['response']['value'];
        if (strpos($value, 'script') === false) {
            return $value;
        } else {
            $html = new DomCrawler($value);
            $ikey = $html->filter('.cptch-wrapper input')->first()->attr('value');
            $gCaptchaKey = $html->filter('.cptch-wrapper .germ')->first()->attr('data-sk');
            try {
                $captchaKey = $this->solveCaptcha($url, $gCaptchaKey);
            } catch (Exception $e) {
                $exceptionMessage = "Captcha not solved: " . $e->getMessage().PHP_EOL;
                $exceptionMessage .= $e->getTraceAsString();
                throw new Exception($exceptionMessage, $e->getCode(), $e);
            }
            $afterCaptchaUri = 'services/data_json.php?' .
                'q=uv_' . $type . '_sec' .
                '&k=g-recaptcha-response%3D' . $captchaKey .
                '%26ikey%3D' . $ikey;
            $response = $this->httpClient->request('GET', $afterCaptchaUri);
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);
            if (!$data || $data['q']['status'] === 0 || strpos($data['q']['response']['value'], 'script') !== false) {
                throw new Exception('Incorrect captcha or captcha solved too late');
            }
            $value = $data['q']['response']['value'];
            return $value;
        }
    }

    /**
     * @param string $url
     * @param string $key
     * @return string
     * @throws Exception
     */
    private function solveCaptcha($url, $key)
    {
        $ruCaptchaKey = Yii::$app->params['rucaptcha.secretKey'];
        $antiCaptchaKey = Yii::$app->params['anticaptcha.secretKey'];

        $time = new DateTime();
        while ($time->diff(new DateTime())->s < Yii::$app->params['auto24.attemptsTimeLimit']) {
            //
            Logger::log("Try to solve captcha for '$url' with RuCaptcha...");
            $ruCaptcha = new RuCaptchaReCaptcha([
                RuCaptchaReCaptcha::ACTION_FIELD_KEY => $ruCaptchaKey,
            ]);
            $ruCaptcha->setCauseAnError(true);
            try {
                $ruCaptcha->recognize([
                    RuCaptchaReCaptcha::ACTION_FIELD_GOOGLEKEY => $key,
                    RuCaptchaReCaptcha::ACTION_FIELD_PAGEURL => $url,
                ]);
                Logger::log("Captcha for '$url' solved by RuCaptcha in " . $time->diff(new DateTime())->s . ' sec');
                return $ruCaptcha->getCode();
            } catch (DeCaptchaErrors $e) {
                Logger::logError("Error to solve auto24 captcha with RuCaptcha: " . $e->getMessage(), ['url' => $url]);
                $ruCaptcha->notTrue();
                if ($e->getCode() === DeCaptchaErrors::ERROR_LIMIT) {
                    break;
                }

                Logger::log('Try to solve captcha with AntiCaptcha...');
                $antiCaptcha = new AnticaptchaReCaptchaProxeless([
                    AnticaptchaReCaptchaProxeless::ACTION_FIELD_KEY => $antiCaptchaKey,
                ]);
                $antiCaptcha->setCauseAnError(true);
                try {
                    $antiCaptcha->recognize([
                        AnticaptchaReCaptchaProxeless::ACTION_FIELD_GOOGLEKEY => $key,
                        AnticaptchaReCaptchaProxeless::ACTION_FIELD_PAGEURL => $url,
                    ]);
                    Logger::log("Captcha for '$url' solved by AntiCaptcha in " . $time->diff(new DateTime())->s . ' sec');
                    return $antiCaptcha->getCode();
                } catch (DeCaptchaErrors $e) {
                    Logger::logError("Error to solve auto24 captcha with AntiCaptcha: " . $e->getMessage(), ['url' => $url]);
                    continue;
                }
            }

        }
        throw new Exception('Exceeded time limit, exit');
    }

    /**
     * Парсит скрытые поля auto24 по ID
     *
     * @param string $url
     * @return array
     * @throws GuzzleException
     */
    public function getHiddenDataByUrl(string $url)
    {
        $parts = explode("/", $url);
        $id = end($parts);
        $uri = 'used/' . $id;
        $result = [];
        try {
            $response = $this->httpClient->get($uri);
            $contents = $response->getBody()->getContents();
            $dom = new DomCrawler($contents);
            if ($dom->filter('h1')->count() === 0) {
                throw new NoContentException('No content on page');
            }
        } catch (ClientException | NoContentException $e) {
            Logger::logError("Something goes wrong, probably ad removed: {$e->getMessage()}");
            $carAd = CarAd::findOne(['source_url' => $url]);
            if ($carAd) {
                $carPrice = CarPrice::find()
                    ->where([
                        'car_ad_id' => $carAd->id
                    ])->orderBy(['updated_at' => SORT_DESC])
                    ->one();
                $carAd->addRemovedStatusRecord($carPrice);
            }
            return [];
        }
        $fields = [
            'phone' => ['selector' => '.pnr', 'id' => 'telnr'],
            'reg_num' => ['selector' => '.field-reg_nr .service-trigger', 'id' => 'regnr'],
            'vin' => ['selector' => '.field-tehasetahis .service-trigger', 'id' => 'vin'],
        ];

        foreach ($fields as $field => $settings) {
            $elements = $dom->filter($settings['selector']);
            if ($elements->count() > 0) {
                $key = $elements->first()->attr('data-key');
                $result[$field] = $this->getHiddenFieldData($url, $key, $settings['id']);
            }
        }
        if (isset($result['phone'])) {
            $result['phone'] = str_replace('/', ',', $result['phone']);
            $result['phone'] = str_replace('<br>', '', $result['phone']);
            $result['phone'] = preg_replace('|\s|', '', $result['phone']);
            $result['phone'] = explode(',', $result['phone']);
        }
        return $result;
    }
}
