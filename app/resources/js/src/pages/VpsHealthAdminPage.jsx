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
import { formatVpsSampleAge, getVpsFpmStatus, getVpsHealthMetricStatus, getVpsSampleAgeStatus, VPS_HEALTH_STATUS } from './vpsHealthMetricStatus';

const RANGE_OPTIONS = [
    { hours: 1, label: '1 hour' },
    { hours: 6, label: '6 hours' },
    { hours: 24, label: '24 hours' },
    { hours: 72, label: '3 days' },
    { hours: 168, label: '7 days' },
    { hours: 720, label: '30 days' },
];

const METRIC_TIPS = {
    load: {
        title: 'Load per core',
        summary: 'Load shows how many tasks are running or waiting for CPU. Compare it with the CPU count and look for a sustained rise across samples.',
        steps: [
            'Check the current load and number of CPU cores.',
            'If load remains high, inspect the busiest CPU processes before changing or restarting anything.',
        ],
        commands: [
            { label: 'Load and CPU count', command: 'uptime; nproc' },
            { label: 'Processes using the most CPU', command: 'ps -eo pid,comm,%cpu,%mem --sort=-%cpu | head -n 12' },
        ],
    },
    ram: {
        title: 'Available RAM',
        summary: 'Linux uses spare RAM for file cache. The “available” value is more useful than the “free” value for judging memory headroom.',
        steps: [
            'Check available memory and swap together.',
            'If available RAM stays low, inspect the largest resident processes and correlate with swap activity.',
        ],
        commands: [
            { label: 'Memory and swap summary', command: 'free -h' },
            { label: 'Processes using the most RAM', command: 'ps -eo pid,comm,rss --sort=-rss | head -n 12' },
        ],
    },
    disk: {
        title: 'Root disk used',
        summary: 'A high root filesystem percentage means less room for database growth, logs, backups, and deployments. Find the largest directories before deleting anything.',
        steps: [
            'Check filesystem capacity and the largest top-level directories.',
            'Review the results and retention needs before removing files. Do not delete active application, database, or backup data based only on size.',
        ],
        commands: [
            { label: 'Filesystem capacity', command: 'df -h /' },
            { label: 'Largest directories on the root filesystem', command: 'sudo du -xhd1 /var /home /tmp 2>/dev/null | sort -h' },
        ],
    },
    inode: {
        title: 'Root inode usage',
        summary: 'Inodes track filesystem entries such as files and directories. A filesystem can run out of inodes while byte capacity remains available.',
        steps: [
            'Check inode capacity and compare it with byte capacity.',
            'If inode use is high, locate directories with many entries and review retention before removing anything.',
        ],
        commands: [
            { label: 'Filesystem inode capacity', command: 'df -i /' },
            { label: 'Count entries in a directory', command: 'sudo find /var -xdev -type f -printf . | wc -c' },
        ],
    },
    diskIo: {
        title: 'Disk I/O',
        summary: 'Read and write rates describe observed throughput for the root block device. They do not by themselves show whether storage is overloaded.',
        steps: [
            'Compare read and write rates across consecutive samples.',
            'Correlate sustained activity with I/O wait and application symptoms before investigating storage changes.',
        ],
        commands: [
            { label: 'Block device counters', command: 'cat /proc/diskstats' },
            { label: 'I/O activity (if installed)', command: 'iostat -xz 1 5' },
        ],
    },
    iowait: {
        title: 'Disk I/O wait',
        summary: 'I/O wait estimates the share of CPU time spent idle while disk I/O is outstanding. It is a host-wide signal and can be unavailable until consecutive counters are readable.',
        steps: [
            'Check whether the reading is available and compare it with disk throughput over several samples.',
            'If elevated, inspect device latency and correlate the time with workload symptoms; a single value does not identify the cause.',
        ],
        commands: [
            { label: 'CPU and I/O activity (if installed)', command: 'iostat -xz 1 5' },
            { label: 'Recent kernel storage messages', command: 'sudo journalctl -k -n 100 --no-pager' },
        ],
    },
    swap: {
        title: 'Swap used',
        summary: 'Swap can stay occupied after an earlier memory spike even when RAM is available again. Check active swap-in and swap-out before treating the percentage as a current problem.',
        steps: [
            'Check available RAM and which swap device is enabled.',
            'Watch the `si` and `so` columns in vmstat. Sustained non-zero values are stronger evidence of active swapping than swap used by itself.',
            'To clear old swap pages, only proceed during a quiet period after confirming available RAM comfortably exceeds swap in use. This moves pages into RAM and can cause an outage if memory pressure returns.',
        ],
        commands: [
            { label: 'Memory and enabled swap', command: 'free -h; swapon --show' },
            { label: 'Live swap-in and swap-out (10 seconds)', command: 'vmstat -w 1 10' },
            { label: 'Optional: move swap pages back into RAM', command: 'sudo swapoff /swapfile && sudo swapon /swapfile' },
        ],
        warning: 'Before using the optional reset command, confirm `swapon --show` lists `/swapfile` and run `free -h` again. Do not run it when RAM is tight or the server is under heavy load. If your swap device has a different path, use that exact path in both commands.',
    },
    fpm: {
        title: 'PHP-FPM workers',
        summary: 'A full worker pool or a non-zero listen queue can mean PHP requests are waiting. Check the service journal and recent trend before considering a reload or configuration change.',
        steps: [
            'Check whether the PHP-FPM service is active.',
            'Review recent service messages for worker saturation or errors. These commands inspect status and logs; they do not restart the service.',
        ],
        commands: [
            { label: 'PHP-FPM service status', command: 'sudo systemctl status php8.4-fpm --no-pager' },
            { label: 'Recent PHP-FPM messages', command: 'sudo journalctl -u php8.4-fpm -n 100 --no-pager' },
        ],
    },
    nginx: {
        title: 'Nginx errors (5 min)',
        summary: 'The card counts recent 502, 503, and 504 responses separately from 499 client disconnects. A count is a clue to investigate, not proof of the cause.',
        steps: [
            'Check that the current Nginx configuration passes its syntax test.',
            'Review recent Nginx error messages and compare their timestamps with the chart and application logs.',
        ],
        commands: [
            { label: 'Test Nginx configuration (does not reload)', command: 'sudo nginx -t' },
            { label: 'Recent Nginx error messages', command: 'sudo tail -n 100 /var/log/nginx/error.log' },
        ],
    },
};

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

function number(value, digits = 1) {
    return value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value))
        ? Number(value).toFixed(digits)
        : '—';
}

function bytes(value) {
    if (value === null || value === undefined || value === '') return 'Unavailable';
    const parsed = Number(value);
    if (!Number.isFinite(parsed) || parsed < 0) return 'Unavailable';
    if (parsed < 1024) return `${parsed} B`;
    const units = ['KiB', 'MiB', 'GiB', 'TiB'];
    let size = parsed / 1024;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit += 1; }
    return `${size.toFixed(2)} ${units[unit]}`;
}

function MetricCard({ label, value, detail, status = 'unknown', threshold, tipId, onShowTips }) {
    const severity = VPS_HEALTH_STATUS[status] ?? VPS_HEALTH_STATUS.unknown;
    return (
        <div className="col-6 col-xl-3">
            <div
                className={`card h-100 border-${severity.color}`}
                title={threshold}
                style={{
                    backgroundColor: {
                        success: 'rgba(var(--bs-success-rgb), 0.045)',
                        warning: 'rgba(var(--bs-warning-rgb), 0.07)',
                        danger: 'rgba(var(--bs-danger-rgb), 0.045)',
                    }[severity.color],
                }}
            >
                <div className="card-body">
                    <div className="d-flex align-items-start justify-content-between gap-2 mb-1">
                        <div className="small text-muted">{label}</div>
                        <span className={`badge text-bg-${severity.color}`}>{severity.label}</span>
                    </div>
                    <div className={`fs-4 fw-semibold ${severity.color === 'warning' ? 'text-warning-emphasis' : `text-${severity.color}`}`}>{value}</div>
                    {detail && <div className="small text-muted mt-1">{detail}</div>}
                    {tipId && onShowTips && <button type="button" className="btn btn-sm btn-link px-0 pb-0 mt-2" onClick={() => onShowTips(tipId)} aria-haspopup="dialog" aria-label={`Tips for ${label}`}>
                        Tips
                    </button>}
                </div>
            </div>
        </div>
    );
}

function CommandBlock({ label, command, onCopy }) {
    return (
        <div className="border rounded p-2 mb-3">
            <div className="d-flex align-items-center justify-content-between gap-2 mb-2">
                <div className="small fw-semibold">{label}</div>
                <button type="button" className="btn btn-sm btn-outline-secondary flex-shrink-0" onClick={() => onCopy(command)}>
                    Copy command
                </button>
            </div>
            <pre className="mb-0 small text-break" style={{ whiteSpace: 'pre-wrap' }}><code>{command}</code></pre>
        </div>
    );
}

function MetricTipsModal({ tip, onClose }) {
    const [copyMessage, setCopyMessage] = useState('');

    useEffect(() => {
        if (!tip) return undefined;
        const onKeyDown = (event) => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [tip, onClose]);

    if (!tip) return null;

    const copyCommand = async (command) => {
        try {
            if (!navigator.clipboard?.writeText) throw new Error('Clipboard API unavailable');
            await navigator.clipboard.writeText(command);
            setCopyMessage('Command copied.');
        } catch {
            const field = document.createElement('textarea');
            field.value = command;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            let copied = false;
            try {
                copied = document.execCommand('copy');
            } catch {
                copied = false;
            }
            field.remove();
            setCopyMessage(copied ? 'Command copied.' : 'Copy failed. Select the command text to copy it.');
        }
    };

    return (
        <div className="modal d-block" style={{ backgroundColor: 'rgba(0, 0, 0, 0.5)' }} role="dialog" aria-modal="true" aria-labelledby="vps-health-tip-title" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
            <div className="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div className="modal-content">
                    <div className="modal-header">
                        <h2 className="modal-title fs-5" id="vps-health-tip-title">{tip.title} tips</h2>
                        <button type="button" className="btn-close" aria-label="Close tips" onClick={onClose} autoFocus />
                    </div>
                    <div className="modal-body">
                        <p>{tip.summary}</p>
                        <h3 className="h6">Steps</h3>
                        <ol className="mb-3">{tip.steps.map((step) => <li key={step} className="mb-1">{step}</li>)}</ol>
                        <h3 className="h6">Commands</h3>
                        {tip.commands.map((item) => <CommandBlock key={item.label} {...item} onCopy={copyCommand} />)}
                        {tip.warning && <div className="alert alert-warning small mb-0">{tip.warning}</div>}
                        <div className="small text-muted mt-2" role="status" aria-live="polite">{copyMessage}</div>
                    </div>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>Close</button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function TrendChart({ title, data, lines, ySuffix = '' }) {
    const hasValues = data.some((row) => lines.some((line) => row[line.key] !== null && row[line.key] !== undefined && row[line.key] !== '' && Number.isFinite(Number(row[line.key]))));
    return (
        <div className="card h-100">
            <div className="card-header fw-semibold">{title}</div>
            <div className="card-body" style={{ height: 260 }}>
                {data.length === 0 ? <div className="text-muted small">No samples in this range.</div> : !hasValues ? <div className="text-muted small">Unavailable: no supported measurements in this range.</div> : (
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
    const [activeTip, setActiveTip] = useState(null);

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
            inode: metric.root_inode?.used_percent,
            diskRead: metric.disk_io?.status === 'available' ? metric.disk_io.read_bytes_per_second : null,
            diskWrite: metric.disk_io?.status === 'available' ? metric.disk_io.write_bytes_per_second : null,
            diskIowait: metric.disk_iowait_percent,
            swap: metric.swap_used_percent,
            nginx499: data?.history_granularity === 'hour' ? null : (metric.nginx?.['499'] ?? 0),
            nginx5xx: data?.history_granularity === 'hour' ? null : ((metric.nginx?.['502'] ?? 0) + (metric.nginx?.['503'] ?? 0) + (metric.nginx?.['504'] ?? 0)),
            fpmQueue: data?.history_granularity === 'hour' ? null : (metric.fpm?.['listen queue'] ?? 0),
        };
    }), [data]);
    const stale = data?.last_sample_age_seconds == null || data.last_sample_age_seconds > 180;
    const diskGrowth = useMemo(() => {
        const rows = (data?.samples ?? []).filter((row) => row.metrics?.root_available_bytes != null && Number.isFinite(Number(row.metrics.root_available_bytes)));
        const first = rows[0]?.metrics;
        const last = rows.at(-1)?.metrics;
        if (rows.length < 2 || !first || !last) return null;
        const elapsedHours = (new Date(rows.at(-1).sampled_at).getTime() - new Date(rows[0].sampled_at).getTime()) / 3600000;
        if (!(elapsedHours >= 1)) return null;
        if (first.root_available_bytes === null || first.root_available_bytes === undefined || last.root_available_bytes === null || last.root_available_bytes === undefined) return null;
        const firstAvailable = Number(first.root_available_bytes);
        const lastAvailable = Number(last.root_available_bytes);
        const consumedPerDay = (firstAvailable - lastAvailable) * 24 / elapsedHours;
        if (!(consumedPerDay > 0) || !Number.isFinite(consumedPerDay) || !Number.isFinite(firstAvailable) || !Number.isFinite(lastAvailable) || !(lastAvailable >= 0)) return null;
        return { consumedPerDay, daysToFull: lastAvailable / consumedPerDay };
    }, [data]);
    const hourlyBreakdown = useMemo(() => {
        const buckets = new Map();
        for (const sample of data?.samples ?? []) {
            const date = new Date(sample.sampled_at);
            if (!Number.isFinite(date.getTime())) continue;
            const hour = date.toISOString().slice(0, 13);
            const bucket = buckets.get(hour) ?? { hour, count: 0, latest: sample };
            bucket.count += 1;
            bucket.latest = sample;
            buckets.set(hour, bucket);
        }
        return [...buckets.values()].sort((a, b) => b.hour.localeCompare(a.hour)).slice(0, data?.history_granularity === 'hour' ? 720 : 72);
    }, [data]);
    const healthStatus = !latest ? 'No data' : stale ? 'Stale data' : latest.status === 'critical' ? 'Critical' : latest.status === 'warning' ? 'Warning' : 'Healthy';
    const statusClass = !latest || stale || latest.status === 'warning' ? 'bg-warning text-dark' : latest.status === 'critical' ? 'bg-danger' : 'bg-success';
    const summaryBorder = !latest || stale || latest.status === 'warning' ? 'warning' : latest.status === 'critical' ? 'danger' : 'success';
    const sampleAgeStatus = getVpsSampleAgeStatus(data?.last_sample_age_seconds);
    const sampleAgeClass = sampleAgeStatus === 'normal' ? 'text-success' : sampleAgeStatus === 'critical' ? 'text-danger' : 'text-muted';
    const summaryTint = !latest || stale
        ? 'rgba(var(--bs-warning-rgb), 0.07)'
        : latest.status === 'critical'
            ? 'rgba(var(--bs-danger-rgb), 0.045)'
            : latest.status === 'warning'
                ? 'rgba(var(--bs-warning-rgb), 0.07)'
            : 'rgba(var(--bs-success-rgb), 0.045)';

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                <div>
                    <h1 className="h3 mb-1">VPS Health</h1>
                    <div className="text-muted small">Host, disk, PHP-FPM, and Nginx samples from the StoX health monitor.</div>
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
                    <div className={`card mb-3 border-${summaryBorder}`} style={{ backgroundColor: summaryTint }}>
                        <div className="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <span className={`badge ${statusClass} me-2`}>{healthStatus}</span>
                                <span className="text-muted small">Last sample: {formatTime(latest?.sampled_at)} · <span className={sampleAgeClass} title={latest?.sampled_at ? formatTime(latest.sampled_at) : undefined}>{formatVpsSampleAge(data?.last_sample_age_seconds)}</span></span>
                            </div>
                            <span className="text-muted small">Auto-refreshes every minute · {data?.sample_count ?? 0} samples shown</span>
                        </div>
                    </div>

                    {!latest ? (
                        <div className="alert alert-info">The dashboard will populate after the VPS health monitor records its first sample.</div>
                    ) : (
                        <>
                            {latest.issues?.length > 0 && (
                                <div className={`alert ${stale || latest.status === 'warning' ? 'alert-warning' : 'alert-danger'}`}>
                                    <div className="fw-semibold mb-1">Latest monitor findings</div>
                                    <ul className="mb-0">{latest.issues.map((issue, index) => <li key={`${index}-${issue}`}>{issue}</li>)}</ul>
                                </div>
                            )}

                            <div className="row g-3 mb-2">
                                <MetricCard tipId="load" onShowTips={setActiveTip} label="Load per core" value={number(metrics.load_per_core, 2)} status={getVpsHealthMetricStatus('loadPerCore', metrics.load_per_core)} threshold="Green <1.0 · Amber 1.0–<2.0 · Red ≥2.0" detail={`1 min ${number(metrics.load1, 2)} · ${metrics.cpus ?? '—'} CPUs`} />
                                <MetricCard tipId="ram" onShowTips={setActiveTip} label="Available RAM" value={`${number(metrics.ram_available_percent)}%`} status={getVpsHealthMetricStatus('ramAvailable', metrics.ram_available_percent)} threshold="Green ≥20% · Amber 10–<20% · Red <10%" detail={`${number(metrics.ram_available_bytes == null ? null : metrics.ram_available_bytes / (1024 ** 3), 2)} GiB available`} />
                                <MetricCard tipId="disk" onShowTips={setActiveTip} label="Root disk used" value={metrics.root_used_percent == null ? 'Unavailable' : `${number(metrics.root_used_percent)}%`} status={getVpsHealthMetricStatus('rootDiskUsed', metrics.root_used_percent, metrics.root_disk_critical_percent ?? 90)} threshold={`Normal <75% · Warning 75% until critical threshold (${number(metrics.root_disk_critical_percent ?? 90, 0)}%, default 90%)`} detail={`${bytes(metrics.root_used_bytes)} used of ${bytes(metrics.root_total_bytes)} · ${bytes(metrics.root_available_bytes)} available (${number(metrics.root_available_percent)}%)`} />
                                <MetricCard tipId="inode" onShowTips={setActiveTip} label="Root inode usage" value={metrics.root_inode?.used_percent == null ? 'Unavailable' : `${number(metrics.root_inode.used_percent)}%`} status={getVpsHealthMetricStatus('inodeUsed', metrics.root_inode?.used_percent)} threshold="Normal <75% · Warning 75–<90% · Critical ≥90%" detail={`${number(metrics.root_inode?.used, 0)} used of ${number(metrics.root_inode?.total, 0)} · ${number(metrics.root_inode?.available, 0)} available`} />
                                <MetricCard tipId="diskIo" onShowTips={setActiveTip} label="Disk I/O" value={metrics.disk_io?.status === 'available' ? `Read ${bytes(metrics.disk_io.read_bytes_per_second)}/s` : 'Unavailable'} status={metrics.disk_io?.status === 'available' ? 'normal' : 'unknown'} threshold="Read-only root block device rates between consecutive samples" detail={metrics.disk_io?.status === 'available' ? `Write ${bytes(metrics.disk_io.write_bytes_per_second)}/s` : 'Waiting for a usable consecutive sample or block-device counters.'} />
                                <MetricCard tipId="iowait" onShowTips={setActiveTip} label="Disk I/O wait" value={metrics.disk_iowait_percent == null ? 'Unavailable' : `${number(metrics.disk_iowait_percent)}%`} status={metrics.disk_iowait_percent == null ? 'unknown' : metrics.disk_iowait_percent >= 20 ? 'warning' : 'normal'} threshold="Share of CPU time waiting on I/O since the prior sample" detail={metrics.disk_iowait_percent == null ? 'Unavailable until consecutive /proc/stat counters are readable.' : 'Host-wide CPU time waiting on I/O.'} />
                                <MetricCard tipId="swap" onShowTips={setActiveTip} label="Swap used" value={`${number(metrics.swap_used_percent)}%`} status={getVpsHealthMetricStatus('swapUsed', metrics.swap_used_percent)} threshold="Green <50% · Amber 50–80% · Red >80%" />
                                <MetricCard tipId="fpm" onShowTips={setActiveTip} label="FPM workers" value={`${fpm['active processes'] ?? '—'} / ${fpm['max children'] ?? '—'}`} status={getVpsFpmStatus({ queue: fpm['listen queue'], active: fpm['active processes'], maxChildren: fpm['max children'] })} threshold="Green: queue 0 and workers below 90% capacity · Amber: queue 0 and workers ≥90% · Red: any queued request" detail={`Idle ${fpm['idle processes'] ?? '—'} · Queue ${fpm['listen queue'] ?? '—'}`} />
                                <MetricCard tipId="nginx" onShowTips={setActiveTip} label="Nginx errors (5 min)" value={(nginx['502'] ?? 0) + (nginx['503'] ?? 0) + (nginx['504'] ?? 0)} status={getVpsHealthMetricStatus('nginx5xx', (nginx['502'] ?? 0) + (nginx['503'] ?? 0) + (nginx['504'] ?? 0))} threshold="Green 0 · Amber 1–4 · Red ≥5" detail={`499 client closes: ${nginx['499'] ?? 0}`} />
                            </div>
                            <details className="small text-muted mb-3">
                                <summary>Color thresholds</summary>
                                <ul className="mt-2 mb-1">
                                    <li>Load per core: green below 1.0; amber from 1.0 to below 2.0; red at 2.0 or higher.</li>
                                    <li>Available RAM: green at 20% or more; amber from 10% to below 20%; red below 10%.</li>
                                    <li>Root disk warnings are raised at 75% and 85%; the critical threshold remains configurable (default 90%). Notifications use the existing cooldown.</li>
                                    <li>Inodes show filesystem file-entry capacity; unavailable values mean the filesystem does not report an inode total.</li>
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
                                        { key: 'inode', name: 'Inodes used', color: '#6f42c1' },
                                        { key: 'swap', name: 'Swap used', color: '#fd7e14' },
                                    ]} />
                                </div>
                                <div className="col-xl-6">
                                    <TrendChart title="Load per core" data={samples} lines={[
                                        { key: 'load', name: 'Load per core', color: '#6f42c1' },
                                    ]} />
                                </div>
                                <div className="col-xl-6">
                                    <TrendChart title="Disk I/O (bytes per second)" data={samples} ySuffix=" B/s" lines={[
                                        { key: 'diskRead', name: 'Read', color: '#0d6efd' },
                                        { key: 'diskWrite', name: 'Write', color: '#fd7e14' },
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
                            <div className="card mb-3">
                                <div className="card-header fw-semibold">Disk growth and capacity forecast</div>
                                <div className="card-body small">
                                    {diskGrowth ? <><span>Available capacity is declining by about {bytes(diskGrowth.consumedPerDay)} per day. </span><span>At this rate, available space reaches zero in about {number(diskGrowth.daysToFull, 1)} days.</span><div className="text-muted mt-1">Linear estimate from available-byte samples over the selected range; shown only with at least one hour of measurable decline.</div></> : <span className="text-muted">Unavailable: need at least one hour of history and measurable decline in available bytes.</span>}
                                </div>
                            </div>
                            <div className="card mb-3">
                                <div className="card-header fw-semibold">Hourly directory size hints</div>
                                <div className="card-body small">
                                    <div className="text-muted mb-2">Shallow file sizes include files directly under each category and one directory level; maximum 500 entries or 250 ms. The restricted monitor may not see some paths. Latest scan: {metrics.directory_breakdown?.scanned_at ? new Date(metrics.directory_breakdown.scanned_at).toLocaleString() : 'Unavailable'} · {metrics.directory_breakdown?.status ?? 'unavailable'}.</div>
                                    <div>Reclaimable candidates (not guaranteed safe to delete): {(metrics.directory_breakdown?.reclaimable_candidates ?? []).length ? metrics.directory_breakdown.reclaimable_candidates.map((item) => `${item.kind}: ${bytes(item.bytes)} (${item.status === 'partial' ? 'partial lower bound' : item.status})`).join(' · ') : 'Unavailable'}</div>
                                    <div className="text-muted">Namespace visibility: host /home unavailable (ProtectHome=yes); host /tmp unavailable (PrivateTmp=yes).</div>
                                    <div className="table-responsive mt-2"><table className="table table-sm mb-0"><thead><tr><th>Path</th><th>Shallow file bytes</th></tr></thead><tbody>
                                        {(metrics.directory_breakdown?.paths ?? []).length ? metrics.directory_breakdown.paths.map((item) => { const size = item.shallow_file_bytes ?? item.direct_file_bytes; return <tr key={item.path}><td>{item.path}</td><td>{item.status === 'unavailable' || size == null ? 'Unavailable' : item.status === 'partial' ? `${bytes(size)} (partial lower bound)` : bytes(size)}</td></tr>; }) : <tr><td colSpan="2">Unavailable</td></tr>}
                                    </tbody></table></div>
                                </div>
                            </div>
                            <div className="card mb-3">
                                <div className="card-header fw-semibold">{data?.history_granularity === 'hour' ? 'Hourly disk history' : 'Hourly disk view'} <span className="text-muted fw-normal">({hours > 168 ? 'up to 30 days' : hours > 72 ? 'up to 7 days' : 'up to 72 hours'})</span></div>
                                <div className="table-responsive" style={{ maxHeight: 360 }}>
                                    <table className="table table-sm table-striped mb-0"><thead className="sticky-top"><tr><th scope="col">Hour (UTC)</th><th scope="col">{data?.history_granularity === 'hour' ? 'Record' : 'Minute samples'}</th><th scope="col">Used</th><th scope="col">Available</th><th scope="col">Inodes</th><th scope="col">Read / write</th><th scope="col">Shallow directory sizes</th></tr></thead><tbody>
                                        {hourlyBreakdown.length ? hourlyBreakdown.map(({ hour, count, latest: sample }) => { const disk = sample.metrics ?? {}; const io = disk.disk_io ?? {}; const dirs = disk.directory_breakdown ?? {}; const candidates = (dirs.reclaimable_candidates ?? []).map((item) => `${item.kind}: ${bytes(item.bytes)} candidate (${item.status === 'partial' ? 'partial lower bound' : item.status})`).join(' · '); return <tr key={hour}><td>{hour}:00</td><td>{data?.history_granularity === 'hour' ? 'Latest hourly rollup' : count}</td><td>{disk.root_used_percent == null ? 'Unavailable' : `${number(disk.root_used_percent)}%`}</td><td>{bytes(disk.root_available_bytes)}</td><td>{disk.root_inode?.used_percent == null ? 'Unavailable' : `${number(disk.root_inode.used_percent)}%`}</td><td>{io.status === 'available' ? `${bytes(io.read_bytes_per_second)}/s · ${bytes(io.write_bytes_per_second)}/s` : 'Unavailable'}</td><td>{dirs.status === 'unavailable' ? 'Unavailable' : <>{`${dirs.status === 'partial' ? 'Partial · ' : ''}${(dirs.paths ?? []).map((item) => { const size = item.shallow_file_bytes ?? item.direct_file_bytes; return `${item.path}: ${item.status === 'unavailable' || size == null ? 'Unavailable' : bytes(size)} (${item.status ?? dirs.status})`; }).join(' · ') || 'No visible entries'}`}{candidates && <div>{candidates}</div>}</>}</td></tr>; }) : <tr><td colSpan="7" className="text-muted">No hourly samples available.</td></tr>}
                                    </tbody></table>
                                </div>
                            </div>
                            <div className="small text-muted">Minute samples are retained for 96 hours; disk-only hourly rollups are retained for 30 days. Long ranges contain one latest disk sample per hour. Cache and rotated-log figures are identifiable candidates only, never guaranteed safe deletion. The monitor cannot see host /home (ProtectHome=yes) or the host /tmp (PrivateTmp=yes); partial and unavailable values are not whole-host totals. Detailed process and log excerpts remain on the VPS and are not exposed in this dashboard.</div>
                        </>
                    )}
                </>
            )}
            <MetricTipsModal tip={METRIC_TIPS[activeTip]} onClose={() => setActiveTip(null)} />
        </div>
    );
}
