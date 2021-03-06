<?php

namespace Academe\Relation\Managers;

use Academe\Constant\TransactionConstant;
use Academe\Contracts\Mapper\Mapper;
use Academe\Contracts\Writer;
use Academe\Relation\Contracts\RelationPivot;
use Academe\Support\ArrayHelper;

class ManyToManyRelationPivot implements RelationPivot
{
    /**
     * @var \Academe\Contracts\Mapper\Mapper
     */
    protected $pivotMapper;

    /**
     * @var string
     */
    protected $mainKey;

    /**
     * @var string
     */
    protected $attachedKey;

    /**
     * ManyToManyPivotHandler constructor.
     *
     * @param \Academe\Contracts\Mapper\Mapper $pivotMapper
     * @param                                  $mainKey
     * @param                                  $attachedKey
     */
    public function __construct(Mapper $pivotMapper, $mainKey, $attachedKey)
    {
        $this->pivotMapper = $pivotMapper;
        $this->mainKey     = $mainKey;
        $this->attachedKey = $attachedKey;
    }

    /**
     * @param       $hostPrimary
     * @param       $guestPrimary
     * @param array $additionAttributes
     * @param int   $lockLevel
     */
    public function attachByKey($hostPrimary, $guestPrimary, $additionAttributes = [], $lockLevel = 0)
    {
        $mainAttributes = [
            $this->mainKey     => $hostPrimary,
            $this->attachedKey => $guestPrimary,
        ];

        $this->createOrUpdate($mainAttributes, $additionAttributes, $lockLevel);
    }

    /**
     * @param $hostPrimary
     * @param $guestPrimaries
     * @return int
     */
    public function detachByKeys($hostPrimary, $guestPrimaries)
    {
        $pivotMapper = $this->getPivotMapper();

        return $pivotMapper->query()
            ->equal($this->mainKey, $hostPrimary)
            ->in($this->attachedKey, $guestPrimaries)
            ->delete();
    }

    /**
     * @param array $mainAttributes
     * @param array $additionAttributes
     * @param int   $lockLevel
     */
    protected function createOrUpdate($mainAttributes, $additionAttributes, $lockLevel = 0)
    {
        $pivotMapper = $this->getPivotMapper();

        $writer = $pivotMapper->getAcademe()->getWriter();

        $conditionStatement = $this->makeEqualityConditionsFromAttributes($mainAttributes, $writer);

        $entity = $pivotMapper->query()
            ->setLockLevel($lockLevel)
            ->apply($conditionStatement)
            ->first();

        if ($entity) {
            $primaryKey = $pivotMapper->getPrimaryKey();

            $this->updateBySpecificKey(
                $primaryKey,
                $entity[$primaryKey],
                $additionAttributes
            );
        } else {
            $pivotMapper->query()
                ->create(array_merge($additionAttributes, $mainAttributes));
        }
    }

    /**
     * @param       $primaryKey
     * @param       $primaryKeyValue
     * @param array $attributes
     * @return int
     */
    protected function updateBySpecificKey($primaryKey, $primaryKeyValue, array $attributes)
    {
        if (count($attributes) === 0) {
            return 0;
        }

        $pivotMapper = $this->getPivotMapper();

        return $pivotMapper->query()
            ->equal($primaryKey, $primaryKeyValue)
            ->update($attributes);
    }

    /**
     * @param $hostPrimary
     * @return int
     */
    public function detachAll($hostPrimary)
    {
        $pivotMapper = $this->getPivotMapper();

        return $pivotMapper->query()
            ->equal($this->mainKey, $hostPrimary)
            ->delete();
    }

    /**
     * @param          $hostPrimary
     * @param          $guestPrimaries
     * @param bool     $detaching
     * @param int|null $lockLevel
     * @return array
     */
    public function syncByKeys($hostPrimary, $guestPrimaries, $detaching = true, $lockLevel = TransactionConstant::LOCK_UNSET)
    {
        $changes         = [
            'attached' => [],
            'detached' => [],
            'updated'  => [],
        ];
        $pivotMapper     = $this->getPivotMapper();
        $pivotPrimaryKey = $pivotMapper->getPrimaryKey();

        $currentEntities = $pivotMapper->query()
            ->setLockLevel($lockLevel)
            ->equal($this->mainKey, $hostPrimary)
            ->all([$pivotPrimaryKey, $this->attachedKey]);

        $currentList = $this->makeList($currentEntities, $pivotPrimaryKey, $this->attachedKey);

        $keyValueList = $this->formatSyncList($guestPrimaries);

        $detach = array_values(
            array_diff($currentList, array_keys($keyValueList))
        );

        if ($detaching && count($detach) > 0) {
            $this->detachByKeys($hostPrimary, $detach);
            $changes['detached'] = $detach;
        }

        $changes = array_merge(
            $changes,
            $this->attachNewOrUpdateExists($hostPrimary, $currentList, $keyValueList, $lockLevel)
        );

        return $changes;
    }

    /**
     * @param array $records
     * @return array
     */
    protected function formatSyncList($records)
    {
        $result = [];

        foreach ($records as $id => $attributes) {
            if (! is_array($attributes)) {
                list($id, $attributes) = [$attributes, []];
            }

            $result[$id] = $attributes;
        }

        return $result;
    }

    /**
     * @param $entities
     * @param $key
     * @param $field
     * @return array
     */
    protected function makeList($entities, $key, $field)
    {
        $list = [];

        foreach ($entities as $entity) {
            $list[$entity[$key]] = $entity[$field];
        }

        return $list;
    }

    /**
     * @param     $mainKeyValue
     * @param     $currentList
     * @param     $keyValueList
     * @param int $lockLevel
     * @return array
     */
    protected function attachNewOrUpdateExists($mainKeyValue, $currentList, $keyValueList, $lockLevel = 0)
    {
        $changes = [
            'attached' => [],
            'updated'  => [],
        ];

        foreach ($keyValueList as $id => $attributes) {
            if (! in_array($id, $currentList)) {
                $this->attachByKey($mainKeyValue, $id, $attributes, $lockLevel);
                $changes['attached'][] = $id;
            } elseif (count($attributes) > 0) {
                if ($this->updateExistingPivot($mainKeyValue, $id, $attributes)) {
                    $changes['attached'][] = $id;
                }
            }
        }

        return $changes;
    }

    /**
     * @param       $mainKeyValue
     * @param       $attachedKeyValue
     * @param array $additionAttributes
     * @return int
     */
    protected function updateExistingPivot($mainKeyValue, $attachedKeyValue, $additionAttributes = [])
    {
        $pivotMapper = $this->getPivotMapper();

        $conditionStatement = $this->makeEqualityConditionsFromAttributes([
            $this->mainKey     => $mainKeyValue,
            $this->attachedKey => $attachedKeyValue,
        ], $pivotMapper->getAcademe()->getWriter());

        return $pivotMapper->query()
            ->apply($conditionStatement)
            ->update($additionAttributes);
    }

    /**
     * @return \Academe\Contracts\Mapper\Mapper
     */
    protected function getPivotMapper()
    {
        return $this->pivotMapper;
    }

    /**
     * @param                           $attributes
     * @param \Academe\Contracts\Writer $writer
     * @return \Academe\Statement\ConditionStatement
     */
    protected function makeEqualityConditionsFromAttributes($attributes, Writer $writer)
    {
        $conditions = [];

        foreach ($attributes as $attribute => $value) {
            $conditions[] = $writer->equal($attribute, $value);
        }

        return $writer->must($conditions);
    }

    /**
     * @param $hostPrimary
     * @return array
     */
    public function getOtherKeys($hostPrimary)
    {
        $attachedKey = $this->attachedKey;

        $pivotEntities = $this->getPivotMapper()->query()
            ->equal($this->mainKey, $hostPrimary)
            ->all([$attachedKey]);

        return ArrayHelper::map($pivotEntities, function ($pivotEntity) use ($attachedKey) {
            return $pivotEntity[$attachedKey];
        });
    }

}


