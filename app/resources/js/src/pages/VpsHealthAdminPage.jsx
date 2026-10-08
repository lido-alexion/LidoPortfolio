import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import api from '../api';
import { getVpsFpmStatus, getVpsHealthMetricStatus, VPS_HEALTH_STATUS } from './vpsHealthMetricStatus';

const RANGE_OPTIONS = [
    { hours: 1, label: '1 hour' },
    { hours: 6, label: '6 hours' },
    { hours: 24, label: '24 hours' },
    { hours: 72, label: '3 days' },
];

function formatTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    const parts = new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }).formatToParts(date);
    const part = (type) => parts.find((item) => item.type === type)?.value ?? '';
    return `${part('day')}-${part('month')}-${part('year')} ${part('hour')}:${part('minute')}:${part('second')} ${part('dayPeriod').toUpperCase()}`;
}

function formatAge(seconds) {
    if (seconds === null || seconds === undefined) return 'No samples yet';
    const roundedSeconds = Math.ceil(seconds);
    if (roundedSeconds < 60) {
        const unit = roundedSeconds === 1 ? 'second' : 'seconds';
        return `${roundedSeconds} ${unit} ago`;
    }
    return `${Math.floor(roundedSeconds / 60)}m ago`;
}

function number(value, digits = 1) {
    return value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value))
        ? Number(value).toFixed(digits)
        : '—';
}

function MetricCard({ label, value, detail, status = 'unknown', threshold }) {
    const severity = VPS_HEALTH_STATUS[status] ?? VPS_HEALTH_STATUS.unknown;
    return (
        <div className="col-6 col-xl-3">
            <div className={`card h-100 border-${severity.color}`} title={threshold}>
                <div className="card-body">
                    <div className="d-flex align-items-start justify-content-between gap-2 mb-1">
                        <div className="small text-muted">{label}</div>
                        <span className={`badge text-bg-${severity.color}`}>{severity.label}</span>
                    </div>
                    <div className={`fs-4 fw-semibold text-${severity.color}`}>{value}</div>
                    {detail && <div className="small text-muted mt-1">{detail}</div>}
                </div>
            </div>
        </div>
    );
}

function TrendChart({ title, data, lines, ySuffix = '' }) {
    return (
        <div className="card h-100">
            <div className="card-header fw-semibold">{title}</div>
            <div className="card-body" style={{ height: 260 }}>
                {data.length === 0 ? <div className="text-muted small">No samples in this range.</div> : (
                    <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={data} margin={{ top: 8, right: 12, left: -18, bottom: 0 }}>
                            <CartesianGrid stroke="var(--bs-border-color)" strokeDasharray="3 3" />
                            <XAxis dataKey="time" minTickGap={32} tickFormatter={formatTime} tick={{ fontSize: 11, fill: 'var(--bs-secondary-color)' }} />
                            <YAxis width={48} tick={{ fontSize: 11, fill: 'var(--bs-secondary-color)' }} tickFormatter={(value) => `${value}${ySuffix}`} />
                            <Tooltip labelFormatter={(value) => formatTime(value)} formatter={(value, name) => [`${number(value)}${ySuffix}`, name]} />
                            <Legend />
                            {lines.map((line) => (
                                <Line key={line.key} type="monotone" dataKey={line.key} name={line.name} stroke={line.color} dot={false} connectNulls isAnimationActive={false} />
                            ))}
                        </LineChart>
                    </ResponsiveContainer>
                )}
            </div>
        </div>
    );
}

export default function VpsHealthAdminPage() {
    const [hours, setHours] = useState(24);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState('');

    const load = useCallback(async (quiet = false) => {
        if (quiet) setRefreshing(true);
        else setLoading(true);
        try {
            const response = await api.get('/v1/admin/vps-health', { params: { hours } });
            setData(response.data?.data ?? null);
            setError('');
        } catch (requestError) {
            setError(requestError?.response?.data?.message || 'Could not load VPS health samples.');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, [hours]);

    useEffect(() => {
        load();
        const timer = window.setInterval(() => load(true), 60_000);
        return () => window.clearInterval(timer);
    }, [load]);

    const latest = data?.latest;
    const metrics = latest?.metrics ?? {};
    const fpm = metrics.fpm ?? {};
    const nginx = metrics.nginx ?? {};
    const samples = useMemo(() => (data?.samples ?? []).map((sample) => {
        const timestamp = sample.sampled_at;
        const metric = sample.metrics ?? {};
        return {
            time: new Date(timestamp).getTime(),
            load: metric.load_per_core,
            ram: metric.ram_available_percent,
            disk: metric.root_used_percent,
            swap: metric.swap_used_percent,
            nginx499: metric.nginx?.['499'] ?? 0,
            nginx5xx: (metric.nginx?.['502'] ?? 0) + (metric.nginx?.['503'] ?? 0) + (metric.nginx?.['504'] ?? 0),
            fpmQueue: metric.fpm?.['listen queue'] ?? 0,
        };
    }), [data]);
    const stale = data?.last_sample_age_seconds == null || data.last_sample_age_seconds > 180;
    const healthStatus = !latest ? 'No data' : stale ? 'Stale data' : latest.status === 'critical' ? 'Critical' : 'Healthy';
    const statusClass = !latest || stale ? 'bg-warning text-dark' : latest.status === 'critical' ? 'bg-danger' : 'bg-success';

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                <div>
                    <h1 className="h3 mb-1">VPS Health</h1>
                    <div className="text-muted small">Host, PHP-FPM, and Nginx samples from the StoX health monitor.</div>
                </div>
                <div className="d-flex align-items-center gap-2">
                    <div className="btn-group" role="group" aria-label="History range">
                        {RANGE_OPTIONS.map((option) => (
                            <button key={option.hours} type="button" className={`btn btn-sm ${hours === option.hours ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setHours(option.hours)}>
                                {option.label}
                            </button>
                        ))}
                    </div>
                    <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => load(true)} disabled={refreshing}>
                        {refreshing ? 'Refreshing…' : 'Refresh'}
                    </button>
                </div>
            </div>

            {error && <div className="alert alert-warning" role="alert">{error}</div>}
            {loading && !data ? <div className="text-muted py-4">Loading VPS health…</div> : (
                <>
                    <div className="card mb-3">
                        <div className="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <span className={`badge ${statusClass} me-2`}>{healthStatus}</span>
                                <span className="text-muted small">Last sample: {formatTime(latest?.sampled_at)} · {formatAge(data?.last_sample_age_seconds)}</span>
                            </div>
                            <span className="text-muted small">Auto-refreshes every minute · {data?.sample_count ?? 0} samples shown</span>
                        </div>
                    </div>

                    {!latest ? (
                        <div className="alert alert-info">The dashboard will populate after the VPS health monitor records its first sample.</div>
                    ) : (
                        <>
                            {latest.issues?.length > 0 && (
                                <div className={`alert ${stale ? 'alert-warning' : 'alert-danger'}`}>
                                    <div className="fw-semibold mb-1">Latest monitor findings</div>
                                    <ul className="mb-0">{latest.issues.map((issue, index) => <li key={`${index}-${issue}`}>{issue}</li>)}</ul>
                                </div>
                            )}

                            <div className="small d-flex flex-wrap gap-2 mb-2" aria-label="Metric color legend">
                                <span className="badge text-bg-success">Green: normal</span>
                                <span className="badge text-bg-warning">Amber: attention</span>
                                <span className="badge text-bg-danger">Red: action needed</span>
                            </div>
                            <div className="row g-3 mb-2">
                                <MetricCard label="Load per core" value={number(metrics.load_per_core, 2)} status={getVpsHealthMetricStatus('loadPerCore', metrics.load_per_core)} threshold="Green <1.0 · Amber 1.0–<2.0 · Red ≥2.0" detail={`1 min ${number(metrics.load1, 2)} · ${metrics.cpus ?? '—'} CPUs`} />
                                <MetricCard label="Available RAM" value={`${number(metrics.ram_available_percent)}%`} status={getVpsHealthMetricStatus('ramAvailable', metrics.ram_available_percent)} threshold="Green ≥20% · Amber 10–<20% · Red <10%" detail={`${number(metrics.ram_available_bytes == null ? null : metrics.ram_available_bytes / (1024 ** 3), 2)} GiB available`} />
                                <MetricCard label="Root disk used" value={`${number(metrics.root_used_percent)}%`} status={getVpsHealthMetricStatus('rootDiskUsed', metrics.root_used_percent)} threshold="Green <80% · Amber 80–<90% · Red ≥90%" />
                                <MetricCard label="Swap used" value={`${number(metrics.swap_used_percent)}%`} status={getVpsHealthMetricStatus('swapUsed', metrics.swap_used_percent)} threshold="Green <50% · Amber 50–80% · Red >80%" />
                                <MetricCard label="FPM workers" value={`${fpm['active processes'] ?? '—'} / ${fpm['max children'] ?? '—'}`} status={getVpsFpmStatus({ queue: fpm['listen queue'], active: fpm['active processes'], maxChildren: fpm['max children'] })} threshold="Green: queue 0 and workers below 90% capacity · Amber: queue 0 and workers ≥90% · Red: any queued request" detail={`Idle ${fpm['idle processes'] ?? '—'} · Queue ${fpm['listen queue'] ?? '—'}`} />
                                <MetricCard label="Nginx errors (5 min)" value={(nginx['502'] ?? 0) + (nginx['503'] ?? 0) + (nginx['504'] ?? 0)} status={getVpsHealthMetricStatus('nginx5xx', (nginx['502'] ?? 0) + (nginx['503'] ?? 0) + (nginx['504'] ?? 0))} threshold="Green 0 · Amber 1–4 · Red ≥5" detail={`499 client closes: ${nginx['499'] ?? 0}`} />
                            </div>
                            <details className="small text-muted mb-3">
                                <summary>Color thresholds</summary>
                                <ul className="mt-2 mb-1">
                                    <li>Load per core: green below 1.0; amber from 1.0 to below 2.0; red at 2.0 or higher.</li>
                                    <li>Available RAM: green at 20% or more; amber from 10% to below 20%; red below 10%.</li>
                                    <li>Root disk used: green below 80%; amber from 80% to below 90%; red at 90% or more.</li>
                                    <li>Swap used: green below 50%; amber from 50% through 80%; red above 80%.</li>
                                    <li>FPM: green with no queue and workers below 90% of capacity; amber with no queue and workers at 90% or more; red if any request is queued.</li>
                                    <li>Nginx 5xx errors in 5 minutes: green at 0; amber from 1–4; red at 5 or more.</li>
                                </ul>
                                <div>Colors reflect the latest sample. Load and worker-saturation alerts require repeated checks before the monitor raises a critical alert.</div>
                            </details>

                            <div className="row g-3 mb-3">
                                <div className="col-xl-6">
                                    <TrendChart title="Resource trends" data={samples} ySuffix="%" lines={[
                                        { key: 'ram', name: 'RAM available', color: '#0d6efd' },
                                        { key: 'disk', name: 'Disk used', color: '#dc3545' },
                                        { key: 'swap', name: 'Swap used', color: '#fd7e14' },
                                    ]} />
                                </div>
                                <div className="col-xl-6">
                                    <TrendChart title="Load per core" data={samples} lines={[
                                        { key: 'load', name: 'Load per core', color: '#6f42c1' },
                                    ]} />
                                </div>
                                <div className="col-xl-6">
                                    <TrendChart title="Nginx responses (5 min window)" data={samples} lines={[
                                        { key: 'nginx499', name: '499', color: '#fd7e14' },
                                        { key: 'nginx5xx', name: '5xx', color: '#dc3545' },
                                    ]} />
                                </div>
                                <div className="col-xl-6">
                                    <TrendChart title="FPM listen queue" data={samples} lines={[
                                        { key: 'fpmQueue', name: 'Queued requests', color: '#20c997' },
                                    ]} />
                                </div>
                            </div>
                            <div className="small text-muted">Samples are retained for 96 hours (4 days). Detailed process and log excerpts remain on the VPS and are not exposed in this dashboard.</div>
                        </>
                    )}
                </>
            )}
        </div>
    );
}
