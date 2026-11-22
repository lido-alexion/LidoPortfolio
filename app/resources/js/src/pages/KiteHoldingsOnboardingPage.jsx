import React, { useEffect, useState } from 'react';
import api, { getApiErrorMessage } from '../api';

export default function KiteHoldingsOnboardingPage() {
    const [draft, setDraft] = useState(null); const [busy, setBusy] = useState(false); const [message, setMessage] = useState(''); const [edits, setEdits] = useState({});
    const load = async () => { const { data } = await api.get('/kite/holdings-onboarding'); setDraft(data.data); };
    useEffect(() => { load().catch(e => setMessage(getApiErrorMessage(e))); }, []);
    const run = async (fn, success) => { setBusy(true); setMessage(''); try { await fn(); await load(); if (success) setMessage(success); } catch (e) { setMessage(getApiErrorMessage(e)); } finally { setBusy(false); } };
    const entries = row => edits[row.id] ?? row.entries;
    const save = row => run(() => api.put(`/kite/holdings-onboarding/stocks/${row.id}`, { entries: entries(row) }), 'Staging saved.');
    const setEntry = (row, i, key, value) => setEdits(old => ({ ...old, [row.id]: entries(row).map((entry, n) => n === i ? { ...entry, [key]: value, ...(key === 'date' ? { date_placeholder: false } : {}) } : entry) }));
    const commitIds = draft?.stocks?.filter(s => s.status === 'verified').map(s => s.id) ?? [];
    const importTradebook = file => { if (!file) return; const body = new FormData(); body.append('tradebook', file); run(() => api.post('/kite/holdings-onboarding/tradebook', body, { headers: { 'Content-Type': 'multipart/form-data' } }), 'Tradebook rows merged. Review every affected stock.'); };
    return <main className="container py-4" aria-label="Kite holdings onboarding">
        <h1>Kite holdings onboarding</h1>
        <p>Stage currently held Kite delivery shares, refine lots, review each stock, then commit selected verified stocks. Uncommitted staging does not change your StoX portfolio.</p>
        <p><strong>Opening position dates are placeholders, not verified acquisition dates.</strong> Do not keep the suggested date unless it is accurate. Trades from an incomplete tradebook may leave unresolved sells, transfers, splits or bonuses.</p>
        {message && <div role="status" className="alert alert-info">{message}</div>}
        {!draft ? <button className="btn btn-primary" disabled={busy} onClick={() => run(() => api.post('/kite/holdings-onboarding'), 'Kite holdings staged.')}>Fetch current Kite holdings</button> : <>
            <p>Snapshot captured {new Date(draft.snapshot_at).toLocaleString()}</p>
            <label className="d-block mb-3">Optional Zerodha Console tradebook CSV, TXT, XLSX, or Excel 2003 XML (.xls); upload multiple periods one at a time<input className="form-control" type="file" accept=".csv,.txt,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={e=>importTradebook(e.target.files?.[0])}/></label>
            <p>Legacy binary .xls workbooks are unsupported. Open one in Excel or LibreOffice and save a copy as .xlsx before uploading.</p>
            {draft.stocks.map(row => <section className="card mb-3 p-3" key={row.id}>
                <div className="d-flex justify-content-between"><h2>{row.symbol} ({row.exchange})</h2><span>{row.status}</span></div>
                <p>Kite: {row.kite_quantity} shares; average cost ₹{row.kite_average_price}; reported cost ₹{(row.kite_quantity * row.kite_average_price).toFixed(2)}. Staged net: {entries(row).reduce((n,e)=>n+(e.type==='buy'?1:-1)*Number(e.quantity),0)} shares; staged buy value ₹{entries(row).filter(e=>e.type==='buy').reduce((n,e)=>n+Number(e.quantity)*Number(e.price),0).toFixed(2)}. Buy value is not a FIFO cost basis when sells exist.</p>
                {entries(row).map((entry,i)=><div className="row g-2 mb-2" key={i}>
                    <div className="col"><label>Side<select className="form-select" value={entry.type} onChange={e=>setEntry(row,i,'type',e.target.value)}><option value="buy">Buy</option><option value="sell">Sell</option></select></label></div>
                    <div className="col"><label>Quantity<input className="form-control" type="number" min="0.0001" step="0.0001" value={entry.quantity} onChange={e=>setEntry(row,i,'quantity',Number(e.target.value))}/></label></div>
                    <div className="col"><label>Price<input className="form-control" type="number" min="0" step="0.0001" value={entry.price} onChange={e=>setEntry(row,i,'price',Number(e.target.value))}/></label></div>
                    <div className="col"><label>Date<input className="form-control" type="date" value={entry.date} onChange={e=>setEntry(row,i,'date',e.target.value)}/></label>{entry.date_placeholder && <small>Placeholder date, not acquisition evidence</small>}<small className="d-block">Source: {entry.provenance || 'manual'}{entry.broker_trade_id ? ` · Trade ID ${entry.broker_trade_id}` : ''}</small></div>
                </div>)}
                <div className="d-flex gap-2"><button className="btn btn-outline-secondary" disabled={busy} onClick={()=>setEdits(old=>({...old,[row.id]:[...entries(row),{type:'buy',quantity:1,price:0,date:new Date().toISOString().slice(0,10),date_placeholder:false,provenance:'manual'}]}))}>Add lot</button><button className="btn btn-outline-primary" disabled={busy} onClick={()=>save(row)}>Save edits</button><button className="btn btn-primary" disabled={busy||row.status==='committed'} onClick={()=>run(async()=>{ if(edits[row.id]) await api.put(`/kite/holdings-onboarding/stocks/${row.id}`,{entries:entries(row)}); await api.post(`/kite/holdings-onboarding/stocks/${row.id}/verify`); setEdits(old=>{const next={...old}; delete next[row.id]; return next;}); },'Stock verified separately from commit.')}>Verify stock</button></div>
            </section>)}
            <button className="btn btn-success" disabled={busy||!commitIds.length} onClick={()=>run(()=>api.post('/kite/holdings-onboarding/commit',{stock_ids:commitIds}),'Verified stocks committed.')}>Commit verified stocks ({commitIds.length})</button>
        </>}
    </main>;
}
