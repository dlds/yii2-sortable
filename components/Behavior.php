<?php

/**
 * @link http://www.digitaldeals.cz/
 * @copyright Copyright (c) 2014 Digital Deals s.r.o. 
 * @license http://www.digitaldeals/license/
 */

namespace dlds\sortable\components;

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
     * Before save
     */
    public function beforeValidate($event)
    {
        // TODO: pack this in extension BoostedGridView
        if ($this->owner->isNewRecord)
        {
            $this->owner->{$this->column} = $this->getMaxSortOrder() + 1;
        }

        return parent::beforeValidate($event);
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

        if (is_array($this->owner->primaryKey))
        {
            throw new Exception('GSortableBehavior owner primaryKey is an array - you have to set sortAttrID');
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

        if (is_array($this->owner->primaryKey))
        {
            throw new Exception('GSortableBehavior owner primaryKey is an array - you have to set sortAttrID');
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
        /* @var $connection \yii\db\Connection */
        $command = \Yii::$app->db->createCommand('SELECT MAX(' . $this->column . ')')->from($this->owner->tableName());

        if (!empty($items))
        {
            $command->where(['in', $this->getOwnerKeyAttr(), $items]);
        }

        if (!empty($restrictions))
        {
            foreach ($restrictions as $column => $values)
            {
                $command->andWhere(['in', $column, $values]);
            }
        }

        return (int) $command->queryScalar();
    }

    /**
     * Resets sortOrder and sets it to model ID value
     * @param array $items defines which models should be included, if is empty it includes all models
     * @return int 
     */
    public function resetSortOrder($items = [])
    {
        $sql = 'UPDATE ' . $this->owner->tableName() . ' SET ' . $this->column . ' = ' . $this->getOwnerKeyAttr();

        if (!empty($items))
        {
            $sql .= ' WHERE ' . $this->getOwnerKeyAttr() . ' IN (' . implode(', ', $items) . ')';
        }

        return \Yii::$app->db->createCommand($sql)->execute();
    }

    /**
     * Sets sort order according to provided data in POST
     */
    public function setSortOrder()
    {
        $itemKeys = Yii::app()->request->getPost($this->index, false);

        if ($itemKeys && is_array($itemKeys))
        {
            $transaction = $this->owner->dbConnection->beginTransaction();

            $maxSortOrder = $this->getMaxSortOrder($itemKeys);

            if (!is_numeric($maxSortOrder) || $maxSortOrder == 0)
            {
                $this->resetSortOrder($itemKeys);
            }

            $currentModels = $this->_getCurrentModels($itemKeys);

            $restrictions = [];

            for ($i = 0; $i < count($itemKeys); $i++)
            {
                $model = $this->owner->findByAttributes([
                    $this->getOwnerKeyAttr() => $itemKeys[$i],
                ]);

                $this->_pullRestrictions($model, $restrictions);

                if ($model->{$this->column} != $currentModels[$i]->{$this->column})
                {
                    $model->{$this->column} = $currentModels[$i]->{$this->column};

                    if (!$model->save())
                    {
                        $transaction->rollback();

                        throw new Exception('Cannot set model sort order.');
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
        $criteria = new DbCriteria;
        $criteria->addInCondition(sprintf('%s.%s', $this->owner->getTableAlias(), $this->getOwnerKeyAttr()), $items);
        $criteria->order = $this->column . ' DESC';

        return $this->owner->find($criteria);
    }

    /**
     * Pulls restrictions from given model
     * @param CModel $model given model
     * @param array $restrictions given restrictions
     */
    private function _pullRestrictions(CModel $model, &$restrictions)
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
     * @param CDbCriteria $criteria given criteria
     */
    private function _assignRestrictions($restrictions, &$criteria)
    {
        if (!empty($restrictions))
        {
            foreach ($restrictions as $column => $values)
            {
                $criteria->addInCondition($column, $values);
            }
        }
    }

    /**
     * Fixes sort gaps which can occure after delete entry
     */
    private function _fixSortGaps($restrictions = array())
    {
        $transaction = $this->owner->dbConnection->beginTransaction();

        $criteria = new CDbCriteria;
        $criteria->order = $this->column;

        $this->_assignRestrictions($restrictions, $criteria);

        $models = $this->owner->findAll($criteria);

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
