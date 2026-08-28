import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api';
import TablePagination from '../components/TablePagination';
import useApiGet from '../hooks/useApiGet';
import { runApiMutation } from '../hooks/useApiMutation';
import { reviewReportDetailPath } from '../navigation/routes';
import { formatInrCompactWhole } from '../utils/tableFormat';
import { tosList, tosMeta, tosData } from '../utils/tosEnvelope';
import {
    REVIEW_REPORTS_PAGE_SIZE,
    generateQueryParams,
    metricsByName,
    reportPeriodLabel,
    tosListMetaToTablePagination,
} from '../utils/reviewReports';

function fmtPct(v) {
    if (v == null || Number.isNaN(Number(v))) {
        return '—';
    }
    return `${Number(v).toFixed(2)}%`;
}

function generatedAtLabel(value) {
    if (!value) {
        return '—';
    }
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString();
}

export default function ReviewReportsListPage() {
    const navigate = useNavigate();
    const [page, setPage] = useState(1);
    const [fromDate, setFromDate] = useState('');
    const [toDate, setToDate] = useState('');
    const [generating, setGenerating] = useState(false);

    const { data, loading, reload } = useApiGet({
        errorFallback: 'Failed to load review reports',
        deps: [page],
        request: async () => {
            const response = await api.get('/v1/reviews', {
                skipErrorToast: true,
                params: { page, pageSize: REVIEW_REPORTS_PAGE_SIZE },
            });
            return {
                reports: tosList(response),
                meta: tosMeta(response),
            };
        },
    });

    const reports = data?.reports ?? [];
    const meta = data?.meta ?? {};
    const total = Number(meta.total) || 0;

    const generate = async () => {
        setGenerating(true);
        const params = generateQueryParams(fromDate, toDate);
        const { ok, data: result } = await runApiMutation(async () => {
            const config = { skipErrorToast: true };
            if (Object.keys(params).length > 0) {
                config.params = params;
            }
            return api.post('/v1/reviews/generate', null, config);
        }, { successMessage: 'Review report generated', errorFallback: 'Generate failed' });
        setGenerating(false);
        if (!ok) {
            return;
        }
        const created = tosData(result)?.report;
        if (created?.id) {
            navigate(reviewReportDetailPath(created.id));
            return;
        }
        setPage(1);
        await reload();
    };

    const openReport = (id, event) => {
        if (event) {
            event.stopPropagation();
        }
        navigate(reviewReportDetailPath(id));
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Review reports</h1>
                    <p className="text-muted small mb-0">
                        Stored point-in-time ReviewEngine reports. These values are not the live Review dashboard.
                    </p>
                </div>
            </div>

            <div className="border rounded p-3 mb-3">
                <div className="row g-2 align-items-end">
                    <div className="col-12 col-md-3">
                        <label className="form-label small mb-1" htmlFor="review-report-from">From (optional)</label>
                        <input
                            id="review-report-from"
                            type="date"
                            className="form-control form-control-sm"
                            value={fromDate}
                            onChange={(e) => setFromDate(e.target.value)}
                        />
                    </div>
                    <div className="col-12 col-md-3">
                        <label className="form-label small mb-1" htmlFor="review-report-to">To (optional)</label>
                        <input
                            id="review-report-to"
                            type="date"
                            className="form-control form-control-sm"
                            value={toDate}
                            onChange={(e) => setToDate(e.target.value)}
                        />
                    </div>
                    <div className="col-12 col-md-auto">
                        <button
                            type="button"
                            className="btn btn-primary btn-sm"
                            onClick={generate}
                            disabled={generating || loading}
                        >
                            {generating ? 'Generating…' : 'Generate'}
                        </button>
                    </div>
                    <div className="col-12">
                        <p className="small text-muted mb-0">
                            Leave both dates empty to use the existing 90-day ReviewEngine default. Dates are sent as query parameters, not a JSON body.
                        </p>
                    </div>
                </div>
            </div>

            {loading && !data ? (
                <p className="text-muted">Loading…</p>
            ) : total === 0 ? (
                <div className="border rounded p-4 text-center" data-testid="review-reports-empty">
                    <p className="text-muted mb-3">No stored review reports yet.</p>
                    <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        onClick={generate}
                        disabled={generating}
                    >
                        Generate
                    </button>
                </div>
            ) : (
                <>
                    <div className="table-responsive">
                        <table className="table table-sm table-hover align-middle" data-testid="review-reports-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Period</th>
                                    <th>Generated at</th>
                                    <th>Status</th>
                                    <th>Portfolio value</th>
                                    <th>XIRR</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {reports.map((report) => {
                                    const metrics = metricsByName(report);
                                    const xirr = metrics.xirr != null ? Number(metrics.xirr) : null;
                                    return (
                                        <tr
                                            key={report.id}
                                            role="button"
                                            tabIndex={0}
                                            onClick={() => openReport(report.id)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') {
                                                    e.preventDefault();
                                                    openReport(report.id);
                                                }
                                            }}
                                        >
                                            <td>{report.id}</td>
                                            <td className="small">{reportPeriodLabel(report)}</td>
                                            <td className="small">{generatedAtLabel(report.generated_at)}</td>
                                            <td>{report.status || '—'}</td>
                                            <td>{formatInrCompactWhole(metrics.portfolio_value)}</td>
                                            <td>{fmtPct(xirr != null && !Number.isNaN(xirr) ? xirr * 100 : null)}</td>
                                            <td className="text-end">
                                                <button
                                                    type="button"
                                                    className="btn btn-outline-primary btn-sm"
                                                    onClick={(e) => openReport(report.id, e)}
                                                >
                                                    Open
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <TablePagination
                        meta={tosListMetaToTablePagination(meta)}
                        onPageChange={setPage}
                    />
                </>
            )}
            {loading && data ? (
                <p className="small text-muted">Refreshing…</p>
            ) : null}
        </div>
    );
}
