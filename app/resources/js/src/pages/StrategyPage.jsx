import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import NumberInput from '../components/NumberInput';
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
    { id: 'summary', label: 'Summary' },
];

const CATEGORY_ORDER = ['Momentum', 'Trend', 'Volume', 'Market', 'Risk'];

const MARKET_PHASES = [
    'Strong Bull', 'Bull', 'Consolidation', 'Pullback', 'Correction', 'Bear', 'Capitulation', 'Recovery',
];

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
        version: payload.version,
        version_label: payload.version_label || (payload.version != null ? `${payload.version}.0` : null),
        modified_at: payload.modified_at,
        status: payload.status,
        is_factory: Boolean(payload.is_factory),
        is_protected: Boolean(payload.is_protected ?? payload.is_factory),
        enabled_indicator_count: payload.enabled_indicator_count ?? payload.enabled_factor_count,
        weight_total: payload.weight_total,
        weights_valid: payload.weights_valid,
    });
    setConfig({
        ...emptyConfig(),
        ...(payload.config || {}),
        eligibility_sources: payload.eligibility_sources || payload.config?.eligibility_sources || [],
        indicators: payload.scoring_model || payload.indicators || payload.config?.indicators || payload.factors || [],
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
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [duplicating, setDuplicating] = useState(false);
    const [availableScreeners, setAvailableScreeners] = useState([]);
    const [meta, setMeta] = useState({
        name: '', description: '', version: null, version_label: null,
        modified_at: null, status: null, is_factory: false, is_protected: false,
    });
    const [config, setConfig] = useState(emptyConfig);
    const [changeNotes, setChangeNotes] = useState('');
    const [addScreenerId, setAddScreenerId] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [{ data }, screenersRes] = await Promise.all([
                api.get('/v1/strategy'),
                api.get('/screeners').catch(() => ({ data: [] })),
            ]);
            applyPayload(data?.data || {}, setMeta, setConfig);
            const list = Array.isArray(screenersRes?.data?.data)
                ? screenersRes.data.data
                : (Array.isArray(screenersRes?.data) ? screenersRes.data : []);
            setAvailableScreeners(list);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to load strategy', 'danger');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

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
        if (!weightsValid) {
            showToast(`Enabled weights must sum to 100 (currently ${weightTotal}).`, 'danger');
            setSection('scoring');
            return;
        }
        setSaving(true);
        try {
            const { data } = await api.put('/v1/strategy', {
                name: meta.name,
                description: meta.description,
                change_notes: changeNotes || undefined,
                config: {
                    ...config,
                    indicators: config.indicators,
                    eligibility_sources: config.eligibility_sources,
                    exit_strategy: config.exit_strategy,
                    market_gates: config.market_gates,
                },
            });
            const payload = data?.data || {};
            showToast(
                meta.is_factory
                    ? `Factory preserved. Custom strategy saved as ${payload.version_label || payload.version}`
                    : `Strategy saved as version ${payload.version_label || payload.version}`,
                'success',
            );
            setChangeNotes('');
            applyPayload(payload, setMeta, setConfig);
        } catch (e) {
            const errors = e?.response?.data?.errors;
            showToast(errors?.indicators?.[0] || e?.response?.data?.message || e?.response?.data?.error?.message || e.message || 'Save failed', 'danger');
        } finally {
            setSaving(false);
        }
    };

    const duplicate = async () => {
        setDuplicating(true);
        try {
            const { data } = await api.post('/v1/strategy/duplicate', {
                name: meta.is_factory ? 'Momentum Strategy (Custom)' : `${meta.name} (Copy)`,
            });
            applyPayload(data?.data || {}, setMeta, setConfig);
            showToast('Strategy duplicated. Customise the copy freely.', 'success');
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Duplicate failed', 'danger');
        } finally {
            setDuplicating(false);
        }
    };

    const updateIndicator = (key, patch) => {
        setConfig((prev) => ({
            ...prev,
            indicators: (prev.indicators || []).map((row) => (row.key === key ? { ...row, ...patch } : row)),
        }));
    };

    const updateIndicatorParam = (key, paramKey, value) => {
        setConfig((prev) => ({
            ...prev,
            indicators: (prev.indicators || []).map((row) => {
                if (row.key !== key) return row;
                return { ...row, parameters: { ...(row.parameters || {}), [paramKey]: value } };
            }),
        }));
    };

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

    const updateExitRule = (idx, patch) => {
        setConfig((prev) => {
            const rules = [...(prev.exit_strategy?.rules || [])];
            rules[idx] = { ...rules[idx], ...patch };
            return { ...prev, exit_strategy: { ...prev.exit_strategy, rules } };
        });
    };

    const updateEligibility = (screenerId, patch) => {
        setConfig((prev) => ({
            ...prev,
            eligibility_sources: (prev.eligibility_sources || []).map((row) => (
                Number(row.screener_id) === Number(screenerId) ? { ...row, ...patch } : row
            )),
        }));
    };

    const removeEligibility = (screenerId) => {
        setConfig((prev) => ({
            ...prev,
            eligibility_sources: (prev.eligibility_sources || []).filter((row) => Number(row.screener_id) !== Number(screenerId)),
        }));
    };

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
                    <p className="text-muted small mb-0">
                        Screeners select eligible stocks. Strategy scores, allocates, and exits — it does not redefine eligibility rules.
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2">
                    <Link className="btn btn-outline-secondary btn-sm" to="/screeners">Screeners</Link>
                    <Link className="btn btn-outline-secondary btn-sm" to="/recommendations">Recommendations</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load}>Refresh</button>
                    <button type="button" className="btn btn-outline-primary btn-sm" onClick={duplicate} disabled={duplicating}>
                        {duplicating ? 'Duplicating…' : 'Duplicate Strategy'}
                    </button>
                    <button type="button" className="btn btn-primary btn-sm" onClick={save} disabled={saving || !weightsValid}>
                        {saving ? 'Saving…' : (meta.is_factory ? 'Save as custom strategy' : 'Save new version')}
                    </button>
                </div>
            </div>

            <div className="card mb-3">
                <div className="card-body py-3">
                    <div className="row g-2 align-items-center">
                        <div className="col-md-3">
                            <div className="text-muted small">Strategy Name</div>
                            <div className="fw-semibold">{meta.name}</div>
                        </div>
                        <div className="col-md-2">
                            <div className="text-muted small">Version</div>
                            <div className="fw-semibold">{meta.version_label || meta.version || '—'}</div>
                        </div>
                        <div className="col-md-2">
                            <div className="text-muted small">Factory Strategy</div>
                            <div>{meta.is_factory ? <span className="badge text-bg-info">Yes — shipped default</span> : <span className="badge text-bg-secondary">No</span>}</div>
                        </div>
                        <div className="col-md-2">
                            <div className="text-muted small">Current Status</div>
                            <div className="fw-semibold text-capitalize">{meta.status || '—'}</div>
                        </div>
                        <div className="col-md-3">
                            <div className="text-muted small">Last Modified</div>
                            <div className="fw-semibold">{meta.modified_at ? new Date(meta.modified_at).toLocaleString() : '—'}</div>
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
                    <div className="col-12">
                        <label className="form-label" htmlFor="strat-notes">Change notes</label>
                        <input id="strat-notes" className="form-control" value={changeNotes} onChange={(e) => setChangeNotes(e.target.value)} />
                    </div>
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
                            <div className="row g-2 align-items-end mb-3">
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
                            {(config.eligibility_sources || []).length === 0 ? (
                                <p className="text-muted mb-0">No Screeners assigned. Assign at least one eligibility source for production use.</p>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th style={{ width: 70 }}>On</th>
                                                <th>Screener</th>
                                                <th style={{ width: 100 }}>Priority</th>
                                                <th>Conditions</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(config.eligibility_sources || []).map((src) => (
                                                <tr key={src.screener_id}>
                                                    <td>
                                                        <input type="checkbox" className="form-check-input" checked={Boolean(src.enabled)} onChange={(e) => updateEligibility(src.screener_id, { enabled: e.target.checked })} />
                                                    </td>
                                                    <td>
                                                        <div className="fw-semibold">{src.screener_name || `Screener #${src.screener_id}`}</div>
                                                        <div className="text-muted small">{src.description || '—'}</div>
                                                    </td>
                                                    <td>
                                                        <NumberInput step="1" min="1" allowDecimals={false} value={src.priority ?? 1} onChange={(e) => updateEligibility(src.screener_id, { priority: Number(e.target.value) })} />
                                                    </td>
                                                    <td>
                                                        <Link className="btn btn-link btn-sm px-0" to={`/screeners/${src.screener_id}`}>
                                                            View conditions{src.condition_count != null ? ` (${src.condition_count})` : ''}
                                                        </Link>
                                                    </td>
                                                    <td className="text-end">
                                                        <button type="button" className="btn btn-outline-danger btn-sm" onClick={() => removeEligibility(src.screener_id)}>Remove</button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {section === 'scoring' && (
                <div className="d-flex flex-column gap-3">
                    <div className={`alert ${weightsValid ? 'alert-success' : 'alert-warning'} py-2 mb-0`}>
                        <strong>Enabled weight total: {weightTotal}</strong>
                        {weightsValid ? ' — valid (must equal 100).' : ' — must equal 100 before save. Weights are not normalised automatically.'}
                    </div>
                    <p className="text-muted small mb-0">Only eligible Screener candidates are scored. Scoring factors are not eligibility filters.</p>
                    {CATEGORY_ORDER.filter((cat) => grouped[cat]?.length).map((category) => (
                        <div className="card" key={category}>
                            <div className="card-header fw-semibold">{category}</div>
                            <div className="card-body p-0">
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th style={{ width: 70 }}>On</th>
                                                <th>Factor</th>
                                                <th className="text-end" style={{ width: 110 }}>Weight</th>
                                                <th className="text-end" style={{ width: 110 }}>Min</th>
                                                <th className="text-end" style={{ width: 110 }}>Max</th>
                                                <th>Parameters</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {grouped[category].map((ind) => (
                                                <tr key={ind.key}>
                                                    <td>
                                                        <input type="checkbox" className="form-check-input" checked={Boolean(ind.enabled)} onChange={(e) => updateIndicator(ind.key, { enabled: e.target.checked })} />
                                                    </td>
                                                    <td>
                                                        <div className="fw-semibold">{ind.display_name}</div>
                                                        <div className="text-muted small">{ind.description}</div>
                                                    </td>
                                                    <td>
                                                        <NumberInput step="1" min="0" allowDecimals={false} value={ind.weight ?? ''} onChange={(e) => updateIndicator(ind.key, { weight: e.target.value === '' ? 0 : Number(e.target.value) })} />
                                                    </td>
                                                    <td>
                                                        <NumberInput step="1" min="0" max="100" allowDecimals={false} value={ind.minimum ?? ''} onChange={(e) => updateIndicator(ind.key, { minimum: e.target.value === '' ? null : Number(e.target.value) })} />
                                                    </td>
                                                    <td>
                                                        {ind.supports_maximum || ind.key === 'risk_score' ? (
                                                            <NumberInput step="1" min="0" max="100" allowDecimals={false} value={ind.maximum ?? ''} onChange={(e) => updateIndicator(ind.key, { maximum: e.target.value === '' ? null : Number(e.target.value) })} />
                                                        ) : <span className="text-muted small">—</span>}
                                                    </td>
                                                    <td>
                                                        {Object.keys(ind.parameters || {}).length === 0 ? <span className="text-muted small">—</span> : (
                                                            <div className="d-flex flex-wrap gap-2">
                                                                {Object.entries(ind.parameters || {}).map(([paramKey, paramVal]) => (
                                                                    <div key={paramKey} style={{ minWidth: 100 }}>
                                                                        <div className="form-text mb-0 text-capitalize">{paramKey.replaceAll('_', ' ')}</div>
                                                                        <input
                                                                            className="form-control form-control-sm"
                                                                            value={paramVal ?? ''}
                                                                            onChange={(e) => {
                                                                                const raw = e.target.value;
                                                                                const num = Number(raw);
                                                                                updateIndicatorParam(ind.key, paramKey, raw !== '' && !Number.isNaN(num) && String(num) === raw ? num : raw);
                                                                            }}
                                                                        />
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {section === 'thresholds' && (
                <div className="card card-body">
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
                                <NumberInput step="1" allowDecimals={false} value={config.thresholds?.[key] ?? ''} onChange={(e) => updateThreshold(key, e.target.value)} />
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
                                <NumberInput step="1" allowDecimals={false} value={config.portfolio_rules?.[key] ?? ''} onChange={(e) => updatePortfolioRule(key, e.target.value)} />
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
                                <div className="form-check">
                                    <input className="form-check-input" type="checkbox" id={`beh-${key}`} checked={Boolean(config.recommendation_behaviour?.[key])} onChange={(e) => updateBehaviour(key, e.target.checked)} />
                                    <label className="form-check-label" htmlFor={`beh-${key}`}>{label}</label>
                                </div>
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
                                    <td><NumberInput step="1" allowDecimals={false} value={band.min ?? ''} onChange={(e) => updateBand(idx, { min: Number(e.target.value) })} /></td>
                                    <td><NumberInput step="1" allowDecimals={false} value={band.max ?? ''} onChange={(e) => updateBand(idx, { max: Number(e.target.value) })} /></td>
                                    <td><NumberInput step="1" allowDecimals={false} value={band.allocation_pct ?? ''} onChange={(e) => updateBand(idx, { allocation_pct: Number(e.target.value) })} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {section === 'exit' && (
                <div className="card card-body">
                    <div className="form-check mb-3">
                        <input className="form-check-input" type="checkbox" id="exit-enabled" checked={Boolean(config.exit_strategy?.enabled)} onChange={(e) => setConfig((prev) => ({ ...prev, exit_strategy: { ...prev.exit_strategy, enabled: e.target.checked } }))} />
                        <label className="form-check-label" htmlFor="exit-enabled">Exit strategy enabled</label>
                    </div>
                    <p className="text-muted small">Exit rules use Evaluation facts on existing holdings. Condition editing for eligibility remains in Screeners.</p>
                    <div className="table-responsive">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th style={{ width: 70 }}>On</th>
                                    <th>Rule</th>
                                    <th style={{ width: 120 }}>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(config.exit_strategy?.rules || []).map((rule, idx) => (
                                    <tr key={rule.key || idx}>
                                        <td>
                                            <input type="checkbox" className="form-check-input" checked={Boolean(rule.enabled)} onChange={(e) => updateExitRule(idx, { enabled: e.target.checked })} />
                                        </td>
                                        <td>
                                            <div className="fw-semibold">{rule.display_name || rule.key}</div>
                                            <div className="text-muted small">{rule.description}</div>
                                        </td>
                                        <td>
                                            {rule.value != null || rule.atr_multiple != null ? (
                                                <NumberInput
                                                    step="1"
                                                    allowDecimals
                                                    value={rule.atr_multiple ?? rule.value ?? ''}
                                                    onChange={(e) => {
                                                        const v = Number(e.target.value);
                                                        if (rule.key === 'atr_stop') updateExitRule(idx, { atr_multiple: v });
                                                        else updateExitRule(idx, { value: v });
                                                    }}
                                                />
                                            ) : (rule.params?.period != null ? (
                                                <NumberInput step="1" allowDecimals={false} value={rule.params.period} onChange={(e) => updateExitRule(idx, { params: { ...rule.params, period: Number(e.target.value) } })} />
                                            ) : <span className="text-muted small">—</span>)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {section === 'market' && (
                <div className="card card-body">
                    <p className="text-muted small">
                        Optional gates consume Market Analysis Engine outputs. Recommendation Engine never recalculates market metrics.
                    </p>
                    <div className="form-check mb-3">
                        <input
                            className="form-check-input"
                            type="checkbox"
                            id="market-gates-enabled"
                            checked={Boolean(config.market_gates?.enabled)}
                            onChange={(e) => setConfig((prev) => ({
                                ...prev,
                                market_gates: { ...prev.market_gates, enabled: e.target.checked },
                            }))}
                        />
                        <label className="form-check-label" htmlFor="market-gates-enabled">Enable market gates</label>
                    </div>
                    <div className="row g-3">
                        <div className="col-md-4">
                            <label className="form-label">Min sentiment</label>
                            <NumberInput
                                step="1"
                                allowDecimals={false}
                                value={config.market_gates?.min_sentiment ?? ''}
                                onChange={(e) => setConfig((prev) => ({
                                    ...prev,
                                    market_gates: { ...prev.market_gates, min_sentiment: Number(e.target.value) },
                                }))}
                            />
                        </div>
                        <div className="col-md-4">
                            <label className="form-label">Max risk (raw)</label>
                            <NumberInput
                                step="1"
                                allowDecimals={false}
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
                                        <div className="form-check" key={phase}>
                                            <input
                                                className="form-check-input"
                                                type="checkbox"
                                                id={`phase-${phase}`}
                                                checked={selected}
                                                onChange={(e) => setConfig((prev) => {
                                                    const current = prev.market_gates?.allowed_phases || [];
                                                    const next = e.target.checked
                                                        ? [...new Set([...current, phase])]
                                                        : current.filter((p) => p !== phase);
                                                    return {
                                                        ...prev,
                                                        market_gates: { ...prev.market_gates, allowed_phases: next },
                                                    };
                                                })}
                                            />
                                            <label className="form-check-label" htmlFor={`phase-${phase}`}>{phase}</label>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
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
                                <div className="form-check">
                                    <input className="form-check-input" type="checkbox" id={`cash-${key}`} checked={Boolean(config.cash_rules?.[key])} onChange={(e) => updateCashRule(key, e.target.checked)} />
                                    <label className="form-check-label" htmlFor={`cash-${key}`}>{label}</label>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {section === 'summary' && (
                <div className="card card-body">
                    <dl className="row mb-0">
                        <dt className="col-sm-3">Strategy Name</dt><dd className="col-sm-9">{meta.name}</dd>
                        <dt className="col-sm-3">Version</dt><dd className="col-sm-9">{meta.version_label || meta.version || '—'}</dd>
                        <dt className="col-sm-3">Factory</dt><dd className="col-sm-9">{meta.is_factory ? 'Yes' : 'No'}</dd>
                        <dt className="col-sm-3">Eligibility sources</dt>
                        <dd className="col-sm-9">{(config.eligibility_sources || []).filter((s) => s.enabled).map((s) => s.screener_name).join(', ') || 'None'}</dd>
                        <dt className="col-sm-3">Weight total</dt><dd className="col-sm-9">{weightTotal}{weightsValid ? ' (valid)' : ''}</dd>
                        <dt className="col-sm-3">Exit strategy</dt><dd className="col-sm-9">{config.exit_strategy?.enabled ? 'Enabled' : 'Disabled'}</dd>
                        <dt className="col-sm-3">Market gates</dt><dd className="col-sm-9">{config.market_gates?.enabled ? 'Enabled' : 'Disabled'}</dd>
                    </dl>
                </div>
            )}
        </div>
    );
}
