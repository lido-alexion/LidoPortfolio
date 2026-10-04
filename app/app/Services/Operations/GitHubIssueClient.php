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
        for ($page = 1; $page <= 10; $page++) {
            $response = $this->request()->get('https://api.github.com/search/issues', [
                'q' => trim('repo:'.config('api_failure_reporting.repository').' is:issue '.$state.' "'.$marker.'"'),
                'per_page' => 100, 'page' => $page, 'sort' => 'created', 'order' => 'desc',
            ]);
            if (! $response->successful() || $response->json('incomplete_results', false)) throw new \RuntimeException('GitHub issue search failed.');
            $items = $response->json('items');
            if (! is_array($items)) throw new \RuntimeException('GitHub issue search invalid.');
            foreach ($items as $item) {
                if (! isset($item['pull_request']) && is_string($item['body'] ?? null) && str_contains($item['body'], $marker)
                    && isset($item['number']) && ($state !== 'is:open' || ($item['state'] ?? null) === 'open')) return $item;
            }
            if (count($items) < 100) return null;
        }
        throw new \RuntimeException('GitHub issue search truncated.');
    }

    public function get(int $number): array
    {
        $response = $this->request()->get('https://api.github.com/repos/'.config('api_failure_reporting.repository').'/issues/'.$number);
        if (! $response->successful() || ! $response->json('number')) throw new \RuntimeException('GitHub issue lookup failed.');
        return (array) $response->json();
    }

    public function create(string $title, string $body, array $labels = []): array
    {
        $response = $this->request()->post('https://api.github.com/repos/'.config('api_failure_reporting.repository').'/issues', [
            'title' => $title,
            'body' => $body,
            'labels' => $labels,
        ]);
        if ($response->status() === 422 && $labels && collect($response->json('errors', []))->contains(fn ($error) => ($error['field'] ?? null) === 'labels')) {
            return $this->create($title, $body, []);
        }
        if (! $response->successful() || ! $response->json('number')) throw new \RuntimeException('GitHub issue creation failed.');
        return (array) $response->json();
    }

    private function request()
    {
        $token = config('api_failure_reporting.token');
        if (! is_string($token) || trim($token) === '') throw new \RuntimeException('GitHub issue token is not configured.');
        return Http::withToken($token)->acceptJson()->asJson()->timeout(5)->withHeaders([
            'User-Agent' => 'StoX-API-Failure-Reporter',
            'X-StoX-Skip-Api-Failure-Reporting' => '1',
        ]);
    }
}
