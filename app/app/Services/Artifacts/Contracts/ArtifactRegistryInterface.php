<?php

namespace App\Services\Artifacts\Contracts;

use App\Models\PortfolioProfile;
use App\Services\Artifacts\ValidationResult;

interface ArtifactRegistryInterface
{
    public function type(): string;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(?PortfolioProfile $profile = null, array $filters = []): array;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $idOrSlug, ?PortfolioProfile $profile = null): ?array;

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function create(array $envelope, ?PortfolioProfile $profile = null): array;

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function update(string $idOrSlug, array $envelope, ?PortfolioProfile $profile = null): array;

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function validate(array $envelope, ?PortfolioProfile $profile = null): ValidationResult;

    /**
     * @return array<string, mixed>
     */
    public function exportOne(string $idOrSlug, ?PortfolioProfile $profile = null): array;
}
