import React from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api';
import useApiGet from '../hooks/useApiGet';
import { ROUTES } from '../navigation/routes';
import { formatInrCompactWhole, formatTableInteger } from '../utils/tableFormat';
import { tosData } from '../utils/tosEnvelope';
import {
    METRIC_LABELS,
    NAMED_CARD_METRIC_KEYS,
    SNAPSHOT_METRIC_KEYS,
    formatMetricValue,
    isoDatePart,
    methodologyEntries,
    metricLabel,
    metricsByName,
    remainingMetricKeys,
    reportPeriodLabel,
} from '../utils/reviewReports';

function fmtPct(v) {
    if (v == null || Number.isNaN(Number(v))) {
        return '—';
    }
    return `${Number(v).toFixed(2)}%`;
}

function fmtNum(v) {
    if (v == null || Number.isNaN(Number(v))) {
        return '—';
    }
    return Number(v).toFixed(2);
}

const formatters = {
    fmtPct,
    fmtNum,
    formatMoney: formatInrCompactWhole,
    formatCount: formatTableInteger,
};

function generatedAtLabel(value) {
    if (!value) {
        return '—';
    }
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString();
}

function MetricCard({ title, children }) {
    return (
        <div className="col-md-3">
            <div className="border rounded p-3 h-100">
                <div className="text-muted small">{title}</div>
                <div className="mt-1">{children}</div>
            </div>
        </div>
    );
}

export default function ReviewReportDetailPage() {
    const { id } = useParams();
    const { data, loading, error } = useApiGet({
        errorFallback: 'Review report not found',
        deps: [id],
        request: async () => {
            const response = await api.get(`/v1/reviews/${id}`, { skipErrorToast: true });
            return tosData(response);
        },
    });

    const notFound = !loading && Boolean(error) && (
        error?.response?.status === 404 || !data
    );
    const report = notFound ? null : data;
    const metrics = metricsByName(report);
    const remaining = remainingMetricKeys(report);
    const methodology = methodologyEntries(report);

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Review report</h1>
                    <p className="text-muted small mb-0">
                        Stored ReviewEngine snapshot. These numbers are not the live Review dashboard.
                    </p>
                </div>
                <Link className="btn btn-outline-secondary btn-sm" to={ROUTES.REVIEW_REPORTS}>
                    Back to reports
                </Link>
            </div>

            {loading && !report ? (
                <p className="text-muted">Loading…</p>
            ) : notFound ? (
                <div data-testid="review-report-not-found">
                    <p className="text-muted">Review report not found.</p>
                    <Link className="btn btn-outline-secondary btn-sm" to={ROUTES.REVIEW_REPORTS}>
                        Back to reports
                    </Link>
                </div>
            ) : !report ? (
                <p className="text-muted">Review report not found.</p>
            ) : (
                <>
                    <div className="border rounded p-3 mb-4">
                        <div className="row g-2 small">
                            <div className="col-md-3"><span className="text-muted">Report ID</span><div>{report.id}</div></div>
                            <div className="col-md-3"><span className="text-muted">Period</span><div>{reportPeriodLabel(report)}</div></div>
                            <div className="col-md-3">
                                <span className="text-muted">Period start / end</span>
                                <div>{isoDatePart(report.period_start) || '—'} / {isoDatePart(report.period_end) || '—'}</div>
                            </div>
                            <div className="col-md-3"><span className="text-muted">Generated at</span><div>{generatedAtLabel(report.generated_at)}</div></div>
                            <div className="col-md-3"><span className="text-muted">Status</span><div>{report.status || '—'}</div></div>
                        </div>
                    </div>

                    <h2 className="h6 text-muted text-uppercase mb-2">Portfolio snapshot (report)</h2>
                    <div className="row g-3 mb-4">
                        {SNAPSHOT_METRIC_KEYS.map((key) => (
                            <MetricCard key={key} title={METRIC_LABELS[key]}>
                                <div className="fs-5">{formatMetricValue(key, metrics[key], formatters)}</div>
                            </MetricCard>
                        ))}
                    </div>

                    <h2 className="h6 text-muted text-uppercase mb-2">Named metrics (report)</h2>
                    <div className="row g-3 mb-4">
                        {NAMED_CARD_METRIC_KEYS.map((key) => (
                            <MetricCard key={key} title={METRIC_LABELS[key]}>
                                <div className="fs-5">{formatMetricValue(key, metrics[key], formatters)}</div>
                            </MetricCard>
                        ))}
                    </div>

                    <h2 className="h5">Other persisted metrics</h2>
                    <div className="table-responsive mb-4">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Metric</th>
                                    <th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                {remaining.length === 0 ? (
                                    <tr><td colSpan={2} className="text-muted">No additional persisted metrics.</td></tr>
                                ) : remaining.map((key) => (
                                    <tr key={key}>
                                        <td>{metricLabel(key)}</td>
                                        <td>{formatMetricValue(key, metrics[key], formatters)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <h2 className="h5">Methodology</h2>
                    <p className="small text-muted">Stored ReviewEngine methodology strings. Not rewritten.</p>
                    {methodology.length === 0 ? (
                        <p className="text-muted">No methodology on this report.</p>
                    ) : (
                        <div className="table-responsive">
                            <table className="table table-sm align-middle" data-testid="review-report-methodology">
                                <thead>
                                    <tr>
                                        <th>Key</th>
                                        <th>Methodology</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {methodology.map((row) => (
                                        <tr key={row.key}>
                                            <td className="small">{row.key}</td>
                                            <td>{row.text}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
