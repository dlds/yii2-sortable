<?php

/**
 * @link http://www.digitaldeals.cz/
 * @copyright Copyright (c) 2014 Digital Deals s.r.o. 
 * @license http://www.digitaldeals/license/
 */

namespace dlds\sortable;

use Yii;
use yii\web\AssetBundle;
use yii\base\InvalidConfigException;

/**
 * This is the class of Sortable Component
 */
class Module extends \yii\base\Component {

    /**
     * Returns info about module
     * @return array infolist
     */
    protected function info()
    {
        return [
            'author' => 'Jiri Svoboda',
        ];
    }

}
