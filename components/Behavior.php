<?php

/**
 * @link http://www.digitaldeals.cz/
 * @copyright Copyright (c) 2014 Digital Deals s.r.o. 
 * @license http://www.digitaldeals/license/
 */

namespace dlds\sortable\components;

use Yii;
use yii\db\Query;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;

/**
 * Behavior class which handles models sorting
 *
 * Settings example:
 * -----------------
 *  'BehaviorName' => [
 *      'class' => \dlds\sortable\components\Behavior::classname(),
 *      'column' => 'position',
 *  ],
 *
 * @author Jiri Svoboda <jiri.svoboda@dlds.cz>
 */
class Behavior extends \yii\base\Behavior {

    /**
     * @var string name of attr to be used as key
     */
    public $key;

    /**
     * @var mixed restriction array condition
     */
    public $restrictions = array();

    /**
     * @var string db table sort column
     */
    public $column = 'sortOrder';

    /**
     * @var string post array index of sort items
     */
    public $index = 'sortItems';

    /**
     * @return array events
     */
    public function events()
    {
        return [ActiveRecord::EVENT_BEFORE_INSERT => 'beforeInsert'];
    }

    /**
     * Before save
     */
    public function beforeInsert()
    {
        /** @var ActiveRecord $model */
        $model = $this->owner;

        if (!$model->hasAttribute($this->column))
        {
            throw new InvalidConfigException("Invalid sortable column `{$this->column}`.");
        }

        $maxOrder = $model->find()->max($model->tableName() . '.' . $this->column);

        $model->{$this->column} = $maxOrder + 1;
    }

    /**
     * After delete
     */
    public function afterDelete($event)
    {
        $restrictions = [];

        $this->_pullRestrictions($this->owner, $restrictions);

        // TODO: avoid multiple calls of fixSortGaps when deleting multiple models in once
        $this->_fixSortGaps($restrictions);

        parent::afterDelete($event);
    }

    /**
     * Retrieves model sort ID
     * @return string model sort ID
     */
    public function getOwnerKey()
    {
        if (isset($this->key))
        {
            return $this->owner->{$this->key};
        }

        return $this->owner->primaryKey;
    }

    /**
     * Retrieves model sort ID attr name
     * @return string model sort ID attr name
     */
    public function getOwnerKeyAttr()
    {
        if (isset($this->key))
        {
            return $this->key;
        }

        if (is_array($this->owner->tableSchema->primaryKey))
        {
            return ArrayHelper::getValue($this->owner->tableSchema->primaryKey, 0);
        }

        return $this->owner->tableSchema->primaryKey;
    }

    /**
     * Retrieves current owner sortOrder
     * @param boolean $reversed if sortOrder should be retrieves in normal or reverse order
     * @return int current sortOrder
     */
    public function getSortOrder($reversed = false)
    {
        if ($reversed)
        {
            $restrictions = [];

            $this->_pullRestrictions($this->owner, $restrictions);

            return ($this->getMaxSortOrder([], $restrictions) + 1) - $this->owner->{$this->column};
        }

        return $this->owner->{$this->column};
    }

    /**
     * Retrieves maximum sortOrder value for 
     * @param $items items which will be included
     * @return int max sortOrder
     */
    public function getMaxSortOrder($items = [], $restrictions = [])
    {
        $query = (new Query())->from($this->owner->tableName());

        if (!empty($items))
        {
            $query->where(['in', $this->getOwnerKeyAttr(), $items]);
        }

        if (!empty($restrictions))
        {
            foreach ($restrictions as $column => $values)
            {
                $query->andWhere(['in', $column, $values]);
            }
        }

        return (int) $query->max($this->column);
    }

    /**
     * Resets sortOrder and sets it to model ID value
     * @param array $items defines which models should be included, if is empty it includes all models
     * @return int 
     */
    public function resetSortOrder($items = [])
    {
        $condition = (empty($items)) ? '' : sprintf(' WHERE %s IN (%s)', $this->getOwnerKeyAttr(), implode(', ', $items));

        $sql = sprintf('UPDATE `%s` SET `%s`=`%s`%s', $this->owner->tableName(), $this->column, $this->getOwnerKeyAttr(), $condition);

        $query = \Yii::$app->db->createCommand($sql);

        return $query->execute();
    }

    /**
     * Sets sort order according to provided data in POST
     */
    public function setSortOrder()
    {
        $itemKeys = Yii::$app->request->post($this->index, false);

        if ($itemKeys && is_array($itemKeys))
        {
            $transaction = \Yii::$app->db->beginTransaction();

            $maxSortOrder = $this->getMaxSortOrder($itemKeys);

            if (!is_numeric($maxSortOrder) || $maxSortOrder == 0)
            {
                $this->resetSortOrder($itemKeys);
            }

            $currentModels = $this->_getCurrentModels($itemKeys);

            $restrictions = [];

            for ($i = 0; $i < count($itemKeys); $i++)
            {
                $model = $this->owner->find()->where([
                            $this->getOwnerKeyAttr() => $itemKeys[$i]
                        ])->one();
                
                $this->_pullRestrictions($model, $restrictions);

                \Yii::trace('Condition: ' . $model->{$this->column}. ' '. $currentModels[$i]->{$this->column});
                
                if ($model->{$this->column} != $currentModels[$i]->{$this->column})
                {
                    $model->{$this->column} = $currentModels[$i]->{$this->column};

                    if (!$model->save())
                    {
                        $transaction->rollback();

                        throw new \ErrorException('Cannot set model sort order.');
                    }
                }
            }

            $transaction->commit();

            $this->_fixSortGaps($restrictions);
        }
    }

    /**
     * Retrieves all current models
     * @param array $items current models keys
     * @return array current models
     */
    private function _getCurrentModels($items = [])
    {
        return $this->owner->find()
                        ->where([$this->getOwnerKeyAttr() => $items])
                        ->orderBy([$this->column => SORT_DESC])
                        ->all();
    }

    /**
     * Pulls restrictions from given model
     * @param CModel $model given model
     * @param array $restrictions given restrictions
     */
    private function _pullRestrictions(ActiveRecord $model, &$restrictions)
    {
        foreach ($this->restrictions as $attr)
        {
            if (isset($model->{$attr}) && (!isset($restrictions[$attr]) || !in_array($model->{$attr}, $restrictions[$attr])))
            {
                $restrictions[$attr][] = $model->{$attr};
            }
        }
    }

    /**
     * Assignes given restrictions to given criteria
     * @param array $restrictions given restrictions
     * @param CDbCriteria $query given criteria
     */
    private function _assignRestrictions($restrictions, &$query)
    {
        if (!empty($restrictions))
        {
            foreach ($restrictions as $column => $values)
            {
                $query->where([$column => $values]);
            }
        }
    }

    /**
     * Fixes sort gaps which can occure after delete entry
     */
    private function _fixSortGaps($restrictions = [])
    {
        $transaction = \Yii::$app->db->beginTransaction();

        $query = $this->owner->find();
        $query->orderBy([$this->column => SORT_ASC]);

        $this->_assignRestrictions($restrictions, $query);

        $models = $query->all();

        $sortOrder = 1;

        foreach ($models as $model)
        {
            if ($model->{$this->column} != $sortOrder)
            {
                $model->{$this->column} = $sortOrder;

                $model->save();
            }

            $sortOrder++;
        }

        $transaction->commit();
    }

}

?>
