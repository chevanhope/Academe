<?php

namespace Academe\Relation\Contracts;

use Academe\Constant\TransactionConstant;

interface RelationPivot
{
    /**
     * @param $hostPrimary
     * @return array
     */
    public function getOtherKeys($hostPrimary);

    /**
     * @param       $hostPrimary
     * @param       $guestPrimary
     * @param array $additionAttributes
     * @param int   $lockLevel
     * @return
     */
    public function attachByKey($hostPrimary, $guestPrimary, $additionAttributes = [], $lockLevel = 0);

    /**
     * @param $hostPrimary
     * @param $guestPrimaries
     * @return int
     */
    public function detachByKeys($hostPrimary, $guestPrimaries);

    /**
     * @param $hostPrimary
     * @return int
     */
    public function detachAll($hostPrimary);

    /**
     * @param          $hostPrimary
     * @param          $guestPrimaries
     * @param bool     $detaching
     * @param int|null $lockLevel
     * @return array
     */
    public function syncByKeys($hostPrimary, $guestPrimaries, $detaching = true, $lockLevel = TransactionConstant::LOCK_UNSET);

}

