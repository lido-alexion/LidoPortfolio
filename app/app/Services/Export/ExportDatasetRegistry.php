<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;
use InvalidArgumentException;
use LogicException;

class ExportDatasetRegistry
{
    /** @var array<string, array{definition:array,provider:ExportDatasetProvider}> */
    private array $datasets = [];

    /** @param iterable<ExportDatasetProvider> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            foreach ($provider->catalog() as $definition) {
                $id = (string) ($definition['id'] ?? '');
                if ($id === '' || isset($this->datasets[$id])) {
                    throw new LogicException('Export dataset provider IDs must be present and unique.');
                }
                $this->datasets[$id] = ['definition' => $definition, 'provider' => $provider];
            }
        }
    }

    public function catalog(): array
    {
        return array_values(array_map(fn (array $entry) => $entry['definition'], $this->datasets));
    }

    public function resolve(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        return $this->providerFor($dataset)->resolve($dataset, $profile, $filters);
    }

    public function supportsScope(string $dataset, string $scope): bool
    {
        return isset($this->datasets[$dataset]) && $this->datasets[$dataset]['provider']->supportsScope($dataset, $scope);
    }

    public function assertAuthorized(string $dataset, PortfolioProfile $profile, int $userId): void
    {
        abort_unless(isset($this->datasets[$dataset]), 404);
        $this->datasets[$dataset]['provider']->assertAuthorized($dataset, $profile, $userId);
    }

    public function estimate(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        return $this->providerFor($dataset)->estimate($dataset, $profile, $filters);
    }

    private function providerFor(string $dataset): ExportDatasetProvider
    {
        if (! isset($this->datasets[$dataset])) throw new InvalidArgumentException('This dataset is not available for export.');
        return $this->datasets[$dataset]['provider'];
    }
}
