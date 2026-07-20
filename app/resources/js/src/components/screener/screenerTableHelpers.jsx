import React, { useState } from 'react';

const DESCRIPTION_PREVIEW_LEN = 100;

export function ScreenerDescriptionCell({ text }) {
    const [expanded, setExpanded] = useState(false);
    const value = (text || '').trim();

    if (!value) {
        return '—';
    }

    if (value.length <= DESCRIPTION_PREVIEW_LEN) {
        return <span className="small">{value}</span>;
    }

    if (expanded) {
        return (
            <span className="small">
                {value}
                {' '}
                <button
                    type="button"
                    className="btn btn-link btn-sm p-0 align-baseline"
                    onClick={() => setExpanded(false)}
                >
                    Less
                </button>
            </span>
        );
    }

    return (
        <span className="small">
            {value.slice(0, DESCRIPTION_PREVIEW_LEN)}
            …
            {' '}
            <button
                type="button"
                className="btn btn-link btn-sm p-0 align-baseline"
                onClick={() => setExpanded(true)}
            >
                more
            </button>
        </span>
    );
}

export function scopeLabel(scope) {
    if (scope === 'watchlist') return 'Watchlist';
    if (scope === 'all_equities') return 'All equities';
    return 'Holdings';
}

export function scopeDisplay(row) {
    const base = scopeLabel(row.scope);
    if (row.scope === 'watchlist' && row.watchlist?.name) {
        return `${base} · ${row.watchlist.name}`;
    }
    if (row.scope === 'watchlist' && row.watchlist_issue) {
        return `${base} · (missing)`;
    }
    return base;
}

export function lastRunSummaryText(stats) {
    if (!stats) return '';
    const parts = [`${stats.matched ?? 0} matched`, `${stats.scanned ?? 0} scanned`];
    if ((stats.skipped_insufficient_data ?? 0) > 0) {
        parts.push(`${stats.skipped_insufficient_data} skipped`);
    }
    if ((stats.errors ?? 0) > 0) {
        parts.push(`${stats.errors} error(s)`);
    }
    return parts.join(' · ');
}

export function runTriggerLabel(triggeredBy) {
    if (triggeredBy === 'schedule') return 'Scheduled';
    return 'Manual';
}

export function formatRunWhen(run) {
    if (run?.finished_at) {
        return new Date(run.finished_at).toLocaleString();
    }
    if (run?.started_at) {
        return `Started ${new Date(run.started_at).toLocaleString()}`;
    }
    return 'In progress';
}

export function formatRunListLabel(run) {
    return `Run ID ${run.id} · ${runTriggerLabel(run.triggered_by)} · ${run.status}`;
}

export function formatRunResultsHeading(run, { isLatest = false } = {}) {
    const parts = [
        `Run ID ${run.id}`,
        runTriggerLabel(run.triggered_by),
        run.status,
        formatRunWhen(run),
    ];
    return {
        title: parts.join(' · '),
        isLatest,
    };
}

export function runStatsWarning(stats, errorMessage = null) {
    const warnings = stats?.warnings ?? [];
    if (warnings.length > 0) {
        return warnings.join(' ');
    }
    if ((stats?.errors ?? 0) > 0) {
        return `${stats.errors} stock evaluation error(s).`;
    }
    if (errorMessage) {
        return errorMessage;
    }
    return null;
}

export function lastRunWarningMessage(row) {
    const fromRun = runStatsWarning(row.last_run?.stats, row.last_run?.error_message);
    if (fromRun) {
        return fromRun;
    }
    if (row.watchlist_issue) {
        return row.watchlist_issue;
    }
    return null;
}

export function LastRunSummaryCell({ row }) {
    const at = row.last_run_at;
    if (!at) {
        return row.watchlist_issue
            ? (
                <div className="small">
                    <div>Never</div>
                    <div className="text-danger" title={row.watchlist_issue}>
                        ⚠ {row.watchlist_issue}
                    </div>
                </div>
            )
            : 'Never';
    }

    const stats = row.last_run?.stats;
    const warning = lastRunWarningMessage(row);

    return (
        <div className="small">
            <div>{new Date(at).toLocaleString()}</div>
            {stats && (
                <div className="text-muted">{lastRunSummaryText(stats)}</div>
            )}
            {warning && (
                <div className="text-warning-emphasis" title={warning}>
                    ⚠ {warning}
                </div>
            )}
        </div>
    );
}

export function scheduleDisplay(row) {
    if (!row.schedule_enabled) {
        return '';
    }
    return row.schedule_time || '—';
}
