<?php
return [
    'timezone' => 'Europe/Moscow',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    'components' => [
        'geoip' => ['class' => 'lysenkobv\GeoIP\GeoIP'],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'user' => [
            'class' => '\yii\web\User',
            'identityClass' => 'common\models\User',
        ],
        'authManager' => [
            'class' => 'yii\rbac\DbManager',
        ],
    ],
];
