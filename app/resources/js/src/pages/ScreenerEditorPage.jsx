import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api from '../api';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { showToast } from '../toast';
import { formatRunListLabel, formatRunResultsHeading, lastRunSummaryText, operatorLabel, runStatsWarning } from '../components/screener/screenerTableHelpers';
import ScreenerRunsCompareTable from '../components/screener/ScreenerRunsCompareTable';
import NumberInput from '../components/NumberInput';
import ComboButton from '../components/ComboButton';
import { buildExplorerComparePath } from '../utils/explorerLinks';
import { resolveExternalStockUrl } from '../utils/externalStockLinks';

const WIDE_LAYOUT_QUERY = '(min-width: 1400px)';
const NAME_MAX_LENGTH = 120;
const DESCRIPTION_MAX_LENGTH = 500;
const DEFAULT_EXPLORER_BENCHMARK = 'NIFTY50';
const BACKTEST_SESSION_KEY = 'lido_screener_backtest_session';
const NAME_ALLOWED_RE = /^[\p{L}\p{N}\s\-._,&()\/:+#%'"]+$/u;
const DESCRIPTION_ALLOWED_RE = /^[\p{L}\p{N}\s\-._,&()\/:+#%'"?!]*$/u;

function getOrCreateBacktestSessionToken() {
    try {
        let token = sessionStorage.getItem(BACKTEST_SESSION_KEY);
        if (!token) {
            token = (typeof crypto !== 'undefined' && crypto.randomUUID)
                ? crypto.randomUUID()
                : `bt-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
            sessionStorage.setItem(BACKTEST_SESSION_KEY, token);
        }
        return token;
    } catch {
        return `bt-${Date.now()}`;
    }
}

function validationMessage(error) {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) return first;
    }
    return error?.response?.data?.message || 'Something went wrong.';
}

function defaultDefinition(meta) {
    const ema = meta?.indicators?.find((i) => i.id === 'ema');
    const fast = ema?.params?.find((p) => p.id === 'period')?.default ?? 50;
    return {
        root: {
            type: 'group',
            op: 'AND',
            children: [
                {
                    type: 'condition',
                    left: { indicator: 'ema', params: { period: fast } },
                    operator: 'gt',
                    weight_factor: 1,
                    right: { indicator: 'ema', params: { period: 200 } },
                },
            ],
        },
    };
}

function defaultForm(meta) {
    return {
        name: '',
        description: '',
        scope: 'holdings',
        watchlist_id: '',
        index_symbol: '',
        definition_json: defaultDefinition(meta),
        schedule_enabled: false,
        schedule_time: '18:30',
        schedule_days: [],
        telegram_enabled: false,
        is_enabled: true,
        is_shared: false,
        max_lookback: null,
    };
}

function defaultCondition(meta) {
    const ema = meta?.indicators?.find((i) => i.id === 'ema');
    const period = ema?.params?.find((p) => p.id === 'period')?.default ?? 50;
    return {
        type: 'condition',
        left: { indicator: 'ema', params: { period } },
        operator: 'gt',
        weight_factor: 1,
        right: { type: 'constant', value: 0 },
    };
}

function defaultParams(indicatorMeta) {
    const params = {};
    (indicatorMeta?.params || []).forEach((p) => {
        params[p.id] = p.default;
    });
    return params;
}

function validateForm(form, watchlists, indexes) {
    const errors = {};
    const name = String(form?.name ?? '').trim();
    const description = String(form?.description ?? '').trim();
    const watchlistIds = new Set((watchlists || []).map((w) => Number(w.id)));
    const indexSymbols = new Set((indexes || []).map((i) => String(i.symbol || '').toUpperCase()));

    if (!name) {
        errors.name = 'Name is required.';
    } else if (name.length > NAME_MAX_LENGTH) {
        errors.name = `Name max ${NAME_MAX_LENGTH} characters.`;
    } else if (!NAME_ALLOWED_RE.test(name)) {
        errors.name = 'Name has unsupported characters.';
    }

    if (description.length > DESCRIPTION_MAX_LENGTH) {
        errors.description = `Description max ${DESCRIPTION_MAX_LENGTH} characters.`;
    } else if (description && !DESCRIPTION_ALLOWED_RE.test(description)) {
        errors.description = 'Description has unsupported characters.';
    }

    if (!form?.scope) {
        errors.scope = 'Scope is required.';
    }

    if (form?.scope === 'watchlist') {
        const watchlistId = Number(form.watchlist_id);
        if (!watchlistId) {
            errors.watchlist_id = 'Watchlist missing or was deleted. Select a watchlist to run this screener.';
        } else if (!watchlistIds.has(watchlistId)) {
            errors.watchlist_id = 'Selected watchlist no longer exists. Choose another watchlist.';
        }
    }

    if (form?.scope === 'index') {
        const indexSymbol = String(form.index_symbol || '').trim().toUpperCase();
        if (!indexSymbol) {
            errors.index_symbol = 'Select an index to run this screener.';
        } else if (indexSymbols.size > 0 && !indexSymbols.has(indexSymbol)) {
            errors.index_symbol = 'Selected index is not supported for constituents.';
        }
    }

    if (form?.schedule_enabled) {
        if (!/^\d{2}:\d{2}$/.test(String(form.schedule_time || ''))) {
            errors.schedule_time = 'Time must be in HH:mm format.';
        }
    }

    if (!form?.definition_json?.root) {
        errors.definition_json = 'At least one condition is required.';
    }

    return errors;
}

function CollapsibleSection({
    id,
    title,
    wideLayout,
    open,
    onToggle,
    children,
    sectionClassName = '',
}) {
    const sectionClass = `border rounded mb-3 ${sectionClassName}`.trim();

    if (wideLayout) {
        return (
            <div className={sectionClass}>
                <div className="px-3 py-2 border-bottom">
                    <h2 className="h6 mb-0">{title}</h2>
                </div>
                <div className="p-3">{children}</div>
            </div>
        );
    }

    return (
        <div className={sectionClass}>
            <div className="card-header p-0 border-0">
                <button
                    type="button"
                    id={`${id}-toggle`}
                    className="lido-collapsible-card-toggle"
                    onClick={onToggle}
                    aria-expanded={open}
                    aria-controls={`${id}-panel`}
                >
                    <span>{title}</span>
                    <span className="lido-collapsible-card-chevron" aria-hidden="true">
                        {open ? '▾' : '▸'}
                    </span>
                </button>
            </div>
            <div id={`${id}-panel`} className={`collapse${open ? ' show' : ''}`}>
                <div className="p-3">{children}</div>
            </div>
        </div>
    );
}

function OperandEditor({ value, onChange, meta, side, bare = false, leading = null }) {
    const isConstant = value?.type === 'constant';
    const indicators = meta?.indicators || [];
    const selected = indicators.find((i) => i.id === value?.indicator);
    const entities = side === 'left' ? (meta?.left_entities || []) : [];
    const entity = value?.entity || 'stock';

    const body = (
        <>
            {leading}
            {entities.length > 0 && (
                <div className="mb-2">
                    <select
                        className="form-select form-select-sm"
                        value={entity}
                        disabled={isConstant}
                        onChange={(e) => {
                            const next = e.target.value;
                            const { entity: _omit, ...rest } = value || {};
                            onChange(next === 'stock' ? rest : { ...rest, entity: next });
                        }}
                        aria-label="Left side entity"
                        title="Compute this side on the scanned stock or on an index (result set is always stocks)"
                    >
                        {entities.map((ent) => (
                            <option key={ent.id} value={ent.id}>{ent.label}</option>
                        ))}
                    </select>
                </div>
            )}
            <div className="btn-group btn-group-sm mb-2" role="group">
                <button
                    type="button"
                    className={`btn btn-outline-secondary ${!isConstant ? 'active' : ''}`}
                    onClick={() => onChange({
                        ...(side === 'left' && value?.entity && value.entity !== 'stock' ? { entity: value.entity } : {}),
                        indicator: value?.indicator || 'close',
                        params: value?.params || {},
                    })}
                >
                    Indicator
                </button>
                <button
                    type="button"
                    className={`btn btn-outline-secondary ${isConstant ? 'active' : ''}`}
                    onClick={() => onChange({ type: 'constant', value: Number(value?.value) || 0 })}
                >
                    Number
                </button>
            </div>
            {isConstant ? (
                <input
                    type="number"
                    className="form-control form-control-sm"
                    value={value?.value ?? 0}
                    onChange={(e) => onChange({ type: 'constant', value: Number(e.target.value) })}
                    aria-label={`${side} constant`}
                />
            ) : (
                <>
                    <select
                        className="form-select form-select-sm mb-2"
                        value={value?.indicator || 'close'}
                        onChange={(e) => {
                            const ind = indicators.find((i) => i.id === e.target.value);
                            onChange({
                                ...(value?.entity && value.entity !== 'stock' ? { entity: value.entity } : {}),
                                indicator: e.target.value,
                                params: defaultParams(ind),
                            });
                        }}
                    >
                        {indicators.map((ind) => (
                            <option key={ind.id} value={ind.id}>{ind.label}</option>
                        ))}
                    </select>
                    <div className="d-flex flex-wrap gap-2">
                        {(selected?.params || []).map((p) => (
                            <label key={p.id} className="small mb-0">
                                {p.label}
                                <input
                                    type="number"
                                    className="form-control form-control-sm"
                                    style={{ width: 88 }}
                                    min={p.min}
                                    max={p.max}
                                    step={p.step || 1}
                                    value={value?.params?.[p.id] ?? p.default}
                                    onChange={(e) => onChange({
                                        ...value,
                                        params: {
                                            ...(value.params || {}),
                                            [p.id]: Number(e.target.value),
                                        },
                                    })}
                                />
                            </label>
                        ))}
                    </div>
                    {selected?.min_bars != null && (
                        <div className="form-text">
                            At default params: ≥ {selected.min_bars} sessions
                            {' '}
                            (period min {meta?.param_min_period ?? 1}; requirement follows the periods you set)
                        </div>
                    )}
                </>
            )}
        </>
    );

    if (bare) {
        return body;
    }

    return (
        <div className="rounded p-2 lido-screener-operand">
            {body}
        </div>
    );
}

function ConditionNode({ node, onChange, onRemove, meta, depth }) {
    if (node.type === 'group') {
        const children = node.children || [];

        return (
            <div className="lido-screener-group mb-2" data-depth={depth}>
                <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <select
                        className="form-select form-select-sm"
                        style={{ width: 100 }}
                        value={node.op || 'AND'}
                        onChange={(e) => onChange({ ...node, op: e.target.value })}
                    >
                        <option value="AND">AND</option>
                        <option value="OR">OR</option>
                    </select>
                    <span className="small text-muted">group</span>
                    <div className="ms-auto d-flex gap-1">
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-primary"
                            onClick={() => onChange({
                                ...node,
                                children: [...children, defaultCondition(meta)],
                            })}
                        >
                            + Condition
                        </button>
                        {depth < (meta?.max_nesting || 4) && (
                            <button
                                type="button"
                                className="btn btn-sm btn-outline-secondary"
                                onClick={() => onChange({
                                    ...node,
                                    children: [...children, {
                                        type: 'group',
                                        op: 'OR',
                                        children: [defaultCondition(meta)],
                                    }],
                                })}
                            >
                                + Group
                            </button>
                        )}
                        {onRemove && (
                            <button type="button" className="btn btn-sm btn-outline-danger" onClick={onRemove}>
                                Remove
                            </button>
                        )}
                    </div>
                </div>
                {children.map((child, idx) => (
                    <ConditionNode
                        key={idx}
                        node={child}
                        meta={meta}
                        depth={depth + 1}
                        onChange={(next) => {
                            const nextChildren = [...children];
                            nextChildren[idx] = next;
                            onChange({ ...node, children: nextChildren });
                        }}
                        onRemove={children.length > 1 ? () => {
                            const nextChildren = children.filter((_, i) => i !== idx);
                            onChange({ ...node, children: nextChildren });
                        } : undefined}
                    />
                ))}
            </div>
        );
    }

    return (
        <div className="lido-screener-leaf mb-2" data-depth={depth}>
            <div className="row g-2 align-items-start">
                <div className="col-12 col-lg">
                    <OperandEditor
                        side="left"
                        meta={meta}
                        value={node.left}
                        onChange={(left) => onChange({ ...node, left })}
                    />
                </div>
                <div className="col-auto">
                    <select
                        className="form-select form-select-sm lido-screener-operator-select"
                        value={node.operator || 'gt'}
                        onChange={(e) => onChange({ ...node, operator: e.target.value })}
                        aria-label="Comparator"
                    >
                        {(meta?.operators || []).map((op) => (
                            <option key={op.id} value={op.id}>{op.label}</option>
                        ))}
                    </select>
                </div>
                <div className="col-12 col-lg">
                    <OperandEditor
                        side="right"
                        meta={meta}
                        value={node.right}
                        onChange={(right) => onChange({ ...node, right })}
                        leading={(
                            <div className="d-flex align-items-center gap-1 mb-2 lido-screener-rhs-weight">
                                <NumberInput
                                    compact
                                    step={0.1}
                                    min={0}
                                    className="lido-screener-weight-input"
                                    value={node.weight_factor ?? 1}
                                    onChange={(e) => {
                                        const raw = e.target.value;
                                        if (raw === '') {
                                            onChange({ ...node, weight_factor: '' });
                                            return;
                                        }
                                        const parsed = Number(raw);
                                        onChange({
                                            ...node,
                                            weight_factor: Number.isFinite(parsed) ? parsed : raw,
                                        });
                                    }}
                                    onBlur={() => {
                                        const parsed = Number(node.weight_factor);
                                        onChange({
                                            ...node,
                                            weight_factor: Number.isFinite(parsed) ? parsed : 1,
                                        });
                                    }}
                                    aria-label="RHS weight factor"
                                    title="Multiplies the right-hand side (default 1)"
                                />
                                <span className="lido-screener-rhs-times user-select-none" aria-hidden="true">×</span>
                            </div>
                        )}
                    />
                </div>
            </div>
            {onRemove && (
                <div className="text-end mt-2">
                    <button type="button" className="btn btn-sm btn-link text-danger" onClick={onRemove}>
                        Remove condition
                    </button>
                </div>
            )}
        </div>
    );
}

const WEEKDAYS = [
    { id: 0, label: 'Sun' },
    { id: 1, label: 'Mon' },
    { id: 2, label: 'Tue' },
    { id: 3, label: 'Wed' },
    { id: 4, label: 'Thu' },
    { id: 5, label: 'Fri' },
    { id: 6, label: 'Sat' },
];

export default function ScreenerEditorPage() {
    const { id } = useParams();
    const location = useLocation();
    const isNew = id === 'new' || location.pathname.replace(/\/$/, '').endsWith('/screeners/new');
    const [searchParams] = useSearchParams();
    const navigate = useNavigate();
    const [meta, setMeta] = useState(null);
    const [watchlists, setWatchlists] = useState([]);
    const [form, setForm] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [running, setRunning] = useState(false);
    const [runResult, setRunResult] = useState(null);
    const [history, setHistory] = useState([]);
    const [historyTotal, setHistoryTotal] = useState(0);
    const [historyLimit, setHistoryLimit] = useState(30);
    const [compareMatrix, setCompareMatrix] = useState(null);
    const [compareVisible, setCompareVisible] = useState(false);
    const [loadingCompare, setLoadingCompare] = useState(false);
    const [backtestRange, setBacktestRange] = useState('1y');
    const [backtesting, setBacktesting] = useState(false);
    const [backtestProgress, setBacktestProgress] = useState(null);
    const [backtestMatrix, setBacktestMatrix] = useState(null);
    const [clearingHistory, setClearingHistory] = useState(false);
    const [wideLayout, setWideLayout] = useState(() => (
        typeof window !== 'undefined' && window.matchMedia(WIDE_LAYOUT_QUERY).matches
    ));
    const [configOpen, setConfigOpen] = useState(true);
    const [conditionsOpen, setConditionsOpen] = useState(true);
    const [touched, setTouched] = useState({});
    const [submitted, setSubmitted] = useState(false);

    useEffect(() => {
        const media = window.matchMedia(WIDE_LAYOUT_QUERY);
        const onChange = () => setWideLayout(media.matches);
        onChange();
        media.addEventListener('change', onChange);
        return () => media.removeEventListener('change', onChange);
    }, []);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const metaRes = await api.get('/screeners/meta', { skipErrorToast: true });
            const metaData = metaRes.data?.data ?? null;
            setMeta(metaData);

            const wlRes = await api.get('/watchlists', { skipErrorToast: true });
            setWatchlists(wlRes.data?.data ?? []);

            if (isNew) {
                setForm(defaultForm(metaData));
                setHistory([]);
                setHistoryTotal(0);
                setCompareMatrix(null);
                setCompareVisible(false);
                setBacktestMatrix(null);
                setBacktestProgress(null);
                setRunResult(null);
                return;
            }

            const [showRes, runsRes] = await Promise.all([
                api.get(`/screeners/${id}`, { skipErrorToast: true }),
                api.get(`/screeners/${id}/runs`, { skipErrorToast: true }),
            ]);
            const data = showRes.data?.data;
            setForm({
                name: data.name,
                description: data.description || '',
                scope: data.scope,
                watchlist_id: data.watchlist_id || '',
                index_symbol: data.index_symbol || '',
                definition_json: data.definition_json,
                schedule_enabled: !!data.schedule_enabled,
                schedule_time: data.schedule_time || '18:30',
                schedule_days: data.schedule_days || [],
                telegram_enabled: !!data.telegram_enabled,
                is_enabled: !!data.is_enabled,
                is_shared: !!data.is_shared,
                max_lookback: data.max_lookback,
            });
            setHistory(runsRes.data?.data ?? []);
            setHistoryTotal(runsRes.data?.total ?? runsRes.data?.data?.length ?? 0);
            setHistoryLimit(runsRes.data?.limit ?? 30);
            setCompareMatrix(null);
            setCompareVisible(false);
            setBacktestProgress(null);

            // Backtest results persist per date in DB; show them on load when present.
            try {
                const btRes = await api.get(`/screeners/${id}/backtest/matrix`, { skipErrorToast: true });
                const btMatrix = btRes.data?.data ?? null;
                setBacktestMatrix(btMatrix?.run_count > 0 ? btMatrix : null);
            } catch {
                setBacktestMatrix(null);
            }

            const runId = searchParams.get('run');
            if (runId) {
                const runShow = await api.get(`/screener-runs/${runId}`, { skipErrorToast: true });
                setRunResult(runShow.data?.data ?? null);
            } else {
                setRunResult(null);
            }
        } catch (error) {
            showToast(validationMessage(error), 'danger');
            navigate('/screeners');
        } finally {
            setLoading(false);
        }
    }, [id, isNew, navigate, searchParams]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        return () => {
            let token = null;
            try {
                token = sessionStorage.getItem(BACKTEST_SESSION_KEY);
            } catch {
                token = null;
            }
            if (!token) return;
            api.delete(`/screener-backtests/session/${encodeURIComponent(token)}`, { skipErrorToast: true })
                .catch(() => {});
        };
    }, []);

    usePortfolioChanged(load);

    const patch = (partial) => setForm((prev) => ({ ...prev, ...partial }));
    const markTouched = (field) => setTouched((prev) => ({ ...prev, [field]: true }));

    const validationErrors = useMemo(
        () => validateForm(form, watchlists, meta?.indexes),
        [form, watchlists, meta?.indexes],
    );
    const hasValidationErrors = Object.keys(validationErrors).length > 0;
    const showFieldError = (field) => {
        if (!validationErrors[field]) return false;
        if (submitted || touched[field]) return true;
        if (!isNew && field === 'watchlist_id' && form?.scope === 'watchlist') return true;
        if (!isNew && field === 'index_symbol' && form?.scope === 'index') return true;
        return false;
    };

    const buildPayload = () => ({
        ...form,
        watchlist_id: form.scope === 'watchlist' ? Number(form.watchlist_id) || null : null,
        index_symbol: form.scope === 'index' ? String(form.index_symbol || '').trim().toUpperCase() || null : null,
        description: form.description || null,
    });

    const save = async () => {
        setSubmitted(true);
        if (hasValidationErrors) {
            const firstError = Object.values(validationErrors)[0];
            showToast(firstError || 'Please fix validation errors before saving.', 'danger');
            return false;
        }
        setSaving(true);
        try {
            const payload = buildPayload();
            if (isNew) {
                const res = await api.post('/screeners', payload);
                showToast(`Screener "${payload.name.trim()}" created successfully.`);
                navigate(`/screeners/${res.data?.data?.id}`, { replace: true });
                return true;
            }
            const res = await api.put(`/screeners/${id}`, payload);
            setForm((prev) => ({
                ...prev,
                ...res.data?.data,
                description: res.data?.data?.description || '',
                watchlist_id: res.data?.data?.watchlist_id || '',
                index_symbol: res.data?.data?.index_symbol || '',
            }));
            showToast(`Screener "${res.data?.data?.name}" updated successfully.`);
            return true;
        } catch (error) {
            showToast(validationMessage(error), 'danger');
            return false;
        } finally {
            setSaving(false);
        }
    };

    const pollContinue = async (runId) => {
        let guard = 0;
        while (guard < 500) {
            guard += 1;
            const cont = await api.post(`/screener-runs/${runId}/continue`);
            setRunResult(cont.data?.data ?? null);
            if (cont.data?.completed) return cont.data.data;
            if (!cont.data?.continued) return cont.data?.data;
        }
        return null;
    };

    const runNow = async () => {
        if (isNew) return;
        setSubmitted(true);
        if (hasValidationErrors) {
            const firstError = Object.values(validationErrors)[0];
            showToast(firstError || 'Please fix validation errors before running.', 'danger');
            return;
        }
        setRunning(true);
        try {
            const ok = await save();
            if (!ok) return;
            const res = await api.post(`/screeners/${id}/run`);
            let run = res.data?.data;
            setRunResult(run);
            if (res.data?.continued && run?.id) {
                showToast('Scanning universe in chunks…');
                run = await pollContinue(run.id);
            }
            const full = await api.get(`/screener-runs/${run.id}`);
            const fullRun = full.data?.data ?? run;
            setRunResult(fullRun);
            navigate(`/screeners/${id}?run=${fullRun.id}`, { replace: true });
            const matched = fullRun?.stats?.matched ?? 0;
            showToast(`Run ID ${fullRun.id} finished with ${matched} match(es).`);
            const runsRes = await api.get(`/screeners/${id}/runs`);
            setHistory(runsRes.data?.data ?? []);
            setHistoryTotal(runsRes.data?.total ?? runsRes.data?.data?.length ?? 0);
            setHistoryLimit(runsRes.data?.limit ?? 30);
            setCompareMatrix(null);
            setCompareVisible(false);
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setRunning(false);
        }
    };

    const loadRun = async (runId) => {
        try {
            const res = await api.get(`/screener-runs/${runId}`);
            setRunResult(res.data?.data ?? null);
            navigate(`/screeners/${id}?run=${runId}`, { replace: true });
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        }
    };

    const loadStackedResults = async () => {
        if (isNew || !id) return;
        setLoadingCompare(true);
        setCompareVisible(true);
        try {
            const compareRes = await api.get(`/screeners/${id}/runs/compare`, { skipErrorToast: true });
            setCompareMatrix(compareRes.data?.data ?? null);
        } catch (error) {
            setCompareVisible(false);
            setCompareMatrix(null);
            showToast(validationMessage(error), 'danger');
        } finally {
            setLoadingCompare(false);
        }
    };

    const pollBacktestContinue = async (backtestId) => {
        let guard = 0;
        let latest = null;
        while (guard < 2000) {
            guard += 1;
            const cont = await api.post(`/screener-backtests/${backtestId}/continue`);
            latest = cont.data?.data ?? null;
            setBacktestProgress(latest);
            if (cont.data?.completed) return latest;
            if (!cont.data?.continued) return latest;
        }
        return latest;
    };

    const runBacktest = async (rangeKey = backtestRange) => {
        if (isNew) return;
        setSubmitted(true);
        if (hasValidationErrors) {
            const firstError = Object.values(validationErrors)[0];
            showToast(firstError || 'Please fix validation errors before backtesting.', 'danger');
            return;
        }
        const scopes = meta?.backtest_scopes || ['holdings', 'watchlist', 'all_equities', 'index'];
        if (!scopes.includes(form.scope)) {
            showToast('Backtest is not available for this scope.', 'danger');
            return;
        }
        const nextRange = rangeKey || backtestRange;
        setBacktestRange(nextRange);
        setBacktesting(true);
        setBacktestMatrix(null);
        setBacktestProgress(null);
        try {
            const ok = await save();
            if (!ok) return;
            const token = getOrCreateBacktestSessionToken();
            const res = await api.post(`/screeners/${id}/backtest`, {
                range: nextRange,
                session_token: token,
            });
            let backtest = res.data?.data;
            setBacktestProgress(backtest);
            if (res.data?.continued && backtest?.id) {
                backtest = await pollBacktestContinue(backtest.id);
            }
            if (backtest?.status === 'failed') {
                showToast(backtest.error_message || 'Backtest failed.', 'danger');
                return;
            }
            if (backtest?.status !== 'completed' || !backtest?.id) {
                showToast('Backtest did not complete.', 'danger');
                return;
            }
            const matrixRes = await api.get(`/screeners/${id}/backtest/matrix`);
            setBacktestMatrix(matrixRes.data?.data ?? null);
            const reused = backtest.stats?.days_reused ?? 0;
            showToast(`Backtest finished (${backtest.stats?.days_done ?? 0} weekdays${reused > 0 ? `, ${reused} reused from saved results` : ''}).`);
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setBacktesting(false);
        }
    };

    const clearHistory = async () => {
        if (!window.confirm('Delete all run history, matched results and saved backtest results for this screener? This cannot be undone.')) {
            return;
        }
        setClearingHistory(true);
        try {
            const res = await api.delete(`/screeners/${id}/runs`);
            const deleted = res.data?.deleted ?? 0;
            const backtestDays = res.data?.backtest_days_cleared ?? 0;
            setHistory([]);
            setHistoryTotal(0);
            setCompareMatrix(null);
            setCompareVisible(false);
            setRunResult(null);
            setBacktestMatrix(null);
            setBacktestProgress(null);
            navigate(`/screeners/${id}`, { replace: true });
            showToast(`Cleared ${deleted} run record(s)${backtestDays > 0 ? ` and ${backtestDays} saved backtest day(s)` : ''}.`);
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setClearingHistory(false);
        }
    };

    const lookbackHint = useMemo(() => form?.max_lookback ?? '—', [form?.max_lookback]);
    const latestRunId = history[0]?.id ?? null;
    const backtestAllowed = useMemo(() => {
        const scopes = meta?.backtest_scopes || ['holdings', 'watchlist', 'all_equities', 'index'];
        return scopes.includes(form?.scope);
    }, [meta?.backtest_scopes, form?.scope]);
    const backtestRanges = meta?.backtest_ranges || [
        { id: '1y', label: '1 year' },
        { id: '6m', label: '6 months' },
        { id: '3m', label: '3 months' },
        { id: '1m', label: '1 month' },
        { id: '15d', label: '15 days' },
    ];
    const selectedBacktestRange = useMemo(
        () => backtestRanges.find((r) => r.id === backtestRange) || backtestRanges[0],
        [backtestRanges, backtestRange],
    );
    const runResultsHeading = useMemo(() => {
        if (!runResult) return null;
        return formatRunResultsHeading(runResult, {
            isLatest: latestRunId != null && runResult.id === latestRunId,
        });
    }, [runResult, latestRunId]);
    const runResultWarning = useMemo(
        () => runStatsWarning(runResult?.stats, runResult?.error_message),
        [runResult],
    );

    if (loading || !form) {
        return <div className="container-fluid py-3 text-muted">Loading screener…</div>;
    }

    const configSection = (
        <>
            <div className="mb-3">
                <label className="form-label">Name</label>
                <input
                    className={`form-control${showFieldError('name') ? ' is-invalid' : ''}`}
                    value={form.name}
                    onChange={(e) => {
                        markTouched('name');
                        patch({ name: e.target.value });
                    }}
                    placeholder="e.g. Golden cross holdings"
                />
                {showFieldError('name') && <div className="invalid-feedback d-block">{validationErrors.name}</div>}
                <div className="form-text">
                    {String(form.name || '').trim().length}/{NAME_MAX_LENGTH}
                </div>
            </div>
            <div className="mb-3">
                <label className="form-label">Description</label>
                <textarea
                    className={`form-control${showFieldError('description') ? ' is-invalid' : ''}`}
                    rows={2}
                    value={form.description}
                    onChange={(e) => {
                        markTouched('description');
                        patch({ description: e.target.value });
                    }}
                />
                {showFieldError('description') && <div className="invalid-feedback d-block">{validationErrors.description}</div>}
                <div className="form-text">
                    {String(form.description || '').trim().length}/{DESCRIPTION_MAX_LENGTH}
                </div>
            </div>
            <div className="mb-3">
                <label className="form-label">Scope</label>
                <select
                    className={`form-select lido-screener-config-select${showFieldError('scope') ? ' is-invalid' : ''}`}
                    value={form.scope}
                    onChange={(e) => {
                        markTouched('scope');
                        patch({ scope: e.target.value });
                    }}
                >
                    {(meta?.scopes || []).map((s) => (
                        <option key={s.id} value={s.id}>{s.label}</option>
                    ))}
                </select>
                {showFieldError('scope') && <div className="invalid-feedback d-block">{validationErrors.scope}</div>}
            </div>
            {form.scope === 'watchlist' && (
                <div className="mb-3">
                    <label className="form-label">Watchlist</label>
                    <select
                        className={`form-select lido-screener-config-select${showFieldError('watchlist_id') ? ' is-invalid' : ''}`}
                        value={form.watchlist_id}
                        onChange={(e) => {
                            markTouched('watchlist_id');
                            patch({ watchlist_id: e.target.value });
                        }}
                    >
                        <option value="">Select…</option>
                        {watchlists.map((w) => (
                            <option key={w.id} value={w.id}>{w.name}</option>
                        ))}
                    </select>
                    {showFieldError('watchlist_id') && (
                        <div className="invalid-feedback d-block">{validationErrors.watchlist_id}</div>
                    )}
                </div>
            )}
            {form.scope === 'index' && (
                <div className="mb-3">
                    <label className="form-label">Index</label>
                    <select
                        className={`form-select lido-screener-config-select${showFieldError('index_symbol') ? ' is-invalid' : ''}`}
                        value={form.index_symbol}
                        onChange={(e) => {
                            markTouched('index_symbol');
                            patch({ index_symbol: e.target.value });
                        }}
                    >
                        <option value="">Select…</option>
                        {(meta?.indexes || []).map((idx) => (
                            <option key={idx.symbol} value={idx.symbol}>
                                {idx.name}
                                {' ('}
                                {idx.symbol}
                                {')'}
                            </option>
                        ))}
                    </select>
                    {showFieldError('index_symbol') && (
                        <div className="invalid-feedback d-block">{validationErrors.index_symbol}</div>
                    )}
                    <div className="form-text">
                        Screens NSE broad and sector index constituents from the Indices cache.
                    </div>
                </div>
            )}
            <div className="form-check form-switch mb-2">
                <input
                    className="form-check-input"
                    type="checkbox"
                    id="screener-enabled"
                    checked={form.is_enabled}
                    onChange={(e) => patch({ is_enabled: e.target.checked })}
                />
                <label className="form-check-label" htmlFor="screener-enabled">Enabled</label>
            </div>
            <div className="form-check form-switch mb-2">
                <input
                    className="form-check-input"
                    type="checkbox"
                    id="screener-share"
                    checked={form.is_shared}
                    onChange={(e) => patch({ is_shared: e.target.checked })}
                />
                <label className="form-check-label" htmlFor="screener-share">
                    Share with other portfolios
                </label>
                <div className="form-text">
                    Listed under Shared screens for other portfolios. Import always creates a private copy.
                </div>
            </div>
            <div className="form-check form-switch mb-2">
                <input
                    className="form-check-input"
                    type="checkbox"
                    id="screener-schedule"
                    checked={form.schedule_enabled}
                    onChange={(e) => patch({ schedule_enabled: e.target.checked })}
                />
                <label className="form-check-label" htmlFor="screener-schedule">Schedule</label>
            </div>
            {form.schedule_enabled && (
                <>
                    <div className="mb-2">
                        <label className="form-label">Time (cron timezone)</label>
                        <input
                            type="time"
                            className={`form-control lido-screener-config-time${showFieldError('schedule_time') ? ' is-invalid' : ''}`}
                            value={form.schedule_time}
                            onChange={(e) => {
                                markTouched('schedule_time');
                                patch({ schedule_time: e.target.value });
                            }}
                        />
                        {showFieldError('schedule_time') && <div className="invalid-feedback d-block">{validationErrors.schedule_time}</div>}
                    </div>
                    <div className="mb-2">
                        <div className="form-label">Days (empty = every day)</div>
                        <div className="d-flex flex-wrap gap-1">
                            {WEEKDAYS.map((d) => {
                                const on = (form.schedule_days || []).includes(d.id);
                                return (
                                    <button
                                        key={d.id}
                                        type="button"
                                        className={`btn btn-sm ${on ? 'btn-primary' : 'btn-outline-secondary'}`}
                                        onClick={() => {
                                            const days = new Set(form.schedule_days || []);
                                            if (on) days.delete(d.id);
                                            else days.add(d.id);
                                            patch({ schedule_days: [...days].sort() });
                                        }}
                                    >
                                        {d.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                    <div className="form-check form-switch mb-2">
                        <input
                            className="form-check-input"
                            type="checkbox"
                            id="screener-telegram"
                            checked={form.telegram_enabled}
                            onChange={(e) => patch({ telegram_enabled: e.target.checked })}
                        />
                        <label className="form-check-label" htmlFor="screener-telegram">
                            Notify me results (only for cron runs)
                        </label>
                    </div>
                </>
            )}
            <p className="small text-muted mb-0 mt-3">
                Tree needs ≥ <strong>{lookbackHint}</strong> OHLCV sessions (recomputed on save).
            </p>
        </>
    );

    const conditionsSection = (
        <div className="lido-screener-tree">
            <ConditionNode
                node={form.definition_json?.root}
                meta={meta}
                depth={1}
                onChange={(root) => patch({
                    definition_json: { root },
                    max_lookback: form.max_lookback,
                })}
            />
        </div>
    );

    return (
        <div className="container-fluid py-3 pb-5">
            <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                <Link to="/screeners" className="btn btn-sm btn-outline-secondary">← Screeners</Link>
                <h1 className="h3 mb-0">{isNew ? 'New screener' : 'Edit screener'}</h1>
            </div>

            <div className="row g-3">
                <div className="col-12 col-xxl-4">
                    <CollapsibleSection
                        id="screener-config"
                        title="Configuration"
                        wideLayout={wideLayout}
                        open={configOpen}
                        onToggle={() => setConfigOpen((v) => !v)}
                    >
                        {configSection}
                    </CollapsibleSection>
                </div>

                <div className="col-12 col-xxl-8">
                    <CollapsibleSection
                        id="screener-conditions"
                        title="Conditions"
                        wideLayout={wideLayout}
                        open={conditionsOpen}
                        onToggle={() => setConditionsOpen((v) => !v)}
                        sectionClassName="lido-screener-section"
                    >
                        {conditionsSection}
                    </CollapsibleSection>

                    {!isNew && runResult && runResultsHeading && (
                        <div className="border rounded p-3 mb-3">
                            <div className="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                <div>
                                    <h2 className="h6 mb-1">
                                        Results for {runResultsHeading.title}
                                    </h2>
                                    <div className="small text-muted">
                                        {runResultsHeading.isLatest
                                            ? 'Showing the most recent run.'
                                            : 'Showing a historical run selected from Run history below.'}
                                    </div>
                                </div>
                                <div className="text-end">
                                    <span className={`badge ${runResultsHeading.isLatest ? 'text-bg-primary' : 'text-bg-secondary'} me-1`}>
                                        {runResultsHeading.isLatest ? 'Latest run' : 'Historical run'}
                                    </span>
                                    <span className="badge text-bg-secondary">{runResult.status}</span>
                                </div>
                            </div>
                            <div className="small text-muted mb-2">
                                {lastRunSummaryText(runResult.stats)}
                                {runResult.stats?.telegram_failed ? ' · Notify failed' : ''}
                            </div>
                            {runResultWarning && (
                                <div className="alert alert-warning py-2 px-3 small mb-2" role="alert">
                                    ⚠ {runResultWarning}
                                </div>
                            )}
                            {runResult.status === 'running' && (
                                <div className="progress mb-2" style={{ height: 8 }}>
                                    <div
                                        className="progress-bar"
                                        style={{ width: `${runResult.progress_pct || 0}%` }}
                                    />
                                </div>
                            )}
                            <div className="table-responsive">
                                <table className="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Symbol</th>
                                            <th>Name</th>
                                            <th>Metrics</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(runResult.hits?.data || []).map((hit) => (
                                            <tr key={hit.id}>
                                                <td>
                                                    {hit.symbol ? (
                                                        <Link to={`/watchlist/${encodeURIComponent(hit.symbol)}`}>
                                                            {hit.symbol}
                                                        </Link>
                                                    ) : (
                                                        '—'
                                                    )}
                                                    {hit.exchange ? (
                                                        <span className="text-muted">
                                                            {` · ${hit.exchange}`}
                                                        </span>
                                                    ) : null}
                                                </td>
                                                <td className="small">{hit.name}</td>
                                                <td className="small">
                                                    {(hit.metrics || []).slice(0, 4).map((m, i) => {
                                                        const weight = Number(m.weight_factor);
                                                        const showWeight = Number.isFinite(weight) && Math.abs(weight - 1) > 1e-12;
                                                        return (
                                                            <div key={i}>
                                                                {m.left}={m.left_value != null ? Number(m.left_value).toFixed(2) : '?'}
                                                                {' '}
                                                                {operatorLabel(m.operator)}
                                                                {' '}
                                                                {showWeight ? `${weight}×` : ''}
                                                                {m.right}
                                                            </div>
                                                        );
                                                    })}
                                                </td>
                                                <td className="text-nowrap">
                                                    {hit.symbol ? (
                                                        <Link
                                                            to={buildExplorerComparePath(hit.symbol, DEFAULT_EXPLORER_BENCHMARK)}
                                                            className="btn btn-sm btn-link"
                                                        >
                                                            Explorer
                                                        </Link>
                                                    ) : null}
                                                    {(meta?.external_stock_links || []).map((link) => {
                                                        if (!hit.symbol || !link?.url) {
                                                            return null;
                                                        }
                                                        const href = resolveExternalStockUrl(
                                                            link.url,
                                                            hit.symbol,
                                                            hit.exchange,
                                                        );
                                                        return (
                                                            <a
                                                                key={link.id || link.label}
                                                                href={href}
                                                                className="btn btn-sm btn-link"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                {link.label}
                                                            </a>
                                                        );
                                                    })}
                                                </td>
                                            </tr>
                                        ))}
                                        {(runResult.hits?.data || []).length === 0 && (
                                            <tr>
                                                <td colSpan={4} className="text-muted">No matches</td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {!isNew && history.length > 0 && (
                        <div className="border rounded p-3 mb-3">
                            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <h2 className="h6 mb-1">Run history</h2>
                                    <div className="form-text mb-0">
                                        Run ID is the permanent database record number.
                                        {' '}
                                        {historyTotal > historyLimit
                                            ? `Showing the latest ${history.length} of ${historyTotal} stored runs.`
                                            : `${historyTotal} stored run(s).`}
                                        {' '}
                                        Matched stocks are stored in the database until history is cleared.
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-danger"
                                    disabled={clearingHistory || running}
                                    onClick={clearHistory}
                                >
                                    {clearingHistory ? 'Clearing…' : 'Clear history'}
                                </button>
                            </div>
                            <ul className="list-unstyled mb-3 small">
                                {history.map((r) => {
                                    const isSelected = runResult?.id === r.id;
                                    const isLatest = latestRunId === r.id;
                                    return (
                                        <li
                                            key={r.id}
                                            className={`d-flex justify-content-between gap-2 py-1 border-bottom${isSelected ? ' lido-screener-run-selected rounded px-1' : ''}`}
                                        >
                                            <button
                                                type="button"
                                                className={`btn btn-link btn-sm p-0 text-start${isSelected ? ' fw-semibold' : ''}`}
                                                onClick={() => loadRun(r.id)}
                                            >
                                                {formatRunListLabel(r)}
                                                {isLatest && (
                                                    <span className="badge text-bg-primary ms-1">Latest</span>
                                                )}
                                                {isSelected && !isLatest && (
                                                    <span className="badge text-bg-secondary ms-1">Viewing</span>
                                                )}
                                            </button>
                                            <span className="text-muted text-end">
                                                {lastRunSummaryText(r.stats)}
                                                {runStatsWarning(r.stats, r.error_message) && (
                                                    <>
                                                        {' · '}
                                                        <span className="text-warning-emphasis" title={runStatsWarning(r.stats, r.error_message)}>
                                                            ⚠
                                                        </span>
                                                    </>
                                                )}
                                                {' · '}
                                                {r.finished_at
                                                    ? new Date(r.finished_at).toLocaleString()
                                                    : '…'}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 mb-2">
                                <h3 className="h6 mb-0">Stacked run results</h3>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-secondary"
                                    disabled={loadingCompare || clearingHistory || running}
                                    onClick={loadStackedResults}
                                >
                                    {loadingCompare
                                        ? 'Loading…'
                                        : compareVisible
                                            ? 'Refresh stacked results'
                                            : 'Show stacked results'}
                                </button>
                            </div>
                            {!compareVisible && (
                                <div className="form-text mb-0">
                                    Optional overlay of matched stocks across completed runs (latest {historyLimit}).
                                    Click the button to calculate and load it.
                                </div>
                            )}
                            {compareVisible && (
                                <>
                                    <div className="form-text mb-2">
                                        All matched stocks across completed runs (latest {historyLimit}).
                                        Green cells mean the stock hit that run; numbers are consecutive hit streaks left→right (reset after a miss).
                                        Scroll horizontally; the stock column stays fixed.
                                    </div>
                                    {loadingCompare && !compareMatrix ? (
                                        <p className="small text-muted mb-0">Building stacked matrix…</p>
                                    ) : (
                                        <ScreenerRunsCompareTable
                                            matrix={compareMatrix}
                                            onSelectRun={loadRun}
                                        />
                                    )}
                                </>
                            )}
                        </div>
                    )}

                    {!isNew && (backtesting || backtestMatrix) && (
                        <div className="border rounded p-3 mb-3">
                            <h2 className="h6 mb-1">Backtest results</h2>
                            {backtesting && (
                                <p className="small text-muted mb-2">
                                    Backtesting…
                                    {' '}
                                    {backtestProgress?.stats?.days_done ?? 0}
                                    {' / '}
                                    {backtestProgress?.stats?.day_total ?? '…'}
                                    {' '}
                                    weekdays
                                    {backtestProgress?.stats?.progress_pct != null
                                        ? ` (${backtestProgress.stats.progress_pct}%)`
                                        : ''}
                                </p>
                            )}
                            {!backtesting && backtestMatrix && (
                                <>
                                    <div className="form-text mb-2">
                                        As-of weekday walk (weekends skipped). Green = matched that day; badge = hit count; numbers are consecutive streaks.
                                        Results are saved per date — re-running a backtest reuses saved days and only computes missing dates.
                                        Editing conditions or scope, or Clear history, discards saved results.
                                    </div>
                                    <ScreenerRunsCompareTable matrix={backtestMatrix} />
                                </>
                            )}
                        </div>
                    )}
                </div>
            </div>

            <div className="lido-screener-editor-footer d-flex flex-wrap justify-content-end align-items-center gap-2 mt-4 pt-3 border-top">
                <Link to="/screeners" className="btn btn-outline-secondary">
                    Close
                </Link>
                {!isNew && (
                    <>
                        <button
                            type="button"
                            className="btn btn-outline-primary"
                            disabled={running || backtesting || saving || hasValidationErrors}
                            onClick={runNow}
                        >
                            {running ? 'Running…' : 'Run now'}
                        </button>
                        <ComboButton
                            label={backtesting
                                ? 'Backtesting…'
                                : `Backtest · ${selectedBacktestRange?.label || '1 year'}`}
                            variant="outline-secondary"
                            disabled={running || backtesting || saving || hasValidationErrors || !backtestAllowed}
                            title={backtestAllowed
                                ? 'Walk weekdays as-of and stack hits (dropdown picks another window)'
                                : 'Backtest is not available for this scope'}
                            onPrimaryClick={() => {
                                if (!running && !backtesting && !saving && !hasValidationErrors && backtestAllowed) {
                                    runBacktest(selectedBacktestRange?.id || backtestRange);
                                }
                            }}
                            menuItems={backtestRanges
                                .filter((r) => r.id !== (selectedBacktestRange?.id || backtestRange))
                                .map((r) => ({
                                    key: r.id,
                                    label: r.label,
                                    onClick: () => runBacktest(r.id),
                                }))}
                        />
                    </>
                )}
                <button
                    type="button"
                    className="btn btn-primary"
                    disabled={saving || running || backtesting || hasValidationErrors}
                    onClick={save}
                >
                    {saving ? 'Saving…' : (isNew ? 'Save' : 'Save')}
                </button>
            </div>
        </div>
    );
}
