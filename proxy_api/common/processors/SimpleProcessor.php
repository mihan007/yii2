<?php


namespace common\processors;


class SimpleProcessor extends CommonProcessor
{

    /**
     * @return boolean
     */
    public function ifRequestValid()
    {
        return true;
    }

    function process()
    {
        return $this->request->getOriginalFileContent();
    }
}