import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

export default function StockClassificationAdminPage() {
    const [query, setQuery] = useState('');
    const [stocks, setStocks] = useState([]);
    const [options, setOptions] = useState({ sectors: [], industries: {} });
    const [selected, setSelected] = useState(null);
    const [sector, setSector] = useState('');
    const [industry, setIndustry] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const timer = window.setTimeout(async () => {
            const response = await api.get('/admin/stock-classifications', { params: { q: query || undefined } });
            setStocks(response.data.data || []);
        }, 250);
        return () => window.clearTimeout(timer);
    }, [query]);

    useEffect(() => {
        api.get('/admin/stock-classifications/options').then((response) => setOptions(response.data.data || { sectors: [], industries: {} }));
    }, []);

    const choose = (stock) => {
        setSelected(stock);
        setSector(stock.classification?.sector || '');
        setIndustry(stock.classification?.industry || '');
        setReason('');
    };

    const save = async () => {
        setBusy(true);
        try {
            const response = await api.put(`/admin/stocks/${selected.id}/classification/override`, { sector, industry, reason: reason || undefined });
            setSelected({ ...selected, classification: response.data.data });
            showToast('Manual classification saved', 'success');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Classification save failed', 'danger');
        } finally { setBusy(false); }
    };

    const remove = async () => {
        setBusy(true);
        try {
            const response = await api.delete(`/admin/stocks/${selected.id}/classification/override`);
            setSelected({ ...selected, classification: response.data.data });
            showToast('Returned to automatic classification', 'success');
        } finally { setBusy(false); }
    };

    const refresh = async () => {
        setBusy(true);
        try {
            const response = await api.post(`/admin/stocks/${selected.id}/classification/refresh`);
            setSelected({ ...selected, classification: response.data.data });
            showToast('Automatic observation refreshed', 'success');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Classification refresh failed', 'danger');
        } finally { setBusy(false); }
    };

    return (
        <div className="row g-3" data-testid="stock-classification-admin-page">
            <div className="col-12 d-flex justify-content-between align-items-start">
                <div><h1 className="h4 mb-1">Stock classifications</h1><p className="text-muted small mb-0">Automatic NSE observations and audited Admin fallback. Historical membership snapshots are unchanged.</p></div>
                <Link to="/settings/stocks" className="btn btn-sm btn-outline-secondary">Back to Stocks</Link>
            </div>
            <div className="col-md-5">
                <input className="form-control mb-2" placeholder="Search symbol or name" value={query} onChange={(event) => setQuery(event.target.value)} />
                <div className="list-group">
                    {stocks.map((stock) => <button type="button" className={`list-group-item list-group-item-action ${selected?.id === stock.id ? 'active' : ''}`} key={stock.id} onClick={() => choose(stock)}>{stock.symbol} · {stock.name || '—'}<small className="d-block">{stock.classification?.sector || 'Unknown'} / {stock.classification?.industry || 'Unknown'}</small></button>)}
                </div>
            </div>
            <div className="col-md-7">
                {!selected ? <div className="alert alert-secondary">Select a stock to inspect or classify it.</div> : (
                    <div className="card"><div className="card-body">
                        <h2 className="h6">{selected.symbol} · {selected.name}</h2>
                        <p className="small text-muted mb-3">Current source: {selected.classification?.source || 'unknown'} · observed {selected.classification?.observed_at || 'never'}</p>
                        <label className="form-label">Sector</label>
                        <select className="form-select mb-2" value={sector} onChange={(event) => { setSector(event.target.value); setIndustry(''); }}><option value="">Select sector</option>{options.sectors.map((item) => <option key={item} value={item}>{item}</option>)}</select>
                        <label className="form-label">Industry</label>
                        <select className="form-select mb-3" value={industry} onChange={(event) => setIndustry(event.target.value)}><option value="">Select industry</option>{(options.industries[sector] || []).map((item) => <option key={item} value={item}>{item}</option>)}</select>
                        <label className="form-label">Audit reason</label><textarea className="form-control mb-3" value={reason} onChange={(event) => setReason(event.target.value)} />
                        <div className="d-flex gap-2 flex-wrap"><button type="button" className="btn btn-primary" disabled={busy || !sector || !industry} onClick={save}>Save</button><button type="button" className="btn btn-outline-primary" disabled={busy} onClick={refresh}>Refresh automatic</button>{selected.classification?.source_type === 'manual_override' && <button type="button" className="btn btn-outline-warning" disabled={busy} onClick={remove}>Return to automatic</button>}</div>
                    </div></div>
                )}
            </div>
        </div>
    );
}
