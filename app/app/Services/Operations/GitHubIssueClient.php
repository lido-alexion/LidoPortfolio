<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Http;

class GitHubIssueClient
{
    public function searchOpen(string $marker): ?array
    {
        return $this->search($marker, 'is:open');
    }

    public function searchAny(string $marker): ?array
    {
        return $this->search($marker, '');
    }

    private function search(string $marker, string $state): ?array
    {
        $response = $this->request()->get('https://api.github.com/search/issues', [
            'q' => trim('repo:'.config('api_failure_reporting.repository').' is:issue '.$state.' "'.$marker.'"'),
            'per_page' => 5,
        ]);
        if (! $response->successful()) throw new \RuntimeException('GitHub issue search failed.');
        return $response->json('items.0');
    }

    public function create(string $title, string $body, array $labels = []): array
    {
        $response = $this->request()->post('https://api.github.com/repos/'.config('api_failure_reporting.repository').'/issues', [
            'title' => $title,
            'body' => $body,
            'labels' => $labels,
        ]);
        if (! $response->successful()) throw new \RuntimeException('GitHub issue creation failed.');
        return (array) $response->json();
    }

    private function request()
    {
        $token = config('api_failure_reporting.token');
        if (! is_string($token) || trim($token) === '') throw new \RuntimeException('GitHub issue token is not configured.');
        return Http::withToken($token)->acceptJson()->asJson()->timeout(10)->withHeaders([
            'User-Agent' => 'StoX-API-Failure-Reporter',
            'X-StoX-Skip-Api-Failure-Reporting' => '1',
        ]);
    }
}
