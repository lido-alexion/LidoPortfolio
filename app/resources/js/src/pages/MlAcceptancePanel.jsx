import React, { useEffect, useRef, useState } from 'react';
import useMlAcceptanceWizard from './ml-acceptance/useMlAcceptanceWizard';
import AcceptanceStepper, { stepDefinitions } from './ml-acceptance/components/AcceptanceStepper';
import ReadinessStep from './ml-acceptance/components/ReadinessStep';
import DiscoveryPreflightStep from './ml-acceptance/components/DiscoveryPreflightStep';
import HistoricalSourcesStep from './ml-acceptance/components/HistoricalSourcesStep';
import BackfillStep from './ml-acceptance/components/BackfillStep';
import FinalPreflightStep from './ml-acceptance/components/FinalPreflightStep';
import CandidateTrainingStep from './ml-acceptance/components/CandidateTrainingStep';
import AcceptanceReviewStep from './ml-acceptance/components/AcceptanceReviewStep';
import AdvancedDiagnostics from './ml-acceptance/components/AdvancedDiagnostics';
import api from '../api';
import { canAdvance } from './ml-acceptance/reasonStatus';
import { describeAcceptanceReason } from './ml-acceptance/acceptanceReasonRegistry';
import './ml-acceptance/acceptanceWizard.css';

const today = () => new Date().toISOString().slice(0, 10);

export default function MlAcceptancePanel() {
    const state = useMlAcceptanceWizard();
    const { wizard, base, sourcesPage, page, setPage, busyAction, error, stale, lastChecked, refresh, run } = state;
    const [activeStep, setActiveStep] = useState(null);
    const [cutoff, setCutoff] = useState(today);
    const [selectedSources, setSelectedSources] = useState([]);
    const [selectedSourceDetails, setSelectedSourceDetails] = useState({});
    const [finished, setFinished] = useState(false);
    const heading = useRef(null);
    const errorSummary = useRef(null);
    const priorServerStep = useRef(null);
    const autoSelectedOptions = useRef('');
    const expanded = activeStep || wizard?.current_step || 1;
    const report = wizard ? {
        migrations: wizard.migrations || {},
        evidence_current: wizard.evidence_current === true,
    } : null;

    useEffect(() => {
        if (!wizard) return;
        if (priorServerStep.current === null || priorServerStep.current !== wizard.current_step) {
            setActiveStep(wizard.current_step);
            priorServerStep.current = wizard.current_step;
        }
        if (wizard.campaign?.cutoff_date) setCutoff(wizard.campaign.cutoff_date);
    }, [wizard?.current_step, wizard?.campaign?.cutoff_date]);

    useEffect(() => {
        if (activeStep !== null) heading.current?.focus();
    }, [activeStep]);

    useEffect(() => { if (error) errorSummary.current?.focus(); }, [error]);

    const statusFor = (number) => wizard?.steps?.[number - 1]?.status || 'not_started';
    const sourceOptionsKey = JSON.stringify([wizard?.campaign?.id || null, (wizard?.required_sources || []).map((requirement) => [
        requirement.date, (requirement.sources || []).filter((source) => source.status === 'sealed' && requirement.accepted_families?.includes(source.family)).map((source) => source.id),
    ])]);
    useEffect(() => {
        if (!wizard || autoSelectedOptions.current === sourceOptionsKey) return;
        autoSelectedOptions.current = sourceOptionsKey;
        const requirements = wizard.required_sources || [];
        const retained = selectedSources.filter((id) => {
            const source = selectedSourceDetails[id];
            return source?.status === 'sealed' && requirements.some((requirement) => requirement.date === source.date && requirement.accepted_families?.includes(source.family));
        });
        const additions = [];
        for (const requirement of requirements) {
            const alreadySelected = retained.some((id) => selectedSourceDetails[id]?.date === requirement.date);
            if (alreadySelected) continue;
            const source = requirement.sources?.find((item) => item.status === 'sealed' && requirement.accepted_families?.includes(item.family));
            if (source) additions.push({ id: source.id, status: source.status, family: source.family, date: source.date });
        }
        setSelectedSources([...retained, ...additions.map((source) => source.id)]);
        setSelectedSourceDetails((previous) => ({ ...Object.fromEntries(retained.map((id) => [id, previous[id]])), ...Object.fromEntries(additions.map((source) => [source.id, source])) }));
    }, [sourceOptionsKey, wizard, selectedSources, selectedSourceDetails]);
    const selectedRecords = selectedSources.map((id) => selectedSourceDetails[id]).filter((source) => source?.status === 'sealed');
    const localBackfillReady = Boolean(wizard?.required_sources?.length)
        && selectedRecords.length === wizard.required_sources.length
        && wizard.required_sources.every((requirement) => selectedRecords.some((source) => source.date === requirement.date && requirement.accepted_families?.includes(source.family)));
    const canMutate = (number) => (wizard?.current_step === number || (number === 4 && wizard?.current_step === 3 && localBackfillReady)) && !stale;
    const currentStatus = statusFor(expanded);
    const canContinue = canAdvance(currentStatus) || (expanded === 3 && localBackfillReady);
    const previous = () => setActiveStep(Math.max(1, expanded - 1));
    const next = () => {
        if (canContinue) setActiveStep(Math.min(7, expanded + 1));
    };
    const cancelCampaign = () => run('campaign-cancel', () => api.post(`${base}/campaigns/${wizard.campaign.id}/cancel`));

    return <section className="card mb-3 acceptance-wizard" aria-labelledby="acceptance-wizard-title">
        <div className="card-body">
            <h2 className="h5" id="acceptance-wizard-title">Production ML acceptance</h2>
            <p className="text-muted">This wizard records production evidence. It will not activate a model or enable schedules.</p>
            {error && <div className="alert alert-danger" role="alert" tabIndex={-1} ref={errorSummary}>{error}</div>}
            {!wizard && !error && <p role="status">Loading saved acceptance state…</p>}
            {!wizard && error && <button type="button" className="btn btn-outline-secondary mb-3" onClick={refresh}>Retry status</button>}
            {wizard && <>
                <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <p className={`mb-0 ${stale ? 'text-warning' : 'text-muted'}`}>{stale ? 'Showing last known state; it may be stale.' : 'Server state loaded.'} {lastChecked && <>Last checked <time dateTime={lastChecked.toISOString()}>{lastChecked.toLocaleTimeString()}</time>.</>}</p>
                    <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busyAction !== ''} onClick={refresh}>Refresh</button>
                </div>
                {finished ? <div className="alert alert-success acceptance-completion-summary" role="status"><h3 className="h6">Production acceptance review finished</h3><p className="mb-2">{wizard.campaign?.status === 'qualified' && wizard.evidence_current === true ? 'Production acceptance is current for this build and configuration.' : 'The campaign review is saved. Check the status and evidence before taking any separate model or lifecycle action.'}</p><button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => { setFinished(false); setActiveStep(7); }}>Reopen completion review</button></div> : <>
                <AcceptanceStepper steps={wizard.steps} currentStep={wizard.current_step} activeStep={expanded} onSelect={setActiveStep} />
                <div className="acceptance-content" aria-live="off">
                    {[1, 2, 3, 4, 5, 6, 7].map((number) => {
                        const [id, title] = stepDefinitions[number - 1];
                        const isOpen = expanded === number;
                        const step = wizard.steps?.[number - 1] || {};
                            const blockedFuture = number > wizard.current_step && !canAdvance(step.status) && !(number === 4 && localBackfillReady);
                        const reasonMessages = (step.reason_codes || []).map((code) => ({ code, ...describeAcceptanceReason(String(code).split(':')[0]) }));
                        return <div key={id} className="acceptance-step-wrapper" hidden={!isOpen}>
                            {isOpen && <>
                            <h2 ref={heading} tabIndex={-1} className="visually-hidden">{title}</h2>
                            {blockedFuture && <div className="alert alert-info"><strong>This step is waiting for a prerequisite.</strong> Complete the current step first. No mutation controls are available here.</div>}
                            {reasonMessages.length > 0 && <div className="alert alert-warning"><h3 className="h6">Why this step needs attention</h3><ul className="mb-0">{reasonMessages.map((reason, i) => <li key={`${reason.code}-${i}`}><strong>{reason.title}:</strong> {reason.explanation} <code>{reason.code}</code></li>)}</ul></div>}
                            {number === 1 && <ReadinessStep wizard={wizard} report={report} onRefresh={refresh} canMutate={canMutate(1)} busy={busyAction !== ''} />}
                            {number === 2 && <DiscoveryPreflightStep wizard={wizard} cutoff={cutoff} setCutoff={setCutoff} onRun={() => run('discovery', () => api.post(`${base}/campaigns`, { cutoff_date: cutoff }))} onCancel={cancelCampaign} busy={busyAction !== ''} canMutate={canMutate(2)} />}
                            {number === 3 && <HistoricalSourcesStep wizard={wizard} sourcesPage={sourcesPage} page={page} setPage={setPage} base={base} run={run} busy={busyAction !== ''} canMutate={canMutate(3)} selected={selectedSources} setSelected={setSelectedSources} selectedDetails={selectedSourceDetails} onSourceToggle={(source, checked) => setSelectedSourceDetails((details) => { const nextDetails = { ...details }; if (checked) nextDetails[source.id] = { id: source.id, status: source.status, family: source.manifest.source, date: source.manifest.date }; else delete nextDetails[source.id]; return nextDetails; })} />}
                            {number === 4 && <BackfillStep wizard={wizard} selectedSources={selectedSources} base={base} api={api} run={run} busy={busyAction !== ''} canMutate={canMutate(4)} />}
                            {number === 5 && <FinalPreflightStep wizard={wizard} cutoff={cutoff} setCutoff={setCutoff} run={run} base={base} api={api} busy={busyAction !== ''} canMutate={canMutate(5)} />}
                            {number === 6 && <CandidateTrainingStep wizard={wizard} base={base} api={api} run={run} busy={busyAction !== ''} canMutate={canMutate(6)} onCancel={cancelCampaign} />}
                            {number === 7 && <AcceptanceReviewStep wizard={wizard} report={report} onFinish={() => setFinished(true)} finished={finished} />}
                            <div className="d-flex justify-content-between mt-3">
                                <button type="button" className="btn btn-outline-secondary" onClick={previous} disabled={number <= 1}>Back</button>
                                {number < 7 && <button type="button" className="btn btn-primary" onClick={next} disabled={!canContinue}>Continue</button>}
                            </div>
                            </>}
                        </div>;
                    })}
                </div>
                </>}
                <AdvancedDiagnostics data={wizard} report={report} />
            </>}
        </div>
    </section>;
}
