<?php

/**
 * @link http://www.digitaldeals.cz/
 * @copyright Copyright (c) 2014 Digital Deals s.r.o. 
 * @license http://www.digitaldeals/license/
 */

namespace dlds\sortable\components;

/**
 * Action class for Sortable module
 */
class Action extends yii\base\Action {

    public $model;

    public function run()
    {
        if ($this->model === null)
        {
            throw new CException('Model class must be specified.');
        }

        $this->model->setSortOrder();
    }

}
