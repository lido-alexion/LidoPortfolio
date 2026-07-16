import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { formatSchedulerTimestamp } from '../utils/schedulerTimestamp';
import {
    expandGapScanRows,
    formatGapRangeList,
    formatProvidersTried,
    gapDays,
} from '../utils/gapReportUtils';

function formatStatusTime(value) {
    return formatSchedulerTimestamp(value, 'Asia/Kolkata');
}

export default function GapFillFailuresPage() {
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [lastFill, setLastFill] = useState(null);
    const [failureReport, setFailureReport] = useState(null);

    const load = useCallback(async () => {
        setLoadError('');
        try {
            const { data } = await api.get('/universe-price-sync/gaps/failures');
            setLastFill(data.data?.last_fill ?? null);
            setFailureReport(data.data?.last_fill_failure_report ?? null);
        } catch (error) {
            setLoadError(error?.response?.data?.message || 'Failed to load gap fill failures.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const failureRows = failureReport?.failures ?? [];

    return (
        <div className="contentPane">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Gap fill failures</h1>
                    <p className="text-muted small mb-0">
                        Symbols that still had missing OHLCV after the last fill-all run.
                    </p>
                </div>
                <Link to="/settings/universe-price-sync" className="btn btn-outline-secondary btn-sm">
                    Back to universe sync
                </Link>
            </div>

            {loadError && (
                <div className="alert alert-danger" role="alert">{loadError}</div>
            )}

            {loading ? (
                <p className="text-muted">Loading…</p>
            ) : (
                <>
                    <dl className="row small mb-3">
                        <dt className="col-sm-3">Last fill</dt>
                        <dd className="col-sm-9">
                            {lastFill
                                ? (
                                    <>
                                        chunk filled {lastFill.filled ?? 0}, chunk failed {lastFill.failed ?? 0}
                                        {lastFill.still_with_gaps != null ? (
                                            <>
                                                {' '}
                                                ·
                                                {' '}
                                                {lastFill.still_with_gaps}
                                                {' '}
                                                still gapped after run
                                            </>
                                        ) : null}
                                    </>
                                )
                                : '—'}
                        </dd>
                        <dt className="col-sm-3">Failure report</dt>
                        <dd className="col-sm-9">
                            {failureReport
                                ? (
                                    <>
                                        {failureReport.unresolved ?? failureRows.length}
                                        {' '}
                                        unresolved
                                        {failureReport.resolved != null ? ` · ${failureReport.resolved} resolved` : ''}
                                        {failureReport.completed_at ? (
                                            <>
                                                {' '}
                                                · completed
                                                {' '}
                                                {formatStatusTime(failureReport.completed_at)}
                                            </>
                                        ) : null}
                                    </>
                                )
                                : 'No failure report stored.'}
                        </dd>
                    </dl>

                    {failureRows.length === 0 ? (
                        <p className="text-muted">No fill failures recorded.</p>
                    ) : (
                        <div className="table-responsive border rounded">
                            <table className="table table-sm table-striped mb-0 small">
                                <thead className="sticky-top">
                                    <tr>
                                        <th>Stock</th>
                                        <th>Exchange</th>
                                        <th>Gap start</th>
                                        <th>Gap end</th>
                                        <th>Gap days</th>
                                        <th>Still missing</th>
                                        <th>Providers tried</th>
                                        <th>Errors</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {failureRows.flatMap((row) => {
                                        const remaining = row.remaining_ranges ?? [];
                                        if (remaining.length === 0) {
                                            return [(
                                                <tr key={`${row.stock_id}-summary`}>
                                                    <td className="text-nowrap">{row.symbol}</td>
                                                    <td>{row.exchange ?? '—'}</td>
                                                    <td colSpan={2}>{formatGapRangeList(row.attempted_ranges)}</td>
                                                    <td>—</td>
                                                    <td>—</td>
                                                    <td className="text-nowrap">{formatProvidersTried(row.providers_tried)}</td>
                                                    <td>{(row.errors ?? []).slice(0, 3).join(' · ') || '—'}</td>
                                                </tr>
                                            )];
                                        }

                                        return remaining.map((range) => (
                                            <tr key={`${row.stock_id}-${range.from}-${range.to}`}>
                                                <td className="text-nowrap">{row.symbol}</td>
                                                <td>{row.exchange ?? '—'}</td>
                                                <td>{range.from}</td>
                                                <td>{range.to}</td>
                                                <td>{gapDays(range.from, range.to)}</td>
                                                <td>{formatGapRangeList([range])}</td>
                                                <td className="text-nowrap">{formatProvidersTried(row.providers_tried)}</td>
                                                <td>{(row.errors ?? []).slice(0, 3).join(' · ') || '—'}</td>
                                            </tr>
                                        ));
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
