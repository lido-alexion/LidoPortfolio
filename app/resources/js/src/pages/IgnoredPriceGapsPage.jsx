import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';
import { formatSchedulerTimestamp } from '../utils/schedulerTimestamp';

function formatStatusTime(value) {
    return formatSchedulerTimestamp(value, 'Asia/Kolkata');
}

export default function IgnoredPriceGapsPage() {
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [rows, setRows] = useState([]);
    const [removingId, setRemovingId] = useState(null);

    const load = useCallback(async () => {
        setLoadError('');
        try {
            const { data } = await api.get('/universe-price-sync/gaps/ignored');
            setRows(data.data ?? []);
        } catch (error) {
            setLoadError(error?.response?.data?.message || 'Failed to load ignored gaps.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const removeIgnored = async (id) => {
        setRemovingId(id);
        try {
            await api.delete(`/universe-price-sync/gaps/ignored/${id}`);
            setRows((current) => current.filter((row) => row.id !== id));
            showToast('Removed from ignore list.', 'success');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to remove ignored gap.', 'danger');
        } finally {
            setRemovingId(null);
        }
    };

    return (
        <div className="contentPane">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Ignored price gaps</h1>
                    <p className="text-muted small mb-0">
                        Gaps excluded from scan inventory and fill attempts.
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
            ) : rows.length === 0 ? (
                <p className="text-muted">No ignored gaps.</p>
            ) : (
                <div className="table-responsive border rounded">
                    <table className="table table-sm table-striped mb-0 small">
                        <thead>
                            <tr>
                                <th>Stock</th>
                                <th>Exchange</th>
                                <th>Gap start</th>
                                <th>Gap end</th>
                                <th>Gap days</th>
                                <th>Ignored at</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id}>
                                    <td className="text-nowrap">{row.symbol}</td>
                                    <td>{row.exchange || '—'}</td>
                                    <td>{row.gap_from}</td>
                                    <td>{row.gap_to}</td>
                                    <td>{row.gap_days}</td>
                                    <td>{formatStatusTime(row.ignored_at)}</td>
                                    <td>
                                        <button
                                            type="button"
                                            className="btn btn-outline-danger btn-sm"
                                            disabled={removingId === row.id}
                                            onClick={() => removeIgnored(row.id)}
                                        >
                                            {removingId === row.id ? 'Removing…' : 'Remove'}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
