import React from 'react';

export const stepDefinitions = [
    ['readiness', 'Understand & check readiness'], ['discovery', 'Discover required data'], ['sources', 'Add historical sources'],
    ['backfill', 'Preview & apply backfill'], ['final_preflight', 'Run final preflight'], ['training', 'Train candidates'], ['review', 'Review completion'],
];
const labels = { not_started: 'Not started', action_required: 'Action required', in_progress: 'In progress', blocked: 'Blocked', failed: 'Failed', cancelled: 'Cancelled', complete: 'Complete', not_required: 'Not required' };

export default function AcceptanceStepper({ steps = [], currentStep, activeStep, onSelect }) {
    return <nav aria-label="Production acceptance steps" className="acceptance-stepper">
        <ol className="list-unstyled mb-0">
            {stepDefinitions.map(([id, title], index) => {
                const number = index + 1;
                const status = steps[index]?.status || 'not_started';
                return <li key={id} className={activeStep === number ? 'is-open' : ''}>
                    <button type="button" className="btn btn-link text-start w-100" aria-current={activeStep === number ? 'step' : undefined} onClick={() => onSelect(number)}>
                        <span className="acceptance-step-number" aria-hidden="true">{status === 'complete' || status === 'not_required' ? '✓' : number}</span>
                        <span><strong>{title}</strong><small className="d-block">{labels[status] || status}</small></span>
                    </button>
                </li>;
            })}
        </ol>
        <span className="visually-hidden">Server current step: {currentStep || 1}</span>
    </nav>;
}
