<?php

namespace api\modules\v1\controllers;

use Yii;
use yii\filters\ContentNegotiator;
use yii\filters\Cors;
use yii\web\Response;

class Controller extends \yii\rest\Controller
{
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                'corsFilter' => [
                    'class' => Cors::className(),
                    'cors' => [
                        'Origin' => ['*'],
                        'Access-Control-Request-Method'     => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
                        'Access-Control-Request-Headers'    => ['*'],
                        'Access-Control-Allow-Credentials'  => true
                    ],
                ],
                'contentNegotiator' => [
                    'class' => ContentNegotiator::className(),
                    'formats' => [
                        'application/json' => Response::FORMAT_JSON,
                        'application/xml' => Response::FORMAT_XML,
                        'application/soap+xml' => Response::FORMAT_XML,
                    ],
                ],
            ]
        );
    }

    public function beforeAction($action)
    {
        if (strtoupper(\Yii::$app->getRequest()->getMethod()) === 'OPTIONS') {
            Yii::$app->response->headers->set('Access-Control-Allow-Credentials', true);
            Yii::$app->response->headers->set('Access-Control-Allow-Headers', 'Authorization, Platform, Content-Type, Cache-Control, X-Requested-With');
            Yii::$app->response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');

            return false;
        }

        return parent::beforeAction($action);
    }
}
