import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { showToast } from '../../toast';
import ManagedStrategyDesigner from './ManagedStrategyDesigner';
import {
    CAPITAL_ALLOCATIONS,
    DEFAULT_STRATEGY_PROMPT_INPUTS,
    EXIT_STYLES,
    EXPLAINABILITY_LEVELS,
    HOLDING_PERIODS,
    INVESTMENT_STYLES,
    MARKET_PREFERENCES,
    OPTIMIZATION_PRIORITIES,
    RISK_PROFILES,
    STORAGE_KEY,
    STRATEGY_COMPLEXITIES,
    TARGET_MARKETS,
    UNIVERSES,
} from '../../strategyPrompt/defaults';
import { generatePrompt } from '../../strategyPrompt/generatePrompt';
import {
    DEFAULT_PROMPT_TEMPLATE_ID,
    listAvailablePromptTemplates,
} from '../../strategyPrompt/templates';

function loadPersisted() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return null;
        return parsed;
    } catch {
        return null;
    }
}

function persistState(next) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    } catch {
        // Ignore quota / private mode failures.
    }
}

function mergeInputs(saved) {
    return {
        ...DEFAULT_STRATEGY_PROMPT_INPUTS,
        ...(saved && typeof saved === 'object' ? saved : {}),
        marketPreferences: Array.isArray(saved?.marketPreferences)
            ? saved.marketPreferences
            : DEFAULT_STRATEGY_PROMPT_INPUTS.marketPreferences,
        optimizationPriorities: Array.isArray(saved?.optimizationPriorities)
            ? saved.optimizationPriorities
            : DEFAULT_STRATEGY_PROMPT_INPUTS.optimizationPriorities,
    };
}

/**
 * Structured inputs for managed Strategy Designer; original builder remains recovery-only.
 */
export default function AIStrategyPromptBuilder() {
    const persisted = useMemo(() => loadPersisted(), []);
    const [open, setOpen] = useState(() => Boolean(persisted?.panelOpen));
    const [templateId, setTemplateId] = useState(
        () => persisted?.templateId || DEFAULT_PROMPT_TEMPLATE_ID,
    );
    const [inputs, setInputs] = useState(() => mergeInputs(persisted?.inputs));
    const templates = useMemo(() => listAvailablePromptTemplates(), []);

    useEffect(() => {
        persistState({
            panelOpen: open,
            templateId,
            inputs,
        });
    }, [open, templateId, inputs]);

    const complexityGuidance = STRATEGY_COMPLEXITIES.find((c) => c.id === inputs.strategyComplexity)?.guidance;
    const explainGuidance = EXPLAINABILITY_LEVELS.find((c) => c.id === inputs.explainabilityLevel)?.guidance;

    const patch = useCallback((partial) => {
        setInputs((prev) => ({ ...prev, ...partial }));
    }, []);

    const toggleMarketPreference = (label) => {
        setInputs((prev) => {
            const current = Array.isArray(prev.marketPreferences) ? [...prev.marketPreferences] : [];
            if (label === 'No Preference') {
                const has = current.includes('No Preference');
                return { ...prev, marketPreferences: has ? [] : ['No Preference'] };
            }
            const withoutNone = current.filter((x) => x !== 'No Preference');
            const next = withoutNone.includes(label)
                ? withoutNone.filter((x) => x !== label)
                : [...withoutNone, label];
            return { ...prev, marketPreferences: next };
        });
    };

    const toggleOptimization = (label) => {
        setInputs((prev) => {
            const current = Array.isArray(prev.optimizationPriorities) ? [...prev.optimizationPriorities] : [];
            const next = current.includes(label)
                ? current.filter((x) => x !== label)
                : [...current, label];
            return { ...prev, optimizationPriorities: next };
        });
    };

    const handleResetDefaults = () => {
        setInputs({ ...DEFAULT_STRATEGY_PROMPT_INPUTS });
        setTemplateId(DEFAULT_PROMPT_TEMPLATE_ID);
        showToast('AI Strategy Designer defaults restored.', 'info');
    };

    const renderCustomField = (show, id, label, value, onChange) => {
        if (!show) return null;
        return (
            <div className="col-12">
                <label className="form-label" htmlFor={id}>{label}</label>
                <textarea
                    id={id}
                    className="form-control"
                    rows={3}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="Describe your custom choice…"
                />
            </div>
        );
    };

    return (
        <div className="card mb-3">
            <div className="card-header p-0">
                <button
                    type="button"
                    id="ai-strategy-designer-toggle"
                    className="lido-collapsible-card-toggle"
                    onClick={() => setOpen((v) => !v)}
                    aria-expanded={open}
                    aria-controls="ai-strategy-designer-panel"
                >
                    <span>AI Strategy Designer</span>
                    <span className="lido-collapsible-card-chevron" aria-hidden="true">
                        {open ? '▾' : '▸'}
                    </span>
                </button>
            </div>
            {/*
              Do not use Bootstrap `.collapse` / `.show` here: the global bootstrap.bundle
              Collapse CSS (`display:none` without matching JS animation) can leave the
              panel visually empty when React only toggles classes. Mount content when open.
            */}
            {open ? (
                <div id="ai-strategy-designer-panel" className="card-body">
                    <p className="text-muted small mb-3">
                        Generate an advisory strategy from your choices. Review the design before choosing
                        Create draft strategy; creating a draft requires a separate preview and approval.
                    </p>

                    <div className="row g-3">
                        {templates.length > 1 ? (
                            <div className="col-md-6">
                                <label className="form-label" htmlFor="ai-prompt-template">Prompt template</label>
                                <select
                                    id="ai-prompt-template"
                                    className="form-select"
                                    value={templateId}
                                    onChange={(e) => setTemplateId(e.target.value)}
                                >
                                    {templates.map((t) => (
                                        <option key={t.id} value={t.id}>{t.label}</option>
                                    ))}
                                </select>
                            </div>
                        ) : (
                            <div className="col-12">
                                <div className="text-muted small">
                                    Template: <strong>{templates[0]?.label || 'StoX Default Prompt'}</strong>
                                </div>
                            </div>
                        )}

                        <div className="col-md-6">
                            <label className="form-label" htmlFor="ai-investment-style">Investment Style</label>
                            <select
                                id="ai-investment-style"
                                className="form-select"
                                value={inputs.investmentStyle}
                                onChange={(e) => patch({ investmentStyle: e.target.value })}
                            >
                                {INVESTMENT_STYLES.map((opt) => (
                                    <option key={opt} value={opt}>{opt}</option>
                                ))}
                            </select>
                        </div>
                        {renderCustomField(
                            inputs.investmentStyle === 'Custom',
                            'ai-custom-investment-style',
                            'Describe Investing Style',
                            inputs.customInvestmentStyle || '',
                            (v) => patch({ customInvestmentStyle: v }),
                        )}

                        <div className="col-md-3">
                            <label className="form-label" htmlFor="ai-risk-profile">Risk Profile</label>
                            <select
                                id="ai-risk-profile"
                                className="form-select"
                                value={inputs.riskProfile}
                                onChange={(e) => patch({ riskProfile: e.target.value })}
                            >
                                {RISK_PROFILES.map((opt) => (
                                    <option key={opt} value={opt}>{opt}</option>
                                ))}
                            </select>
                        </div>

                        <div className="col-md-3">
                            <label className="form-label" htmlFor="ai-holding-period">Holding Period</label>
                            <select
                                id="ai-holding-period"
                                className="form-select"
                                value={inputs.holdingPeriod}
                                onChange={(e) => patch({ holdingPeriod: e.target.value })}
                            >
                                {HOLDING_PERIODS.map((opt) => (
                                    <option key={opt} value={opt}>{opt}</option>
                                ))}
                            </select>
                        </div>
                        {renderCustomField(
                            inputs.holdingPeriod === 'Custom',
                            'ai-custom-holding-period',
                            'Describe Holding Period',
                            inputs.customHoldingPeriod || '',
                            (v) => patch({ customHoldingPeriod: v }),
                        )}

                        <div className="col-md-3">
                            <label className="form-label" htmlFor="ai-target-market">Target Market</label>
                            <select
                                id="ai-target-market"
                                className="form-select"
                                value={inputs.targetMarket}
                                onChange={(e) => patch({ targetMarket: e.target.value })}
                            >
                                {TARGET_MARKETS.map((opt) => (
                                    <option key={opt} value={opt}>{opt}</option>
                                ))}
                            </select>
                        </div>
                        {renderCustomField(
                            inputs.targetMarket === 'Custom',
                            'ai-custom-target-market',
                            'Describe Target Market',
                            inputs.customTargetMarket || '',
                            (v) => patch({ customTargetMarket: v }),
                        )}

                        <div className="col-md-3">
                            <label className="form-label" htmlFor="ai-universe">Universe</label>
                            <select
                                id="ai-universe"
                                className="form-select"
                                value={inputs.universe}
                                onChange={(e) => patch({ universe: e.target.value })}
                            >
                                {UNIVERSES.map((opt) => (
                                    <option key={opt} value={opt}>{opt}</option>
                                ))}
                            </select>
                        </div>
                        {renderCustomField(
                            inputs.universe === 'Custom',
                            'ai-custom-universe',
                            'Describe Universe',
                            inputs.customUniverse || '',
                            (v) => patch({ customUniverse: v }),
                        )}

                        <div className="col-md-3">
                            <label className="form-label" htmlFor="ai-max-positions">Maximum Positions</label>
                            <input
                                id="ai-max-positions"
                                type="number"
                                className="form-control"
                                min={1}
                                step={1}
                                value={inputs.maximumPositions ?? ''}
                                onChange={(e) => {
                                    const raw = e.target.value;
                                    if (raw === '') {
                                        patch({ maximumPositions: '' });
                                        return;
                                    }
                                    const n = Number(raw);
                                    patch({ maximumPositions: Number.isFinite(n) ? n : raw });
                                }}
                            />
                        </div>

                        <div className="col-md-3">
                            <label className="form-label" htmlFor="ai-capital-allocation">Capital Allocation</label>
                            <select
                                id="ai-capital-allocation"
                                className="form-select"
                                value={inputs.capitalAllocation}
                                onChange={(e) => patch({ capitalAllocation: e.target.value })}
                            >
                                {CAPITAL_ALLOCATIONS.map((opt) => (
                                    <option key={opt} value={opt}>{opt}</option>
                                ))}
                            </select>
                        </div>
                        {renderCustomField(
                            inputs.capitalAllocation === 'Custom',
                            'ai-custom-capital-allocation',
                            'Describe Capital Allocation',
                            inputs.customCapitalAllocation || '',
                            (v) => patch({ customCapitalAllocation: v }),
                        )}

                        <div className="col-md-6">
                            <label className="form-label" htmlFor="ai-exit-style">Preferred Exit Style</label>
                            <select
                                id="ai-exit-style"
                                className="form-select"
                                value={inputs.preferredExitStyle}
                                onChange={(e) => patch({ preferredExitStyle: e.target.value })}
                            >
                                {EXIT_STYLES.map((opt) => (
                                    <option key={opt} value={opt}>{opt}</option>
                                ))}
                            </select>
                        </div>
                        {renderCustomField(
                            inputs.preferredExitStyle === 'Custom',
                            'ai-custom-exit-style',
                            'Describe Preferred Exit Style',
                            inputs.customPreferredExitStyle || '',
                            (v) => patch({ customPreferredExitStyle: v }),
                        )}

                        <div className="col-12">
                            <div className="form-label mb-1">Market Preference</div>
                            <div className="d-flex flex-wrap gap-3">
                                {MARKET_PREFERENCES.map((opt) => {
                                    const id = `ai-market-pref-${opt.replace(/\s+/g, '-').toLowerCase()}`;
                                    const checked = (inputs.marketPreferences || []).includes(opt);
                                    return (
                                        <div className="form-check" key={opt}>
                                            <input
                                                className="form-check-input"
                                                type="checkbox"
                                                id={id}
                                                checked={checked}
                                                onChange={() => toggleMarketPreference(opt)}
                                            />
                                            <label className="form-check-label" htmlFor={id}>{opt}</label>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="col-12">
                            <div className="form-label mb-1">Optimization Priorities</div>
                            <div className="row g-2">
                                {OPTIMIZATION_PRIORITIES.map((opt) => {
                                    const id = `ai-opt-${opt.replace(/\s+/g, '-').toLowerCase()}`;
                                    const checked = (inputs.optimizationPriorities || []).includes(opt);
                                    return (
                                        <div className="col-md-4 col-lg-3" key={opt}>
                                            <div className="form-check">
                                                <input
                                                    className="form-check-input"
                                                    type="checkbox"
                                                    id={id}
                                                    checked={checked}
                                                    onChange={() => toggleOptimization(opt)}
                                                />
                                                <label className="form-check-label" htmlFor={id}>{opt}</label>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="col-md-6">
                            <label className="form-label" htmlFor="ai-complexity">Strategy Complexity</label>
                            <select
                                id="ai-complexity"
                                className="form-select"
                                value={inputs.strategyComplexity}
                                onChange={(e) => patch({ strategyComplexity: e.target.value })}
                            >
                                {STRATEGY_COMPLEXITIES.map((opt) => (
                                    <option key={opt.id} value={opt.id}>{opt.label}</option>
                                ))}
                            </select>
                            {complexityGuidance ? (
                                <div className="form-text">{complexityGuidance}</div>
                            ) : null}
                        </div>

                        <div className="col-md-6">
                            <label className="form-label" htmlFor="ai-explainability">Explainability Level</label>
                            <select
                                id="ai-explainability"
                                className="form-select"
                                value={inputs.explainabilityLevel}
                                onChange={(e) => patch({ explainabilityLevel: e.target.value })}
                            >
                                {EXPLAINABILITY_LEVELS.map((opt) => (
                                    <option key={opt.id} value={opt.id}>{opt.label}</option>
                                ))}
                            </select>
                            {explainGuidance ? (
                                <div className="form-text">{explainGuidance}</div>
                            ) : null}
                        </div>

                        <div className="col-12">
                            <label className="form-label" htmlFor="ai-additional-constraints">
                                Additional Constraints <span className="text-muted">(optional)</span>
                            </label>
                            <textarea
                                id="ai-additional-constraints"
                                className="form-control"
                                rows={4}
                                value={inputs.additionalConstraints || ''}
                                onChange={(e) => patch({ additionalConstraints: e.target.value })}
                                placeholder="Optional constraints, exclusions, or preferences…"
                            />
                        </div>
                    </div>

                    <ManagedStrategyDesigner inputs={inputs} fallback={() => generatePrompt(inputs, templateId)} />
                    <button type="button" className="btn btn-outline-secondary btn-sm mt-3" onClick={handleResetDefaults}>Reset Defaults</button>
                </div>
            ) : null}
        </div>
    );
}
