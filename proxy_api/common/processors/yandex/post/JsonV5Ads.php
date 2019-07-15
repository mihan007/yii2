<?php

namespace common\processors\yandex\post;
use common\processors\SimpleProcessor;

class JsonV5Ads extends SimpleProcessor
{
    public function ifRequestValid()
    {
        $request = json_decode($this->request->request_body);

        return $request->method == 'get';
    }
}