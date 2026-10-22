<?php

namespace App\Services\Strategy;

use InvalidArgumentException;

final class StrategyArchiveBlocked extends InvalidArgumentException
{
    /** @param list<array{code:string,label:string,count:int,href:string}> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('This strategy still has outstanding records to resolve.');
    }
}
