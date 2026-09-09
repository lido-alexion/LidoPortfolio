<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxRuleVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaxRuleVersionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => TaxRuleVersion::query()
            ->with('creator:id,name,email')
            ->orderByDesc('effective_from')->orderByDesc('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:32', 'unique:portfolio_tax_rule_versions,version'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'source_reference' => ['required', 'string', 'max:255'],
            'rules' => ['required', 'array'],
            'rules.long_term_holding_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'rules.short_term_rate' => ['nullable', 'numeric', 'between:0,1'],
            'rules.long_term_rate' => ['nullable', 'numeric', 'between:0,1'],
            'rules.long_term_exemption' => ['nullable', 'numeric', 'min:0'],
            'rules.fee_classifications' => ['required', 'array'],
        ]);

        $overlap = TaxRuleVersion::query()
            ->whereDate('effective_from', '<=', $validated['effective_to'] ?? '9999-12-31')
            ->where(function ($query) use ($validated) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $validated['effective_from']);
            })->exists();
        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_from' => ['Tax-rule effective periods cannot overlap. Close the prior version first through a new reconciled migration or administrative correction process.'],
            ]);
        }

        $version = TaxRuleVersion::query()->create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $version->load('creator:id,name,email')], 201);
    }
}
