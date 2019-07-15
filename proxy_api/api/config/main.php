<?php
$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

return [
    'id' => 'proxy_api-api',
    'language' => 'ru-RU',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'api\controllers',
    'components' => [
        'user' => [
            'enableAutoLogin' => false,
            'enableSession' => false,
            'loginUrl' => null,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'yandexRequester' => [
            'class' => 'api\components\YandexRequester'
        ],
        'googleRequester' => [
            'class' => 'api\components\GoogleRequester'
        ],
        'vkRequester' => [
            'class' => 'api\components\VkRequester'
        ],
        'facebookRequester' => [
            'class' => 'api\components\FacebookRequester'
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
    ],
    'catchAll' => ['v1/process/request'],
    'modules' => [
        'v1' => [
            'class' => '\api\modules\v1\Module',
        ],
    ],
    'params' => $params,
];
