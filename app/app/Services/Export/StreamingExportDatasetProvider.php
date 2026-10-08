<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;

interface StreamingExportDatasetProvider extends ExportDatasetProvider
{
    /**
     * Resolve worker rows lazily. Selected identities, when supplied, must be
     * revalidated against the fresh dataset before the stream is returned.
     *
     * @return array{columns:array,rows:iterable,metadata:array,estimated_rows:int,selection_validated?:bool}
     */
    public function stream(string $dataset, PortfolioProfile $profile, array $filters = [], array $selectedIds = []): array;
}
