import React, { useCallback, useState } from 'react';
import api from '../api';
import useApiGet from '../hooks/useApiGet';
import { getLocalTodayDateString } from '../utils/transactionDate';
import { showToast } from '../toast';

export default function TaxEvidenceEntryPanel({ financialYear, onChanged }) {
    const today = getLocalTodayDateString();
    const [dividend, setDividend] = useState({ received_on: today, amount: '', source_reference: '' });
    const [loss, setLoss] = useState({ loss_type: 'short_term', amount: '', status: 'calculated', reason: '' });
    const [lot, setLot] = useState({ stock_id: '', acquired_on: today, quantity: '', cost_basis: '', reason: '' });
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [busy, setBusy] = useState(false);
    const loadStocks = useCallback(async () => (await api.get('/stocks', { params: { limit: 100 } })).data?.data || [], []);
    const { data: stocks } = useApiGet({ request: loadStocks, deps: [], initialData: [], errorFallback: 'Could not load stocks' });

    const submit = async (path, payload) => {
        setBusy(true);
        try {
            await api.post(path, payload);
            showToast('Tax evidence recorded', 'success');
            onChanged();
        } finally { setBusy(false); }
    };
    const upload = async (dryRun) => {
        const body = new FormData();
        body.append('file', file);
        body.append('definition_version', 'generic-dividend-csv.v1');
        body.append('dry_run', dryRun ? '1' : '0');
        setBusy(true);
        try {
            const result = (await api.post('/tax/dividends/import', body)).data?.data;
            setPreview(result);
            if (!dryRun) onChanged();
        } finally { setBusy(false); }
    };

    return <div className="card mb-4"><div className="card-header">Record tax evidence</div><div className="card-body row g-3">
        <form className="col-lg-4" onSubmit={(e) => { e.preventDefault(); submit('/tax/dividends', dividend); }}><h3 className="h6">Manual dividend</h3><input aria-label="Dividend date" className="form-control form-control-sm mb-2" type="date" max={today} value={dividend.received_on} onChange={(e) => setDividend({ ...dividend, received_on: e.target.value })} required /><input aria-label="Dividend amount" className="form-control form-control-sm mb-2" type="number" min="0.01" step="0.01" placeholder="Amount (INR)" value={dividend.amount} onChange={(e) => setDividend({ ...dividend, amount: e.target.value })} required /><input aria-label="Dividend reference" className="form-control form-control-sm mb-2" placeholder="Reference" value={dividend.source_reference} onChange={(e) => setDividend({ ...dividend, source_reference: e.target.value })} /><button className="btn btn-sm btn-outline-primary" disabled={busy}>Record dividend</button></form>
        <form className="col-lg-4" onSubmit={(e) => { e.preventDefault(); submit('/tax/losses', { ...loss, financial_year: financialYear }); }}><h3 className="h6">Tax loss</h3><select aria-label="Loss term" className="form-select form-select-sm mb-2" value={loss.loss_type} onChange={(e) => setLoss({ ...loss, loss_type: e.target.value })}><option value="short_term">Short term</option><option value="long_term">Long term</option></select><select aria-label="Loss status" className="form-select form-select-sm mb-2" value={loss.status} onChange={(e) => setLoss({ ...loss, status: e.target.value })}><option value="calculated">Calculated</option><option value="confirmed">Confirmed carry-forward</option></select><input aria-label="Loss amount" className="form-control form-control-sm mb-2" type="number" min="0.01" step="0.01" placeholder="Amount" value={loss.amount} onChange={(e) => setLoss({ ...loss, amount: e.target.value })} required /><input aria-label="Loss reason" className="form-control form-control-sm mb-2" placeholder="Evidence/reason" value={loss.reason} onChange={(e) => setLoss({ ...loss, reason: e.target.value })} required={loss.status === 'confirmed'} /><button className="btn btn-sm btn-outline-primary" disabled={busy}>Record loss</button></form>
        <form className="col-lg-4" onSubmit={(e) => { e.preventDefault(); submit('/tax/opening-lots', lot); }}><h3 className="h6">Opening tax lot</h3><select aria-label="Opening lot stock" className="form-select form-select-sm mb-2" value={lot.stock_id} onChange={(e) => setLot({ ...lot, stock_id: e.target.value })} required><option value="">Select stock</option>{stocks.map((stock) => <option key={stock.id} value={stock.id}>{stock.symbol} · {stock.name}</option>)}</select><input aria-label="Acquired date" className="form-control form-control-sm mb-2" type="date" max={today} value={lot.acquired_on} onChange={(e) => setLot({ ...lot, acquired_on: e.target.value })} required /><div className="d-flex gap-2 mb-2"><input aria-label="Lot quantity" className="form-control form-control-sm" type="number" min="0.0001" step="0.0001" placeholder="Quantity" value={lot.quantity} onChange={(e) => setLot({ ...lot, quantity: e.target.value })} required /><input aria-label="Lot cost basis" className="form-control form-control-sm" type="number" min="0" step="0.01" placeholder="Cost basis" value={lot.cost_basis} onChange={(e) => setLot({ ...lot, cost_basis: e.target.value })} required /></div><input aria-label="Lot reason" className="form-control form-control-sm mb-2" placeholder="Pre-StoX evidence/reason" value={lot.reason} onChange={(e) => setLot({ ...lot, reason: e.target.value })} required /><button className="btn btn-sm btn-outline-primary" disabled={busy}>Record opening lot</button></form>
        <div className="col-12 border-top pt-3"><label className="form-label small" htmlFor="dividend-import">Dividend CSV · generic-dividend-csv.v1</label><div className="d-flex flex-wrap gap-2"><input id="dividend-import" className="form-control form-control-sm w-auto" type="file" accept=".csv,text/csv" onChange={(e) => { setFile(e.target.files?.[0] || null); setPreview(null); }} /><button type="button" className="btn btn-sm btn-outline-secondary" disabled={!file || busy} onClick={() => upload(true)}>Preview</button><button type="button" className="btn btn-sm btn-outline-primary" disabled={!file || busy || preview?.valid !== true} onClick={() => upload(false)}>Import validated rows</button></div>{preview ? <div className="small mt-2">Valid {String(preview.valid)} · candidates {preview.candidate_rows || 0} · duplicates {preview.duplicates} · imported {preview.imported}</div> : null}</div>
    </div></div>;
}
