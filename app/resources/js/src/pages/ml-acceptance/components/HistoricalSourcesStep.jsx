import React, { useState } from 'react';
import StepCard from './StepCard';
import api from '../../../api';
import InfoHelp from './InfoHelp';
import ConfirmationPrompt from './ConfirmationPrompt';

const maxBytes = 16 * 1024 * 1024;

export default function HistoricalSourcesStep({ wizard, sourcesPage, page, setPage, base, run, busy, canMutate, selected, setSelected, selectedDetails, onSourceToggle }) {
    const [date, setDate] = useState('');
    const [family, setFamily] = useState('nse_cash_bhavcopy');
    const [file, setFile] = useState(null);
    const [resumeId, setResumeId] = useState('');
    const [uploadProgress, setUploadProgress] = useState(null);
    const [filter, setFilter] = useState('all');
    const [cancelSource, setCancelSource] = useState(null);
    const requirements = wizard?.required_sources || [];
    const upload = async () => {
        if (!file || file.size > maxBytes) throw new Error('Choose an official CSV or single-CSV ZIP up to 16 MiB.');
        const bytes = new Uint8Array(await file.arrayBuffer());
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        const sha256 = Array.from(new Uint8Array(digest), (v) => v.toString(16).padStart(2, '0')).join('');
        let source;
        if (resumeId) {
            source = (await api.get(`${base}/sources/${resumeId}`)).data.data;
            if (source.manifest.sha256 !== sha256 || source.manifest.bytes !== file.size) throw new Error('Reselect the identical original file to resume.');
        } else {
            const requirement = requirements.find((item) => item.date === date);
            if (!requirement || !requirement.accepted_families?.includes(family)) throw new Error('Choose a source family and date reported as accepted for this requirement.');
            source = requirement.sources?.find((item) => ['uploading', 'queued', 'sealed'].includes(item.status)
                && item.family === family && item.filename === file.name && item.bytes === file.size
                && item.id && item.status !== 'failed');
            if (source) {
                source = (await api.get(`${base}/sources/${source.id}`)).data.data;
                if (source.manifest.sha256 !== sha256) throw new Error('A source with the same name and size has different contents. Start a new upload only after reviewing the source list.');
                setResumeId(source.id);
            } else {
                source = (await api.post(`${base}/sources`, { version: 1, source: family, date, filename: file.name, bytes: file.size, sha256 })).data.data;
                setResumeId(source.id);
            }
        }
        if (source.status === 'queued' || source.status === 'sealed') { setFile(null); setUploadProgress(null); return; }
        if (source.status !== 'uploading') throw new Error('This source can no longer be resumed. Start a new upload after reviewing its status.');
        const offsetStart = source.received ?? 0;
        if (!Number.isSafeInteger(offsetStart) || offsetStart < 0 || offsetStart > bytes.length || offsetStart > source.manifest.bytes) throw new Error('The saved upload offset is invalid. Refresh the source list before resuming.');
        setUploadProgress({ received: offsetStart, total: bytes.length });
        for (let offset = offsetStart; offset < bytes.length; offset += 1024 * 1024) {
            const chunk = bytes.subarray(offset, Math.min(offset + 1024 * 1024, bytes.length));
            let binary = ''; for (const byte of chunk) binary += String.fromCharCode(byte);
            await api.put(`${base}/sources/${source.id}/chunks`, { offset, chunk: btoa(binary) });
            setUploadProgress({ received: Math.min(offset + chunk.length, bytes.length), total: bytes.length });
        }
        await api.post(`${base}/sources/${source.id}/finalize`);
        setResumeId('');
        setFile(null);
        setUploadProgress(null);
    };
    const toggle = (source, checked) => {
        setSelected((items) => checked ? [...new Set([...items, source.id])] : items.filter((value) => value !== source.id));
        onSourceToggle?.(source, checked);
    };
    return <StepCard number={3} title="Add historical sources" what="StoX needs official NSE files for the exact dates reported by preflight. Files are validated, hashed and stored privately; StoX never downloads an archive URL for you." learn="Historical sources reconstruct what was known on a past date. Only CSV or a ZIP containing one CSV is accepted, up to 16 MiB. Select the source family and date that match the official archive. A sealed source may be reused only for its reported date and family.">
        <h4 className="h6">Required source dates <InfoHelp label="historical source">An official dated NSE CSV or ZIP used to reconstruct what was known on that historical date. StoX validates, hashes and stores it privately.</InfoHelp></h4>
        {requirements.length === 0 ? <p>No missing source dates are currently reported. Refresh or run preflight to see requirements.</p> : <ul>{requirements.map((item) => <li key={item.date}>{item.date} — {item.horizons.join(', ')} horizon(s)</li>)}</ul>}
        <div className="row g-2 align-items-end">
            <div className="col-12 col-md-3"><label className="form-label" htmlFor="source-date">Required date</label><select id="source-date" className="form-select" value={date} onChange={(e) => setDate(e.target.value)}><option value="">Select a required date</option>{requirements.map((item) => <option key={item.date}>{item.date}</option>)}</select></div>
            <div className="col-12 col-md-3"><label className="form-label" htmlFor="source-family">Source family</label><select id="source-family" className="form-select" value={family} onChange={(e) => setFamily(e.target.value)}><option value="nse_cash_bhavcopy">NSE cash bhavcopy</option><option value="nse_mii_security_file">NSE MII security file</option></select></div>
            <div className="col-12 col-md-6"><label className="form-label" htmlFor="source-file">Official CSV or ZIP</label><input id="source-file" className="form-control" type="file" accept=".csv,.zip" onChange={(e) => setFile(e.target.files?.[0] || null)} /></div>
        </div>
        <div className="mt-2"><label className="form-label" htmlFor="resume-source">Resume incomplete upload</label><select id="resume-source" className="form-select" value={resumeId} onChange={(e) => setResumeId(e.target.value)}><option value="">Start new upload</option>{(sourcesPage?.data || []).filter((source) => source.status === 'uploading').map((source) => <option key={source.id} value={source.id}>{source.manifest.filename} — {source.received} bytes</option>)}</select></div>
        {canMutate && <button type="button" className="btn btn-primary mt-2" disabled={busy || !file || !date} onClick={() => run('upload', upload)}>Upload and queue validation</button>}
        {uploadProgress && <div role="status" aria-live="polite" className="mt-2">Uploaded {uploadProgress.received.toLocaleString()} of {uploadProgress.total.toLocaleString()} bytes.</div>}
        <p className="small mt-2">If upload is interrupted, keep the same original file and choose its saved entry under “Resume incomplete upload.” StoX continues from its saved byte offset.</p>
        <label htmlFor="source-filter" className="form-label mt-3">Filter sources</label><select id="source-filter" className="form-select" value={filter} onChange={(e) => setFilter(e.target.value)}><option value="all">All sources</option><option value="required">Required</option><option value="uploading">Uploading</option><option value="validating">Validating</option><option value="sealed">Sealed</option><option value="failed">Failed</option></select>
        {(sourcesPage?.data || []).length > 0 && <div className="table-responsive mt-2"><table className="table table-sm"><thead><tr><th>Select sealed source</th><th>File and date</th><th>Status</th><th>Actions</th></tr></thead><tbody>{sourcesPage.data.filter((source) => filter === 'all' || (filter === 'required' && requirements.some((req) => req.date === source.manifest.date)) || (filter === 'validating' && source.status === 'queued') || source.status === filter).map((source) => { const req = requirements.find((item) => item.date === source.manifest.date); const matchesRequirement = req?.accepted_families?.includes(source.manifest.source); const anotherForDate = Object.values(selectedDetails || {}).some((item) => item.date === source.manifest.date && item.id !== source.id); return <tr key={source.id}><td><input type="checkbox" aria-label={`Select ${source.manifest.filename}`} checked={selected.includes(source.id)} disabled={!canMutate || source.status !== 'sealed' || !matchesRequirement || (!selected.includes(source.id) && anotherForDate)} onChange={(e) => toggle(source, e.target.checked)} /></td><td>{source.manifest.filename}<br /><small>{source.manifest.date} · {source.manifest.source}</small></td><td>{source.status === 'queued' ? 'Validating' : source.status}{source.status === 'failed' && <small className="d-block">Validation failed. Check the official filename and content date, then start a new upload.</small>}</td><td>{source.status === 'queued' && canMutate && <button type="button" className="btn btn-outline-secondary btn-sm me-2" disabled={busy} onClick={() => run('source-resume', () => api.post(`${base}/sources/${source.id}/resume`))}>Resume validation</button>}{['uploading', 'queued'].includes(source.status) && canMutate && <button type="button" className="btn btn-outline-danger btn-sm" disabled={busy} onClick={() => setCancelSource(source)}>Cancel upload</button>}</td></tr>; })}</tbody></table></div>}
        {cancelSource && <ConfirmationPrompt titleId="cancel-source-title" onDismiss={() => setCancelSource(null)}><h4 id="cancel-source-title" className="h6" data-confirmation-title tabIndex={-1}>Confirm source cancellation</h4><p>The incomplete upload or queued validation will stop. Any sealed source and other saved evidence remain unchanged.</p><button type="button" className="btn btn-outline-danger me-2" disabled={busy} onClick={async () => { const source = cancelSource; setCancelSource(null); await run('source-cancel', () => api.post(`${base}/sources/${source.id}/cancel`)); }}>Confirm cancellation</button><button type="button" className="btn btn-outline-secondary" onClick={() => setCancelSource(null)}>Keep source</button></ConfirmationPrompt>}
        <div className="d-flex gap-2 align-items-center mt-2"><button type="button" className="btn btn-outline-secondary btn-sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous sources</button><span>Page {page} of {sourcesPage?.last_page || 1}</span><button type="button" className="btn btn-outline-secondary btn-sm" disabled={page >= (sourcesPage?.last_page || 1)} onClick={() => setPage(page + 1)}>Next sources</button></div>
        <p className="mt-2">Continue after every required date has a matching sealed source.</p>
    </StepCard>;
}
