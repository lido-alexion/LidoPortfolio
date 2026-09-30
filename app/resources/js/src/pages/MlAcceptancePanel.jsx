import React, { useEffect, useState } from 'react';
import api from '../api';

const base = '/v1/admin/ml/acceptance';
const initialDate = () => new Date().toISOString().slice(0, 10);

export default function MlAcceptancePanel() {
    const [report, setReport] = useState(null);
    const [sources, setSources] = useState([]);
    const [sourcePage, setSourcePage] = useState(1);
    const [sourceLastPage, setSourceLastPage] = useState(1);
    const [selected, setSelected] = useState([]);
    const [date, setDate] = useState(initialDate);
    const [cutoff, setCutoff] = useState(initialDate);
    const [family, setFamily] = useState('nse_cash_bhavcopy');
    const [file, setFile] = useState(null);
    const [resumeId, setResumeId] = useState('');
    const [backfillId, setBackfillId] = useState('');
    const [backfill, setBackfill] = useState(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');

    const refresh = async (page = sourcePage) => {
        const [r, s] = await Promise.all([api.get(base), api.get(`${base}/sources${page > 1 ? `?page=${page}` : ''}`)]);
        setReport(r.data.data); setSources(s.data.data.data || []);
        setSourcePage(s.data.data.current_page || page);
        setSourceLastPage(s.data.data.last_page || 1);
        if (backfillId) setBackfill((await api.get(`${base}/backfills/${backfillId}`)).data.data);
    };
    const perform = async (work, page = sourcePage) => {
        setBusy(true); setMessage('');
        try { await work(); await refresh(page); }
        catch (error) { setMessage(Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || error.message); }
        finally { setBusy(false); }
    };
    useEffect(() => { perform(async () => {}); }, []); // Only explicit controls mutate operations.

    const upload = async () => {
        if (!file || file.size > 16 * 1024 * 1024) throw new Error('Choose a CSV or ZIP up to 16 MiB.');
        const bytes = new Uint8Array(await file.arrayBuffer());
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        const sha256 = Array.from(new Uint8Array(digest), (v) => v.toString(16).padStart(2, '0')).join('');
        let source;
        if (resumeId) {
            source = (await api.get(`${base}/sources/${resumeId}`)).data.data;
            if (source.manifest.sha256 !== sha256 || source.manifest.bytes !== file.size) throw new Error('Reselect the identical original file to resume.');
        } else {
            source = (await api.post(`${base}/sources`, { version: 1, source: family, date, filename: file.name, bytes: file.size, sha256 })).data.data;
            setResumeId(source.id);
        }
        for (let offset = source.received; offset < bytes.length; offset += 1024 * 1024) {
            const chunk = bytes.subarray(offset, Math.min(offset + 1024 * 1024, bytes.length));
            let binary = ''; for (const byte of chunk) binary += String.fromCharCode(byte);
            await api.put(`${base}/sources/${source.id}/chunks`, { offset, chunk: btoa(binary) });
            setMessage(`Uploaded ${Math.min(offset + chunk.length, bytes.length)} of ${bytes.length} bytes`);
        }
        await api.post(`${base}/sources/${source.id}/finalize`);
        setResumeId(''); setMessage('Validation queued. Refresh to inspect its result.');
    };
    const preview = async () => {
        const result = (await api.post(`${base}/backfills/preview`, { sources: selected })).data.data;
        setBackfill(result); setBackfillId(String(result.id));
    };
    const campaignAction = async (action) => { await api.post(`${base}/campaigns/${report.campaign_id}/${action}`); };

    return <section className="card mb-3"><div className="card-body">
        <h2 className="h5">Production ML acceptance</h2>
        <p className="text-muted">Stage dated NSE files, preview historical membership changes, then explicitly run the linked 1m, 3m and 6m acceptance campaign. Models remain candidates. Scheduling requires current production evidence.</p>
        <button className="btn btn-outline-secondary btn-sm mb-2" disabled={busy} onClick={() => perform(async () => {})}>Refresh acceptance report</button>
        {message && <p role="status">{message}</p>}
        {report && <p>Campaign: {report.campaign_status}. Lifecycle readiness: {report.readiness.ready ? 'Ready' : report.readiness.reason}. Queue configuration: {report.runtime.queue_configuration_ready ? 'Ready; worker execution requires evidence' : 'Not ready'}.</p>}
        <fieldset disabled={busy}>
            <legend className="h6">Private NSE source upload</legend>
            <label className="me-2">Source family <select className="form-select form-select-sm" value={family} onChange={(e) => setFamily(e.target.value)}><option value="nse_cash_bhavcopy">Cash bhavcopy</option><option value="nse_mii_security_file">MII security file</option></select></label>
            <label className="me-2">Source date <input className="form-control form-control-sm" type="date" value={date} onChange={(e) => setDate(e.target.value)} /></label>
            <label className="me-2">Official CSV or ZIP <input className="form-control form-control-sm" type="file" accept=".csv,.zip" onChange={(e) => setFile(e.target.files?.[0] || null)} /></label>
            <label className="me-2">Resume upload <select className="form-select form-select-sm" value={resumeId} onChange={(e) => setResumeId(e.target.value)}><option value="">New upload</option>{sources.filter((s) => s.status === 'uploading').map((s) => <option key={s.id} value={s.id}>{s.manifest.filename} ({s.received} bytes)</option>)}</select></label>
            <button className="btn btn-outline-primary btn-sm" disabled={!file} onClick={() => perform(upload)}>Upload and queue validation</button>
            <div className="table-responsive mt-3"><table className="table table-sm"><thead><tr><th>Select</th><th>Source</th><th>Status</th><th>Action</th></tr></thead><tbody>{sources.map((s) => <tr key={s.id}>
                <td><input aria-label={`Select ${s.manifest.filename}`} type="checkbox" disabled={s.status !== 'sealed'} checked={selected.includes(s.id)} onChange={(e) => setSelected((ids) => e.target.checked ? [...ids, s.id] : ids.filter((id) => id !== s.id))} /></td>
                <td>{s.manifest.filename}<br /><small>{s.manifest.date}</small></td><td>{s.status}</td>
                <td>{s.status === 'queued' && <button className="btn btn-outline-secondary btn-sm me-2" onClick={() => perform(() => api.post(`${base}/sources/${s.id}/resume`))}>Resume validation</button>}{['uploading', 'queued'].includes(s.status) && <button className="btn btn-outline-danger btn-sm" onClick={() => perform(() => api.post(`${base}/sources/${s.id}/cancel`))}>Cancel upload</button>}</td>
            </tr>)}</tbody></table></div>
            <div className="mb-2">
                <button className="btn btn-outline-secondary btn-sm me-2" disabled={sourcePage <= 1} onClick={() => perform(async () => {}, sourcePage - 1)}>Previous sources</button>
                <span>Source page {sourcePage} of {sourceLastPage}</span>
                <button className="btn btn-outline-secondary btn-sm ms-2" disabled={sourcePage >= sourceLastPage} onClick={() => perform(async () => {}, sourcePage + 1)}>Next sources</button>
            </div>
            <button className="btn btn-outline-primary btn-sm" disabled={!selected.length} onClick={() => perform(preview)}>Queue backfill dry-run</button>
            <label className="ms-2">Backfill ID <input type="number" min="1" value={backfillId} onChange={(e) => setBackfillId(e.target.value)} /></label>
            <button className="btn btn-outline-secondary btn-sm ms-2" onClick={() => perform(async () => {})}>Load backfill status</button>
            {backfill && <div className="mt-2"><p>Backfill {backfill.id}: {backfill.status}, {backfill.acceptance.mode}; {backfill.acceptance.cursor} of {backfill.requested_dates.length} dates.</p>
                {backfill.status === 'completed' && backfill.acceptance.mode === 'preview' && <button className="btn btn-warning btn-sm me-2" onClick={() => perform(async () => { setBackfill((await api.post(`${base}/backfills/${backfill.id}/apply`)).data.data); })}>Apply reviewed backfill</button>}
                {['queued', 'running', 'failed'].includes(backfill.status) && ['resume', 'cancel'].map((action) => <button key={action} className="btn btn-outline-secondary btn-sm me-2" onClick={() => perform(async () => { setBackfill((await api.post(`${base}/backfills/${backfill.id}/${action}`)).data.data); })}>{action} backfill</button>)}
                <details><summary>Backfill evidence</summary><pre>{JSON.stringify(backfill.acceptance.results, null, 2)}</pre></details>
            </div>}
            <hr /><legend className="h6">Acceptance campaign</legend>
            <label className="me-2">Training cutoff <input type="date" value={cutoff} onChange={(e) => setCutoff(e.target.value)} /></label>
            <button className="btn btn-outline-primary btn-sm me-2" disabled={['preflight', 'ready', 'training'].includes(report?.campaign_status)} onClick={() => perform(() => api.post(`${base}/campaigns`, { cutoff_date: cutoff }))}>Queue campaign preflight</button>
            {report?.campaign_status === 'ready' && <button className="btn btn-warning btn-sm me-2" onClick={() => perform(() => campaignAction('start'))}>Start 1m/3m/6m training</button>}
            {['preflight', 'training', 'cancelled'].includes(report?.campaign_status) && <button className="btn btn-outline-secondary btn-sm me-2" onClick={() => perform(() => campaignAction('resume'))}>Resume campaign</button>}
            {['preflight', 'ready', 'training'].includes(report?.campaign_status) && <button className="btn btn-outline-danger btn-sm" onClick={() => perform(() => campaignAction('cancel'))}>Cancel campaign</button>}
        </fieldset>
        {report && <details className="mt-3"><summary>Read-only acceptance evidence and blocking reasons</summary><pre style={{ maxHeight: 500, overflow: 'auto' }}>{JSON.stringify(report, null, 2)}</pre></details>}
    </div></section>;
}
