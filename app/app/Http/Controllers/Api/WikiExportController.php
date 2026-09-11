<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Wiki\WikiExportService;
use Symfony\Component\HttpFoundation\Response;

class WikiExportController extends Controller
{
    public function __construct(private WikiExportService $exports) {}

    public function page(string $page): Response
    {
        return $this->exports->page(\activePortfolio(), $page);
    }

    public function branch(string $page): Response
    {
        return $this->exports->archive(\activePortfolio(), $page);
    }

    public function wiki(): Response
    {
        return $this->exports->archive(\activePortfolio());
    }
}
