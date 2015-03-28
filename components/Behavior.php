<?php

/**
 * @link http://www.digitaldeals.cz/
 * @copyright Copyright (c) 2014 Digital Deals s.r.o. 
 * @license http://www.digitaldeals.cz/license/
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
        return [
            ActiveRecord::EVENT_BEFORE_INSERT => 'handleBeforeInsert',
            ActiveRecord::EVENT_AFTER_DELETE => 'handleAfterDelete'
        ];
    }

    /**
     * Before save
     */
    public function handleBeforeInsert()
    {
        /** @var ActiveRecord $model */
        $model = $this->owner;

        if (!$model->hasAttribute($this->column))
        {
            throw new InvalidConfigException("Invalid sortable column `{$this->column}`.");
        }

        $restrictions = [];

        $this->_pullRestrictions($this->owner, $restrictions);

        $maxOrder = $this->getMaxSortOrder([], $restrictions);

        $model->{$this->column} = $maxOrder + 1;
    }

    /**
     * After delete
     */
    public function handleAfterDelete()
    {
        $restrictions = [];

        $this->_pullRestrictions($this->owner, $restrictions);

        // TODO: avoid multiple calls of fixSortGaps when deleting multiple models in once
        $this->_fixSortGaps($restrictions);
    }

    /**
     * Retrieves sort column name
     * @return string sort column name
     */
    public function getSortColumn()
    {
        return $this->column;
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
     * Sets sort order according to provided data in POST
     */
    public function setSortOrder($sort)
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

            $currentModels = $this->_getCurrentModels($itemKeys, $sort);

            $restrictions = [];

            for ($i = 0; $i < count($itemKeys); $i++)
            {
                $model = $this->owner->find()->where([
                            $this->getOwnerKeyAttr() => $itemKeys[$i]
                        ])->one();

                $this->_pullRestrictions($model, $restrictions);

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
     * Is first in sorted row
     * @param int $sort sort type
     * @return boolean TRUE if is first, FALSE otherwise
     */
    public function isFirst($sort = SORT_ASC)
    {
        $sorted = ArrayHelper::getColumn($this->_getSortedModels($sort), $this->getOwnerKeyAttr());

        $last = array_shift($sorted);

        return (null !== $last && $this->getOwnerKey() === (int) $last);
    }

    /**
     * Is last in sorted row
     * @param int $sort sort type
     * @return boolean TRUE if is last, FALSE otherwise
     */
    public function isLast($sort = SORT_ASC)
    {
        $sorted = ArrayHelper::getColumn($this->_getSortedModels($sort), $this->getOwnerKeyAttr());

        $last = array_pop($sorted);

        return (null !== $last && $this->getOwnerKey() === (int) $last);
    }

    /**
     * Retrieves prev in sorted row
     * @param int $sort sort type
     * @return int primary key of prev record in row
     */
    public function prev($sort = SORT_ASC)
    {
        $sorted = ArrayHelper::getColumn($this->_getSortedModels($sort), $this->getOwnerKeyAttr());

        $current = array_search($this->getOwnerKey(), $sorted);

        if (false !== $current)
        {
            $next = ArrayHelper::getValue($sorted, --$current, null);

            return $next;
        }

        return null;
    }

    /**
     * Retrieves next in sorted row
     * @param int $sort sort type
     * @return int primary key of next record in row
     */
    public function next($sort = SORT_ASC)
    {
        $sorted = ArrayHelper::getColumn($this->_getSortedModels($sort), $this->getOwnerKeyAttr());

        $current = array_search($this->getOwnerKey(), $sorted);

        if (false !== $current)
        {
            $next = ArrayHelper::getValue($sorted, ++$current, null);

            return $next;
        }

        return null;
    }

    /**
     * Retrieves model sort ID
     * @return string model sort ID
     */
    protected function getOwnerKey()
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
    protected function getOwnerKeyAttr()
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
     * Retrieves maximum sortOrder value for 
     * @param $items items which will be included
     * @return int max sortOrder
     */
    protected function getMaxSortOrder($items = [], $restrictions = [])
    {
        $query = $this->_getQuery($items, $restrictions);

        return (int) $query->max($this->column);
    }

    /**
     * Resets sortOrder and sets it to model ID value
     * @param array $items defines which models should be included, if is empty it includes all models
     * @return int 
     */
    protected function resetSortOrder($items = [])
    {
        $condition = (empty($items)) ? '' : sprintf(' WHERE %s IN (%s)', $this->getOwnerKeyAttr(), implode(', ', $items));

        $sql = sprintf('UPDATE `%s` SET `%s`=`%s`%s', $this->owner->tableName(), $this->column, $this->getOwnerKeyAttr(), $condition);

        $query = \Yii::$app->db->createCommand($sql);

        return $query->execute();
    }

    /**
     * Returns base query
     * @param array $items given restricted items
     * @param array $restrictions given restrictions
     * @return \yii\db\ActiveQuery query
     */
    private function _getQuery($items = [], $restrictions = [])
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

        return $query;
    }

    /**
     * Retrieves sorted models
     * @return array sorted models
     */
    private function _getSortedModels($sort = SORT_ASC)
    {
        $restrictions = [];

        $this->_pullRestrictions($this->owner, $restrictions);

        $query = $this->_getQuery([], $restrictions);

        $query->orderBy([$this->column => $sort]);

        return $query->all();
    }

    /**
     * Retrieves all current models
     * @param array $items current models keys
     * @return array current models
     */
    private function _getCurrentModels($items = [], $sort = SORT_DESC)
    {
        return $this->owner->find()
                        ->where([$this->getOwnerKeyAttr() => $items])
                        ->orderBy([$this->column => $sort])
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

        return $restrictions;
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
