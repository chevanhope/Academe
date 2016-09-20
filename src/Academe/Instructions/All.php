<?php

namespace Academe\Instructions;

use Academe\Contracts\Mapper\Mapper;
use Academe\Instructions\Traits\WithRelation;
use Academe\Contracts\Mapper\Instructions\All as AllContract;

class All extends SelectionType implements AllContract
{
    use WithRelation;

    /**
     * @param Mapper $mapper
     * @return array
     */
    public function execute(Mapper $mapper)
    {
        $transactions = $this->getTransactions();

        $mapper->involve($transactions);

        $entities = $this->getEntities($mapper);

        $loadedRelations = $this->getLoadedRelations($entities, $mapper, $transactions);

        if (! empty($loadedRelations)) {
            $this->associateRelations($entities, $loadedRelations);
        }

        return $entities;
    }
}
