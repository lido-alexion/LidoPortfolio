<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Wiki\WikiPageService;
use App\Services\Wiki\WikiShareService;
use Illuminate\Http\JsonResponse;

class WikiShareController extends Controller
{
    public function __construct(private WikiPageService $pages, private WikiShareService $shares) {}

    public function store(string $page): JsonResponse
    {
        return response()->json(['data' => $this->shares->enable($this->pages->find(\activePortfolio(), $page), \activePortfolio())], 201);
    }

    public function regenerate(string $page): JsonResponse
    {
        return response()->json(['data' => $this->shares->regenerate($this->pages->find(\activePortfolio(), $page), \activePortfolio())]);
    }

    public function destroy(string $page): JsonResponse
    {
        $this->shares->revoke($this->pages->find(\activePortfolio(), $page), \activePortfolio());

        return response()->json(['data' => ['shared' => false]]);
    }

    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->shares->publicPage($token)]);
    }
}
