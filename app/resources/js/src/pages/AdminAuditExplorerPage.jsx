import React, { useEffect, useState } from 'react';
import { Download, Search } from 'lucide-react';
import api, { getApiErrorMessage } from '../api';
import { appUrl } from '../appBase';
import { showToast } from '../toast';

export default function AdminAuditExplorerPage() {
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ investors: [], portfolios: [] });
    const [filters, setFilters] = useState({ source: '', level: '', user_id: '', profile_id: '', search: '' });
    const [loading, setLoading] = useState(false);

    const load = async () => {
        setLoading(true);
        try {
            const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
            const response = await api.get('/admin/audit', { params });
            setRows(response.data?.data || []);
            setMeta(response.data?.meta || { investors: [], portfolios: [] });
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not load audit events'), 'danger');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const exportUrl = () => {
        const params = new URLSearchParams(Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')));
        return appUrl(`/api/admin/audit/export?${params.toString()}`);
    };

    return (
        <div className="container-fluid py-4">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div>
                    <h2 className="h4 mb-1">Audit explorer</h2>
                    <p className="text-muted small mb-0">Read-only persisted events for investors, portfolios, execution safety, and system activity.</p>
                </div>
                <a className="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1" href={exportUrl()}>
                    <Download size={14} /> CSV
                </a>
            </div>
            <div className="card mb-3">
                <div className="card-body">
                    <div className="row g-2 align-items-end">
                        <div className="col-md-2"><label className="form-label small mb-1">Source</label><select className="form-select form-select-sm" value={filters.source} onChange={(e) => setFilters({ ...filters, source: e.target.value })}><option value="">All</option><option value="safety">Safety</option><option value="system">System</option></select></div>
                        <div className="col-md-2"><label className="form-label small mb-1">Level</label><select className="form-select form-select-sm" value={filters.level} onChange={(e) => setFilters({ ...filters, level: e.target.value })}><option value="">All</option><option value="info">Info</option><option value="warning">Warning</option><option value="error">Error</option></select></div>
                        <div className="col-md-2"><label className="form-label small mb-1">Investor</label><select className="form-select form-select-sm" value={filters.user_id} onChange={(e) => setFilters({ ...filters, user_id: e.target.value })}><option value="">All</option>{meta.investors.map((u) => <option key={u.id} value={u.id}>{u.name || u.email}</option>)}</select></div>
                        <div className="col-md-2"><label className="form-label small mb-1">Portfolio</label><select className="form-select form-select-sm" value={filters.profile_id} onChange={(e) => setFilters({ ...filters, profile_id: e.target.value })}><option value="">All</option>{meta.portfolios.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
                        <div className="col-md-3"><label className="form-label small mb-1">Search</label><input className="form-control form-control-sm" value={filters.search} onChange={(e) => setFilters({ ...filters, search: e.target.value })} /></div>
                        <div className="col-md-1"><button type="button" className="btn btn-primary btn-sm w-100" onClick={load} disabled={loading}><Search size={14} /></button></div>
                    </div>
                </div>
            </div>
            <div className="table-responsive">
                <table className="table table-sm align-middle">
                    <thead><tr><th>When</th><th>Source</th><th>Event</th><th>Level</th><th>User</th><th>Portfolio</th><th>Message</th></tr></thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td>{row.occurred_at ? new Date(row.occurred_at).toLocaleString() : '-'}</td>
                                <td>{row.source}</td>
                                <td><code>{row.event}</code></td>
                                <td><span className={`badge ${row.level === 'error' ? 'text-bg-danger' : row.level === 'warning' ? 'text-bg-warning' : 'text-bg-secondary'}`}>{row.level}</span></td>
                                <td>{row.user_id || '-'}</td>
                                <td>{row.profile_id || '-'}</td>
                                <td>{row.message}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="7" className="text-muted">{loading ? 'Loading…' : 'No audit events found.'}</td></tr>}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
