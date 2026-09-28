<?php

namespace App\Contracts;

interface MlHistoricalUniverseProvider
{
    /**
     * @return array{effective_from:string,source:string,snapshot_key:string,response_version:?string,memberships:list<array{stock_id:int,sector_snapshot?:?string,provider_symbol?:?string,provider_token?:?string,exchange?:?string}>}
     */
    public function snapshotForDate(string $date): array;
}
