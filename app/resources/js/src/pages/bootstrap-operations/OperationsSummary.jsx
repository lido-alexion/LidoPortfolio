import React from 'react';

const show = (value) => {
    if (value === null || value === undefined || value === '') return 'Not available';
    if (typeof value === 'object') return value.label || value.name || value.scheduled_at || value.next_run_at || (value.id ? `Run #${value.id}` : 'Not available');
    return value;
};
export default function OperationsSummary({ health, coverage }) {
    const summary = health?.health ?? health ?? {};
    const covered = coverage?.coverage_summary ?? summary.coverage_summary ?? coverage?.summary ?? coverage?.coverage;
    const cards = [
        ['Active run', summary.active_run?.id ? `Run #${summary.active_run.id} · ${summary.active_run.status || 'in progress'}` : (typeof summary.active_run==='number'?`Run #${summary.active_run}`:summary.active_run === null ? 'None' : 'Not available')],
        ['Queued runs', summary.queued_count],
        ['Last successful run', summary.last_successful_run?.id ? `Run #${summary.last_successful_run.id}${summary.last_successful_run.completed_at ? ` · ${new Date(summary.last_successful_run.completed_at).toLocaleDateString()}` : ''}` : summary.last_successful_run],
        ['Unresolved failures', summary.unresolved_failed_items],
        ['Unresolved warnings', summary.unresolved_warnings],
        ['Next scheduled run', summary.next_schedule?.scheduled_at || summary.next_schedule?.next_run_at || summary.next_schedule],
    ];
    return <section className="bo-summary" aria-labelledby="bo-summary-title">
        <div className="bo-section-heading"><div><h2 id="bo-summary-title">Operations at a glance</h2><p>Current run activity, outstanding review, and coverage.</p></div></div>
        <div className="bo-summary-grid">{cards.map(([label, value]) => <article className="bo-summary-card" key={label}><span>{label}</span><strong>{show(value)}</strong></article>)}</div>
        <div className="bo-coverage-strip"><strong>Coverage</strong>{Array.isArray(covered) ? covered.slice(0, 5).map((row) => <span key={`${row.metric}-${row.cadence}`}>{row.metric} · {row.cadence}: {row.coverage_percent ?? row.coverage ?? 'Unknown'}{row.coverage_percent != null ? '%' : ''}</span>) : <span>{show(covered?.label ?? covered?.status ?? covered)}</span>}<small>Only observed coverage is shown. Expected periods or metric applicability may be unknown.</small></div>
    </section>;
}
