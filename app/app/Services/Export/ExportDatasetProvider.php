<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;

/** Contract consumed by API and queue export flows. */
interface ExportDatasetProvider
{
    /** @return list<array{id:string,label:string,fields:array,field_metadata:array,scopes:array,formats:array}> */
    public function catalog(): array;

    /** Resolve freshly authorized canonical values, stable row identities, and provenance. */
    public function resolve(string $dataset, PortfolioProfile $profile, array $filters = []): array;

    /** Enforce the normal account data boundary for this dataset and profile. */
    public function assertAuthorized(string $dataset, PortfolioProfile $profile, int $userId): void;

    /** Report whether a requested scope has real provider semantics. */
    public function supportsScope(string $dataset, string $scope): bool;

    /** @return array{rows:int,exact:bool} */
    public function estimate(string $dataset, PortfolioProfile $profile, array $filters = []): array;
}
