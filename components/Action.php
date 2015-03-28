<?php

/**
 * @link http://www.digitaldeals.cz/
 * @copyright Copyright (c) 2014 Digital Deals s.r.o. 
 * @license http://www.digitaldeals.cz/license/
 */

namespace dlds\sortable\components;

use yii\base\ErrorException;
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

        /* @var $model \yii\db\ActiveRecord */
        $model = new $this->modelClass;
        
        if (!$model->hasMethod('setSortOrder', true))
        {
            throw new ErrorException("Sortable behavior is not attached to model.");
        }

        $model->setSortOrder(\Yii::$app->request->getQueryParam('sort', SORT_DESC));
    }

}
