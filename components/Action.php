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
class Action extends \yii\base\Action {

    public $modelClass;

    public function run()
    {
        if (!$this->modelClass)
        {
            throw new CException('Model class must be specified.');
        }

        /** @var \yii\db\ActiveRecord $model */
        $model = new $this->modelClass;

        if (!$model->hasMethod('setSortOrder', true))
        {
            throw new InvalidConfigException("Sortable behavior is not attached to model.");
        }

        $model->setSortOrder();
    }

}
