import React, { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { DataTableCard } from '../components/DataTable';
import NumberInput from '../components/NumberInput';
import useApiGet from '../hooks/useApiGet';
import { runApiMutation } from '../hooks/useApiMutation';
import { showToast } from '../toast';

const SECTIONS = [
    { id: 'general', label: 'General' },
    { id: 'eligibility', label: 'Eligibility Sources' },
    { id: 'scoring', label: 'Scoring Model' },
    { id: 'thresholds', label: 'Recommendation Thresholds' },
    { id: 'portfolio', label: 'Portfolio Rules' },
    { id: 'allocation', label: 'Capital Allocation' },
    { id: 'exit', label: 'Exit Strategy' },
    { id: 'market', label: 'Market Gates' },
    { id: 'cash', label: 'Cash Management' },
];

const CATEGORY_ORDER = ['Momentum', 'Trend', 'Volume', 'Market', 'Risk'];

/** Scale enabled indicator weights to sum to 100 (2 d.p., largest-remainder). */
function redistributeEnabledWeights(indicators = []) {
    const list = indicators.map((ind) => ({ ...ind }));
    const enabledIdx = [];
    let sum = 0;
    list.forEach((ind, i) => {
        if (!ind.enabled) return;
        const w = Math.max(0, Number(ind.weight) || 0);
        enabledIdx.push(i);
        sum += w;
    });
    if (!enabledIdx.length || sum <= 0) return list;
    if (Math.abs(sum - 100) <= 0.01) return list;

    const floors = {};
    const fracs = [];
    enabledIdx.forEach((i) => {
        const w = Math.max(0, Number(list[i].weight) || 0);
        const scaled = (w / sum) * 100;
        const floor = Math.floor(scaled * 100) / 100;
        floors[i] = floor;
        fracs.push({ i, frac: scaled - floor });
    });
    let remainderHundredths = Math.round((100 - Object.values(floors).reduce((a, b) => a + b, 0)) * 100);
    fracs.sort((a, b) => b.frac - a.frac);
    for (const { i } of fracs) {
        if (remainderHundredths <= 0) break;
        floors[i] = Math.round((floors[i] + 0.01) * 100) / 100;
        remainderHundredths -= 1;
    }
    Object.entries(floors).forEach(([i, weight]) => {
        list[Number(i)].weight = weight;
    });
    return list;
}

const SCORING_CATEGORY_ICONS = {
    Momentum: (
        <svg className="lido-strategy-category-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M3.5 18.5 9 13l3.5 3.5L20.5 7.5 19 6l-6.5 8L9 10.5 2 17.5z" />
            <path fill="currentColor" d="M14 6h6v6h-2V9.4l-6.3 6.3-1.4-1.4L16.6 8H14z" />
        </svg>
    ),
    Trend: (
        <svg className="lido-strategy-category-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M4 18h2V9h3v9h2V6h3v12h2V8h3v10h1v2H3v-2h1z" />
        </svg>
    ),
    Volume: (
        <svg className="lido-strategy-category-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M4 20h3V10H4zm6.5 0h3V4h-3zm6.5 0h3v-7h-3z" />
        </svg>
    ),
    Market: (
        <svg className="lido-strategy-category-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm7.4 9h-3.1a15.6 15.6 0 0 0-1.3-5.1A8 8 0 0 1 19.4 11zM12 4c.9 0 2.5 2.3 3.1 6H8.9C9.5 6.3 11.1 4 12 4zM4.6 13h3.1c.2 1.9.7 3.6 1.3 5.1A8 8 0 0 1 4.6 13zm3.1-2H4.6a8 8 0 0 1 4.4-5.1A15.6 15.6 0 0 0 7.7 11zm1.2 2h6.2c-.6 3.7-2.2 6-3.1 6s-2.5-2.3-3.1-6zm6.3 5.1c.6-1.5 1.1-3.2 1.3-5.1h3.1a8 8 0 0 1-4.4 5.1z" />
        </svg>
    ),
    Risk: (
        <svg className="lido-strategy-category-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M12 2 2 21h20L12 2zm0 4.8 6.3 11.7H5.7L12 6.8zM11 10h2v5h-2zm0 6h2v2h-2z" />
        </svg>
    ),
};

function ScoringCategoryTitle({ category }) {
    return (
        <span className="lido-strategy-category-title">
            {SCORING_CATEGORY_ICONS[category] || null}
            <span>{category}</span>
        </span>
    );
}

const MARKET_PHASES = [
    'Strong Bull', 'Bull', 'Consolidation', 'Pullback', 'Correction', 'Bear', 'Capitulation', 'Recovery',
];

function StrategySwitch({ id, checked, onChange, label = null, className = 'mb-0', ariaLabel = null, disabled = false }) {
    return (
        <div className={`form-check form-switch ${className}`.trim()}>
            <input
                className="form-check-input"
                type="checkbox"
                role="switch"
                id={id}
                checked={Boolean(checked)}
                disabled={disabled}
                onChange={(e) => onChange(e.target.checked)}
                aria-label={ariaLabel || label || undefined}
            />
            {label ? (
                <label className={`form-check-label${disabled ? ' text-muted' : ''}`} htmlFor={id}>{label}</label>
            ) : null}
        </div>
    );
}

function emptyConfig() {
    return {
        eligibility_sources: [],
        indicators: [],
        thresholds: {},
        portfolio_rules: {},
        capital_allocation: { strategy: 'proportional', tie_break: 'highest_score', score_bands: [] },
        cash_rules: {},
        exit_strategy: { enabled: true, mode: 'any', rules: [] },
        market_gates: {
            enabled: false,
            min_sentiment: 45,
            allowed_phases: ['Strong Bull', 'Bull', 'Consolidation', 'Pullback', 'Recovery'],
            max_risk_raw: 70,
        },
        recommendation_behaviour: {},
        risk: {},
    };
}

function applyPayload(payload, setMeta, setConfig) {
    setMeta({
        name: payload.name || 'Strategy',
        description: payload.description || '',
        modified_at: payload.modified_at,
        status: payload.status,
        enabled_indicator_count: payload.enabled_indicator_count ?? payload.enabled_factor_count,
        weight_total: payload.weight_total,
        weights_valid: payload.weights_valid,
    });
    const rawIndicators = payload.scoring_model || payload.indicators || payload.config?.indicators || payload.factors || [];
    setConfig({
        ...emptyConfig(),
        ...(payload.config || {}),
        eligibility_sources: payload.eligibility_sources || payload.config?.eligibility_sources || [],
        indicators: rawIndicators.map((ind) => (
            ind?.key === 'risk_score' && (ind.minimum == null || ind.minimum === '')
                ? { ...ind, minimum: 0 }
                : ind
        )),
        thresholds: payload.thresholds || payload.config?.thresholds || {},
        portfolio_rules: payload.portfolio_rules || payload.config?.portfolio_rules || {},
        capital_allocation: payload.capital_allocation || payload.config?.capital_allocation || emptyConfig().capital_allocation,
        cash_rules: payload.cash_rules || payload.config?.cash_rules || {},
        exit_strategy: payload.exit_strategy || payload.config?.exit_strategy || emptyConfig().exit_strategy,
        market_gates: payload.market_gates || payload.config?.market_gates || emptyConfig().market_gates,
        recommendation_behaviour: payload.recommendation_behaviour || payload.config?.recommendation_behaviour || {},
        risk: payload.config?.risk || {},
    });
}

export default function StrategyPage() {
    const [section, setSection] = useState('general');
    const [saving, setSaving] = useState(false);
    const [availableScreeners, setAvailableScreeners] = useState([]);
    const [meta, setMeta] = useState({
        name: '', description: '', modified_at: null, status: null,
    });
    const [config, setConfig] = useState(emptyConfig);
    const [addScreenerId, setAddScreenerId] = useState('');
    const [benchmarkIndexes, setBenchmarkIndexes] = useState([]);

    const { loading, reload: load } = useApiGet({
        deps: [],
        errorFallback: 'Failed to load strategy',
        request: async () => {
            const [{ data }, screenersRes, indexesRes] = await Promise.all([
                api.get('/v1/strategy', { skipErrorToast: true }),
                api.get('/screeners', { skipErrorToast: true }).catch(() => ({ data: [] })),
                api.get('/indexes', { skipErrorToast: true }).catch(() => ({ data: {} })),
            ]);
            applyPayload(data?.data || {}, setMeta, setConfig);
            const list = Array.isArray(screenersRes?.data?.data)
                ? screenersRes.data.data
                : (Array.isArray(screenersRes?.data) ? screenersRes.data : []);
            setAvailableScreeners(list);
            setBenchmarkIndexes(indexesRes?.data?.data?.indexes || []);
            return data?.data;
        },
    });

    const grouped = useMemo(() => {
        const map = {};
        for (const ind of config.indicators || []) {
            const cat = ind.category || 'Other';
            if (!map[cat]) map[cat] = [];
            map[cat].push(ind);
        }
        return map;
    }, [config.indicators]);

    const weightTotal = useMemo(() => (
        (config.indicators || [])
            .filter((ind) => ind.enabled)
            .reduce((sum, ind) => sum + Number(ind.weight || 0), 0)
    ), [config.indicators]);

    const weightsValid = Math.abs(weightTotal - 100) < 0.01;

    const assignedIds = useMemo(
        () => new Set((config.eligibility_sources || []).map((s) => Number(s.screener_id))),
        [config.eligibility_sources],
    );

    const save = async () => {
        const enabledPositive = (config.indicators || []).some(
            (ind) => ind.enabled && Number(ind.weight || 0) > 0,
        );
        if (!enabledPositive) {
            showToast('Enable at least one scoring factor with a positive weight.', 'danger');
            setSection('scoring');
            return;
        }

        const didNormalize = !weightsValid;
        const indicators = redistributeEnabledWeights(config.indicators || []);
        if (didNormalize) {
            setConfig((prev) => ({ ...prev, indicators }));
        }

        setSaving(true);
        try {
            const { ok, data: payload } = await runApiMutation(async () => {
                const { data } = await api.put('/v1/strategy', {
                    name: meta.name,
                    description: meta.description,
                    config: {
                        ...config,
                        indicators,
                        eligibility_sources: config.eligibility_sources,
                        exit_strategy: config.exit_strategy,
                        market_gates: config.market_gates,
                    },
                }, { skipErrorToast: true });
                const next = data?.data || {};
                applyPayload(next, setMeta, setConfig);
                return next;
            }, { errorFallback: 'Save failed' });
            if (ok && payload) {
                showToast(
                    `Strategy saved${didNormalize ? ' (weights auto-normalised to 100)' : ''}`,
                    'success',
                );
            }
        } finally {
            setSaving(false);
        }
    };

    const updateIndicator = useCallback((key, patch) => {
        setConfig((prev) => ({
            ...prev,
            indicators: (prev.indicators || []).map((row) => (row.key === key ? { ...row, ...patch } : row)),
        }));
    }, []);

    const updateIndicatorParam = useCallback((key, paramKey, value) => {
        setConfig((prev) => ({
            ...prev,
            indicators: (prev.indicators || []).map((row) => {
                if (row.key !== key) return row;
                return { ...row, parameters: { ...(row.parameters || {}), [paramKey]: value } };
            }),
        }));
    }, []);

    const scoringColumns = useMemo(() => [
        {
            id: 'enabled',
            accessorKey: 'enabled',
            header: () => <span className="ps-3">On</span>,
            size: 88,
            minSize: 72,
            maxSize: 120,
            enableSorting: false,
            cell: ({ row }) => (
                <div className="ps-3">
                    <StrategySwitch
                        id={`score-on-${row.original.key}`}
                        checked={Boolean(row.original.enabled)}
                        onChange={(enabled) => updateIndicator(row.original.key, { enabled })}
                        ariaLabel={`Enable ${row.original.display_name || row.original.key}`}
                    />
                </div>
            ),
        },
        {
            id: 'factor',
            accessorKey: 'display_name',
            header: 'Factor',
            size: 260,
            minSize: 160,
            cell: ({ row }) => {
                const disabled = !row.original.enabled;
                return (
                    <div className={disabled ? 'opacity-50' : undefined}>
                        <div className="fw-semibold">{row.original.display_name}</div>
                        <div className="text-muted small">{row.original.description}</div>
                    </div>
                );
            },
        },
        {
            id: 'weight',
            accessorKey: 'weight',
            header: 'Weight',
            size: 180,
            minSize: 150,
            maxSize: 280,
            cell: ({ row }) => {
                const disabled = !row.original.enabled;
                return (
                    <NumberInput
                        className="lido-number-input--compact"
                        buttonVariant="secondary"
                        step="1"
                        min="0"
                        allowDecimals={false}
                        disabled={disabled}
                        value={row.original.weight ?? ''}
                        onChange={(e) => updateIndicator(row.original.key, {
                            weight: e.target.value === '' ? 0 : Number(e.target.value),
                        })}
                        aria-label={`Weight for ${row.original.display_name || row.original.key}`}
                    />
                );
            },
        },
        {
            id: 'minimum',
            accessorKey: 'minimum',
            header: 'Min',
            size: 180,
            minSize: 150,
            maxSize: 280,
            cell: ({ row }) => {
                const disabled = !row.original.enabled;
                const isRisk = row.original.key === 'risk_score';
                const minValue = row.original.minimum ?? (isRisk ? 0 : '');
                return (
                    <NumberInput
                        className="lido-number-input--compact"
                        buttonVariant="secondary"
                        step="1"
                        min="0"
                        max="100"
                        allowDecimals={false}
                        disabled={disabled}
                        value={minValue}
                        onChange={(e) => updateIndicator(row.original.key, {
                            minimum: e.target.value === '' ? null : Number(e.target.value),
                        })}
                        aria-label={`Minimum for ${row.original.display_name || row.original.key}`}
                    />
                );
            },
        },
        {
            id: 'maximum',
            accessorKey: 'maximum',
            header: 'Max',
            size: 180,
            minSize: 150,
            maxSize: 280,
            cell: ({ row }) => {
                const disabled = !row.original.enabled;
                if (!(row.original.supports_maximum || row.original.key === 'risk_score')) {
                    return <span className="text-muted small">—</span>;
                }
                return (
                    <NumberInput
                        className="lido-number-input--compact"
                        buttonVariant="secondary"
                        step="1"
                        min="0"
                        max="100"
                        allowDecimals={false}
                        disabled={disabled}
                        value={row.original.maximum ?? ''}
                        onChange={(e) => updateIndicator(row.original.key, {
                            maximum: e.target.value === '' ? null : Number(e.target.value),
                        })}
                        aria-label={`Maximum for ${row.original.display_name || row.original.key}`}
                    />
                );
            },
        },
        {
            id: 'parameters',
            header: 'Parameters',
            size: 320,
            minSize: 200,
            enableSorting: false,
            cell: ({ row }) => {
                const disabled = !row.original.enabled;
                const params = row.original.parameters || {};
                if (Object.keys(params).length === 0) {
                    return <span className="text-muted small">—</span>;
                }
                const indexOptions = [...benchmarkIndexes];
                return (
                    <div className={`d-flex flex-wrap gap-2${disabled ? ' opacity-75' : ''}`}>
                        {Object.entries(params).map(([paramKey, paramVal]) => {
                            const label = paramKey.replaceAll('_', ' ');
                            const isBenchmark = paramKey === 'benchmark' || paramKey.endsWith('_benchmark');
                            if (isBenchmark) {
                                const current = paramVal ?? '';
                                const hasCurrent = indexOptions.some((idx) => idx.symbol === current);
                                return (
                                    <div key={paramKey} style={{ minWidth: 160 }}>
                                        <div className="form-text mb-0 text-capitalize">{label}</div>
                                        <select
                                            className="form-select form-select-sm"
                                            value={current}
                                            disabled={disabled}
                                            onChange={(e) => updateIndicatorParam(row.original.key, paramKey, e.target.value)}
                                            aria-label={`${label} for ${row.original.display_name || row.original.key}`}
                                        >
                                            {!hasCurrent && current !== '' ? (
                                                <option value={current}>{current}</option>
                                            ) : null}
                                            {current === '' ? <option value="">Select index…</option> : null}
                                            {indexOptions.map((idx) => (
                                                <option key={idx.symbol} value={idx.symbol}>
                                                    {idx.symbol} — {idx.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                );
                            }
                            return (
                                <div key={paramKey} style={{ minWidth: 120 }}>
                                    <div className="form-text mb-0 text-capitalize">{label}</div>
                                    <NumberInput
                                        className="lido-number-input--compact"
                                        buttonVariant="secondary"
                                        step="1"
                                        min="1"
                                        allowDecimals={false}
                                        disabled={disabled}
                                        value={paramVal ?? ''}
                                        onChange={(e) => {
                                            const raw = e.target.value;
                                            updateIndicatorParam(
                                                row.original.key,
                                                paramKey,
                                                raw === '' ? '' : Number(raw),
                                            );
                                        }}
                                        aria-label={`${label} for ${row.original.display_name || row.original.key}`}
                                    />
                                </div>
                            );
                        })}
                    </div>
                );
            },
        },
    ], [benchmarkIndexes, updateIndicator, updateIndicatorParam]);

    const updateThreshold = (key, value) => {
        setConfig((prev) => ({ ...prev, thresholds: { ...prev.thresholds, [key]: value === '' ? '' : Number(value) } }));
    };

    const updatePortfolioRule = (key, value) => {
        setConfig((prev) => ({ ...prev, portfolio_rules: { ...prev.portfolio_rules, [key]: value === '' ? '' : Number(value) } }));
    };

    const updateBehaviour = (key, value) => {
        setConfig((prev) => ({ ...prev, recommendation_behaviour: { ...prev.recommendation_behaviour, [key]: value } }));
    };

    const updateCashRule = (key, value) => {
        setConfig((prev) => ({ ...prev, cash_rules: { ...prev.cash_rules, [key]: value } }));
    };

    const updateBand = (idx, patch) => {
        setConfig((prev) => {
            const bands = [...(prev.capital_allocation?.score_bands || [])];
            bands[idx] = { ...bands[idx], ...patch };
            return { ...prev, capital_allocation: { ...prev.capital_allocation, score_bands: bands } };
        });
    };

    const updateExitRule = useCallback((ruleKey, patch) => {
        setConfig((prev) => ({
            ...prev,
            exit_strategy: {
                ...prev.exit_strategy,
                rules: (prev.exit_strategy?.rules || []).map((rule) => (
                    rule.key === ruleKey ? { ...rule, ...patch } : rule
                )),
            },
        }));
    }, []);

    const exitColumns = useMemo(() => [
        {
            id: 'enabled',
            accessorKey: 'enabled',
            header: () => <span className="ps-3">On</span>,
            size: 88,
            minSize: 72,
            maxSize: 120,
            enableSorting: false,
            cell: ({ row }) => (
                <div className="ps-3">
                    <StrategySwitch
                        id={`exit-rule-${row.original.key}`}
                        checked={Boolean(row.original.enabled)}
                        disabled={!config.exit_strategy?.enabled}
                        onChange={(enabled) => updateExitRule(row.original.key, { enabled })}
                        ariaLabel={`Enable ${row.original.display_name || row.original.key}`}
                    />
                </div>
            ),
        },
        {
            id: 'rule',
            accessorFn: (row) => row.display_name || row.key,
            header: 'Rule',
            size: 360,
            minSize: 200,
            cell: ({ row }) => (
                <>
                    <div className="fw-semibold">{row.original.display_name || row.original.key}</div>
                    <div className="text-muted small">{row.original.description}</div>
                </>
            ),
        },
        {
            id: 'value',
            header: 'Value',
            size: 260,
            minSize: 180,
            maxSize: 360,
            enableSorting: false,
            cell: ({ row }) => {
                const rule = row.original;
                const disabled = !config.exit_strategy?.enabled;
                if (rule.key === 'screener_exit') {
                    const currentId = rule.screener_id != null ? String(rule.screener_id) : '';
                    return (
                        <select
                            className="form-select form-select-sm"
                            value={currentId}
                            disabled={disabled || !rule.enabled}
                            onChange={(e) => {
                                const id = e.target.value ? Number(e.target.value) : null;
                                const scr = availableScreeners.find((s) => Number(s.id) === Number(id));
                                updateExitRule(rule.key, {
                                    screener_id: id,
                                    screener_name: scr?.name || null,
                                });
                            }}
                            aria-label="Exit screener"
                        >
                            <option value="">Select screener…</option>
                            {availableScreeners.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}{s.is_factory ? ' (factory)' : ''}
                                </option>
                            ))}
                        </select>
                    );
                }
                if (rule.value != null || rule.atr_multiple != null) {
                    return (
                        <NumberInput
                            className="lido-number-input--compact"
                            buttonVariant="secondary"
                            step="1"
                            allowDecimals
                            disabled={disabled || !rule.enabled}
                            value={rule.atr_multiple ?? rule.value ?? ''}
                            onChange={(e) => {
                                const v = Number(e.target.value);
                                if (rule.key === 'atr_stop') updateExitRule(rule.key, { atr_multiple: v });
                                else updateExitRule(rule.key, { value: v });
                            }}
                            aria-label={`Value for ${rule.display_name || rule.key}`}
                        />
                    );
                }
                if (rule.params?.period != null) {
                    return (
                        <NumberInput
                            className="lido-number-input--compact"
                            buttonVariant="secondary"
                            step="1"
                            allowDecimals={false}
                            disabled={disabled || !rule.enabled}
                            value={rule.params.period}
                            onChange={(e) => updateExitRule(rule.key, {
                                params: { ...rule.params, period: Number(e.target.value) },
                            })}
                            aria-label={`Period for ${rule.display_name || rule.key}`}
                        />
                    );
                }
                return <span className="text-muted small">—</span>;
            },
        },
    ], [availableScreeners, config.exit_strategy?.enabled, updateExitRule]);

    const updateEligibility = useCallback((screenerId, patch) => {
        setConfig((prev) => ({
            ...prev,
            eligibility_sources: (prev.eligibility_sources || []).map((row) => (
                Number(row.screener_id) === Number(screenerId) ? { ...row, ...patch } : row
            )),
        }));
    }, []);

    const removeEligibility = useCallback((screenerId) => {
        setConfig((prev) => ({
            ...prev,
            eligibility_sources: (prev.eligibility_sources || []).filter((row) => Number(row.screener_id) !== Number(screenerId)),
        }));
    }, []);

    const eligibilityColumns = useMemo(() => [
        {
            id: 'enabled',
            accessorKey: 'enabled',
            header: 'On',
            size: 72,
            minSize: 64,
            maxSize: 100,
            enableSorting: false,
            cell: ({ row }) => (
                <StrategySwitch
                    id={`elig-on-${row.original.screener_id}`}
                    checked={Boolean(row.original.enabled)}
                    onChange={(enabled) => updateEligibility(row.original.screener_id, { enabled })}
                    ariaLabel={`Enable ${row.original.screener_name || `Screener #${row.original.screener_id}`}`}
                />
            ),
        },
        {
            id: 'screener',
            accessorFn: (row) => row.screener_name || `Screener #${row.screener_id}`,
            header: 'Screener',
            size: 280,
            minSize: 160,
            cell: ({ row }) => (
                <>
                    <div className="fw-semibold">{row.original.screener_name || `Screener #${row.original.screener_id}`}</div>
                    <div className="text-muted small">{row.original.description || '—'}</div>
                </>
            ),
        },
        {
            id: 'priority',
            accessorKey: 'priority',
            header: 'Priority',
            size: 200,
            minSize: 160,
            maxSize: 320,
            cell: ({ row }) => (
                <NumberInput
                    className="lido-number-input--compact"
                    buttonVariant="secondary"
                    step="1"
                    min="1"
                    allowDecimals={false}
                    value={row.original.priority ?? 1}
                    onChange={(e) => updateEligibility(row.original.screener_id, { priority: Number(e.target.value) })}
                    aria-label={`Priority for ${row.original.screener_name || `Screener #${row.original.screener_id}`}`}
                />
            ),
        },
        {
            id: 'conditions',
            accessorKey: 'condition_count',
            header: 'Conditions',
            size: 160,
            minSize: 120,
            enableSorting: false,
            cell: ({ row }) => {
                const count = row.original.condition_count;
                const label = count != null
                    ? `${count} condition${Number(count) === 1 ? '' : 's'}`
                    : 'Conditions';
                return (
                    <Link
                        className="btn btn-link btn-sm px-0"
                        to={`/screeners/${row.original.screener_id}`}
                        title="Open screener"
                    >
                        {label}
                        <span className="ms-1" aria-hidden="true">↗</span>
                        <span className="visually-hidden"> (opens Screener page)</span>
                    </Link>
                );
            },
        },
        {
            id: 'actions',
            header: '',
            size: 110,
            minSize: 96,
            maxSize: 140,
            enableSorting: false,
            meta: { columnMenuLabel: 'Actions' },
            cell: ({ row }) => (
                <div className="text-end">
                    <button
                        type="button"
                        className="btn btn-outline-danger btn-sm"
                        onClick={() => removeEligibility(row.original.screener_id)}
                    >
                        Remove
                    </button>
                </div>
            ),
        },
    ], [removeEligibility, updateEligibility]);

    const addEligibility = () => {
        const id = Number(addScreenerId);
        if (!id || assignedIds.has(id)) return;
        const scr = availableScreeners.find((s) => Number(s.id) === id);
        setConfig((prev) => ({
            ...prev,
            eligibility_sources: [
                ...(prev.eligibility_sources || []),
                {
                    screener_id: id,
                    screener_name: scr?.name || `Screener #${id}`,
                    description: scr?.description || '',
                    enabled: true,
                    priority: (prev.eligibility_sources || []).length + 1,
                    display_order: (prev.eligibility_sources || []).length,
                    condition_count: null,
                },
            ],
        }));
        setAddScreenerId('');
    };

    if (loading) {
        return <div className="container-fluid py-3 text-muted">Loading strategy…</div>;
    }

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Strategy</h1>
                    <p className="text-muted small mb-1">
                        Strategy is a set of configurations. Based on these settings, recommended stocks appear on the{' '}
                        <Link to="/recommendations">Recommendations</Link> tab after you run the decision pipeline.
                    </p>
                    <p className="text-muted small mb-0">
                        Screeners select eligible stocks. Strategy scores, allocates, and exits — it does not redefine eligibility rules.
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2">
                    <Link className="btn btn-outline-secondary btn-sm" to="/screeners">Screeners</Link>
                    <Link className="btn btn-outline-secondary btn-sm" to="/recommendations">Recommendations</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load}>Refresh</button>
                    <button type="button" className="btn btn-primary btn-sm" onClick={save} disabled={saving}>
                        {saving ? 'Saving…' : 'Save'}
                    </button>
                </div>
            </div>

            <div className="card mb-3">
                <div className="card-body py-3">
                    <div className="row g-2 align-items-start">
                        <div className="col-md-3">
                            <div className="text-muted small">Strategy Name</div>
                            <div className="fw-semibold">{meta.name}</div>
                        </div>
                        <div className="col-md-3">
                            <div className="text-muted small">Last Modified</div>
                            <div className="fw-semibold">{meta.modified_at ? new Date(meta.modified_at).toLocaleString() : '—'}</div>
                        </div>
                        <div className="col-md-3">
                            <div className="text-muted small">Eligibility sources</div>
                            <div className="fw-semibold">
                                {(config.eligibility_sources || []).filter((s) => s.enabled).map((s) => s.screener_name).join(', ') || 'None'}
                            </div>
                        </div>
                        <div className="col-md-2">
                            <div className="text-muted small">Weight total</div>
                            <div className={`fw-semibold${weightsValid ? '' : ' text-warning'}`}>
                                {Number(weightTotal.toFixed(2))}
                                {weightsValid ? ' (valid)' : ' (auto-normalises on save)'}
                            </div>
                        </div>
                        <div className="col-md-2">
                            <div className="text-muted small">Exit strategy</div>
                            <div className="fw-semibold">{config.exit_strategy?.enabled ? 'Enabled' : 'Disabled'}</div>
                        </div>
                        <div className="col-md-2">
                            <div className="text-muted small">Market gates</div>
                            <div className="fw-semibold">{config.market_gates?.enabled ? 'Enabled' : 'Disabled'}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="btn-group flex-wrap mb-3" role="group">
                {SECTIONS.map((s) => (
                    <button key={s.id} type="button" className={`btn btn-sm ${section === s.id ? 'btn-primary' : 'btn-outline-primary'}`} onClick={() => setSection(s.id)}>
                        {s.label}
                    </button>
                ))}
            </div>

            {section === 'general' && (
                <div className="card card-body row g-3">
                    <div className="col-md-6">
                        <label className="form-label" htmlFor="strat-name">Name</label>
                        <input id="strat-name" className="form-control" value={meta.name} onChange={(e) => setMeta({ ...meta, name: e.target.value })} />
                    </div>
                    <div className="col-12">
                        <label className="form-label" htmlFor="strat-desc">Description</label>
                        <textarea id="strat-desc" className="form-control" rows={3} value={meta.description} onChange={(e) => setMeta({ ...meta, description: e.target.value })} />
                    </div>
                    <p className="col-12 text-muted small mb-0">
                        Each portfolio has one strategy. It starts as Momentum with Minervini Trend Template eligibility; edit any tab and Save.
                    </p>
                </div>
            )}

            {section === 'eligibility' && (
                <div className="d-flex flex-column gap-3">
                    <div className="alert alert-info py-2 mb-0">
                        Eligibility comes only from Screeners. Edit conditions in the <Link to="/screeners">Screener module</Link> — not here.
                        A stock is eligible if it passes <strong>any</strong> enabled Screener (union).
                    </div>
                    <div className="card">
                        <div className="card-body">
                            <div className="row g-2 align-items-end">
                                <div className="col-md-8">
                                    <label className="form-label">Add Screener</label>
                                    <select className="form-select" value={addScreenerId} onChange={(e) => setAddScreenerId(e.target.value)}>
                                        <option value="">Select a screener…</option>
                                        {availableScreeners
                                            .filter((s) => !assignedIds.has(Number(s.id)))
                                            .map((s) => (
                                                <option key={s.id} value={s.id}>{s.name}{s.is_factory ? ' (factory)' : ''}</option>
                                            ))}
                                    </select>
                                </div>
                                <div className="col-md-4">
                                    <button type="button" className="btn btn-outline-primary" onClick={addEligibility} disabled={!addScreenerId}>Add</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <DataTableCard
                        title="Assigned Screeners"
                        columns={eligibilityColumns}
                        data={config.eligibility_sources || []}
                        storageKey="strategy-eligibility-v1"
                        emptyMessage="No Screeners assigned. Assign at least one eligibility source for production use."
                        defaultColumnOrder={['enabled', 'screener', 'priority', 'conditions', 'actions']}
                        initialSorting={[{ id: 'priority', desc: false }]}
                        enableColumnResizing
                    />
                </div>
            )}

            {section === 'scoring' && (
                <div className="d-flex flex-column gap-3">
                    <div className={`alert ${weightsValid ? 'alert-success' : 'alert-warning'} py-2 mb-0`}>
                        <strong>Enabled weight total: {Number(weightTotal.toFixed(2))}</strong>
                        {weightsValid
                            ? ' — equals 100.'
                            : ' — will auto-normalise to 100 on Save (relative proportions kept).'}
                        {!weightsValid && (
                            <button
                                type="button"
                                className="btn btn-sm btn-outline-secondary ms-2"
                                onClick={() => setConfig((prev) => ({
                                    ...prev,
                                    indicators: redistributeEnabledWeights(prev.indicators || []),
                                }))}
                            >
                                Normalise now
                            </button>
                        )}
                    </div>
                    <p className="text-muted small mb-0">Only eligible Screener candidates are scored. Scoring factors are not eligibility filters.</p>
                    {CATEGORY_ORDER.filter((cat) => grouped[cat]?.length).map((category) => (
                        <DataTableCard
                            key={category}
                            title={<ScoringCategoryTitle category={category} />}
                            columns={scoringColumns}
                            data={grouped[category]}
                            storageKey="strategy-scoring-v1"
                            emptyMessage="No factors in this category."
                            defaultColumnOrder={['enabled', 'factor', 'weight', 'minimum', 'maximum', 'parameters']}
                            enableColumnResizing
                            bodyClassName="p-0"
                        />
                    ))}
                </div>
            )}

            {section === 'thresholds' && (
                <div className="card card-body">
                    <p className="text-muted small mb-3">
                        These cutoffs are compared against the strategy&apos;s <strong>overall score (0–100)</strong> —
                        the weighted blend of enabled Scoring Model factors for each eligible stock.
                        Higher thresholds open / increase positions; lower thresholds reduce / exit.
                        Watch marks ideas that are interesting but not strong enough for a funded trade action.
                    </p>
                    <div className="row g-3">
                        {[
                            ['minimum_overall_score', 'Minimum Overall Score'],
                            ['open_position', 'Open Position'],
                            ['increase_position', 'Increase Position'],
                            ['reduce_position', 'Reduce Position'],
                            ['exit_position', 'Exit Position'],
                            ['watch', 'Watch'],
                        ].map(([key, label]) => (
                            <div className="col-md-4" key={key}>
                                <label className="form-label">{label}</label>
                                <NumberInput
                                    step="1"
                                    allowDecimals={false}
                                    buttonVariant="secondary"
                                    value={config.thresholds?.[key] ?? ''}
                                    onChange={(e) => updateThreshold(key, e.target.value)}
                                />
                                <div className="form-text">Score points (0–100)</div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {section === 'portfolio' && (
                <div className="card card-body">
                    <div className="row g-3">
                        {[
                            ['max_position_size_pct', 'Maximum Position Size %'],
                            ['min_position_size_pct', 'Minimum Position Size %'],
                            ['max_cash_deployment_pct', 'Maximum Cash Deployment %'],
                            ['min_cash_reserve_pct', 'Minimum Cash Reserve %'],
                            ['max_new_positions_per_cycle', 'Maximum New Positions'],
                            ['max_exposure_per_stock_pct', 'Maximum Exposure Per Stock %'],
                        ].map(([key, label]) => (
                            <div className="col-md-4" key={key}>
                                <label className="form-label">{label}</label>
                                <NumberInput
                                    step="1"
                                    allowDecimals={false}
                                    buttonVariant="secondary"
                                    value={config.portfolio_rules?.[key] ?? ''}
                                    onChange={(e) => updatePortfolioRule(key, e.target.value)}
                                />
                            </div>
                        ))}
                        <div className="col-12"><hr /><div className="fw-semibold small">Behaviour</div></div>
                        {[
                            ['allow_increase_position', 'Allow increase position'],
                            ['allow_partial_exit', 'Allow partial exit'],
                            ['allow_averaging_up', 'Allow averaging up'],
                            ['allow_averaging_down', 'Allow averaging down'],
                        ].map(([key, label]) => (
                            <div className="col-md-6" key={key}>
                                <StrategySwitch
                                    id={`beh-${key}`}
                                    checked={Boolean(config.recommendation_behaviour?.[key])}
                                    onChange={(enabled) => updateBehaviour(key, enabled)}
                                    label={label}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {section === 'allocation' && (
                <div className="card card-body">
                    <div className="row g-3 mb-3">
                        <div className="col-md-4">
                            <label className="form-label">Allocation method</label>
                            <select className="form-select" value={config.capital_allocation?.strategy || 'proportional'} onChange={(e) => setConfig((prev) => ({ ...prev, capital_allocation: { ...prev.capital_allocation, strategy: e.target.value } }))}>
                                <option value="proportional">Proportional by Score</option>
                                <option value="simple_ranking">Simple ranking</option>
                                <option value="equal_weight">Equal weight</option>
                            </select>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label">Tie-breaking</label>
                            <select className="form-select" value={config.capital_allocation?.tie_break || 'highest_score'} onChange={(e) => setConfig((prev) => ({ ...prev, capital_allocation: { ...prev.capital_allocation, tie_break: e.target.value } }))}>
                                <option value="highest_score">Higher Overall Score</option>
                                <option value="highest_relative_strength">Highest relative strength</option>
                                <option value="highest_momentum">Highest momentum</option>
                                <option value="highest_breakout">Highest breakout</option>
                            </select>
                        </div>
                    </div>
                    <h2 className="h6">Score bands</h2>
                    <table className="table table-sm">
                        <thead><tr><th>Min</th><th>Max</th><th>Allocation %</th></tr></thead>
                        <tbody>
                            {(config.capital_allocation?.score_bands || []).map((band, idx) => (
                                <tr key={idx}>
                                    <td>
                                        <NumberInput
                                            step="1"
                                            allowDecimals={false}
                                            buttonVariant="secondary"
                                            value={band.min ?? ''}
                                            onChange={(e) => updateBand(idx, { min: Number(e.target.value) })}
                                        />
                                    </td>
                                    <td>
                                        <NumberInput
                                            step="1"
                                            allowDecimals={false}
                                            buttonVariant="secondary"
                                            value={band.max ?? ''}
                                            onChange={(e) => updateBand(idx, { max: Number(e.target.value) })}
                                        />
                                    </td>
                                    <td>
                                        <NumberInput
                                            step="1"
                                            allowDecimals={false}
                                            buttonVariant="secondary"
                                            value={band.allocation_pct ?? ''}
                                            onChange={(e) => updateBand(idx, { allocation_pct: Number(e.target.value) })}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {section === 'exit' && (
                <div className="d-flex flex-column gap-3">
                    <div className="card card-body py-3">
                        <StrategySwitch
                            id="exit-enabled"
                            className="mb-2"
                            checked={Boolean(config.exit_strategy?.enabled)}
                            onChange={(enabled) => setConfig((prev) => ({
                                ...prev,
                                exit_strategy: { ...prev.exit_strategy, enabled },
                            }))}
                            label="Exit strategy enabled"
                        />
                        <p className="text-muted small mb-0">
                            Exit rules use Evaluation facts on existing holdings. Enable <strong>Screener Exit</strong> and pick a screener —
                            any open holding that appears in that screener&apos;s latest results gets an Exit recommendation.
                            Eligibility condition trees still live in the Screener module.
                        </p>
                    </div>
                    <DataTableCard
                        title="Exit rules"
                        columns={exitColumns}
                        data={config.exit_strategy?.rules || []}
                        storageKey="strategy-exit-v1"
                        emptyMessage="No exit rules configured."
                        defaultColumnOrder={['enabled', 'rule', 'value']}
                        enableColumnResizing
                        bodyClassName="p-0"
                    />
                </div>
            )}

            {section === 'market' && (
                <div className="card card-body">
                    <p className="text-muted small">
                        Optional gates consume Market Analysis Engine outputs. Recommendation Engine never recalculates market metrics.
                        When gates are off, sentiment / phase / risk cutoffs are ignored during recommendation generation.
                    </p>
                    <StrategySwitch
                        id="market-gates-enabled"
                        className="mb-3"
                        checked={Boolean(config.market_gates?.enabled)}
                        onChange={(enabled) => setConfig((prev) => ({
                            ...prev,
                            market_gates: { ...prev.market_gates, enabled },
                        }))}
                        label="Enable market gates"
                    />
                    {(() => {
                        const gatesDisabled = !config.market_gates?.enabled;
                        return (
                            <div className={`row g-3${gatesDisabled ? ' opacity-75' : ''}`}>
                                <div className="col-md-4">
                                    <label className="form-label" htmlFor="market-min-sentiment">Min sentiment</label>
                                    <NumberInput
                                        id="market-min-sentiment"
                                        step="1"
                                        allowDecimals={false}
                                        buttonVariant="secondary"
                                        disabled={gatesDisabled}
                                        value={config.market_gates?.min_sentiment ?? ''}
                                        onChange={(e) => setConfig((prev) => ({
                                            ...prev,
                                            market_gates: { ...prev.market_gates, min_sentiment: Number(e.target.value) },
                                        }))}
                                    />
                                </div>
                                <div className="col-md-4">
                                    <label className="form-label" htmlFor="market-max-risk">Max risk (raw)</label>
                                    <NumberInput
                                        id="market-max-risk"
                                        step="1"
                                        allowDecimals={false}
                                        buttonVariant="secondary"
                                        disabled={gatesDisabled}
                                        value={config.market_gates?.max_risk_raw ?? ''}
                                        onChange={(e) => setConfig((prev) => ({
                                            ...prev,
                                            market_gates: { ...prev.market_gates, max_risk_raw: Number(e.target.value) },
                                        }))}
                                    />
                                </div>
                                <div className="col-12">
                                    <label className="form-label">Allowed phases</label>
                                    <div className="d-flex flex-wrap gap-3">
                                        {MARKET_PHASES.map((phase) => {
                                            const selected = (config.market_gates?.allowed_phases || []).includes(phase);
                                            return (
                                                <StrategySwitch
                                                    key={phase}
                                                    id={`phase-${phase}`}
                                                    checked={selected}
                                                    disabled={gatesDisabled}
                                                    onChange={(enabled) => setConfig((prev) => {
                                                        const current = prev.market_gates?.allowed_phases || [];
                                                        const next = enabled
                                                            ? [...new Set([...current, phase])]
                                                            : current.filter((p) => p !== phase);
                                                        return {
                                                            ...prev,
                                                            market_gates: { ...prev.market_gates, allowed_phases: next },
                                                        };
                                                    })}
                                                    label={phase}
                                                />
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        );
                    })()}
                </div>
            )}

            {section === 'cash' && (
                <div className="card card-body">
                    <div className="row g-3">
                        {[
                            ['reservations_enabled', 'Cash reservations enabled'],
                            ['reserve_on_approval', 'Reserve on approval'],
                            ['release_on_execution', 'Release on execution'],
                            ['release_on_cancellation', 'Release on cancellation'],
                            ['release_on_expiry', 'Release on expiry'],
                        ].map(([key, label]) => (
                            <div className="col-md-6" key={key}>
                                <StrategySwitch
                                    id={`cash-${key}`}
                                    checked={Boolean(config.cash_rules?.[key])}
                                    onChange={(enabled) => updateCashRule(key, enabled)}
                                    label={label}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
