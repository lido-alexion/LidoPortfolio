import React, { useEffect, useRef, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';
import ExportDataButton from './ExportDataButton';
import ExportBasketPanel from './ExportBasketPanel';
import { DASHBOARD_CARDS, DASHBOARD_SUMMARY_FIELDS, factoryDashboardLayout, hasLocalDashboardLayout, inspectDashboardLayoutCompatibility, mergeDashboardLayoutRecord, migrateDashboardLayout, readLocalDashboardLayout, writeLocalDashboardLayout } from '../utils/dashboardLayouts';

export default function DashboardLayoutToolbar({ userId, onLayoutChange }) {
    const [layout, setLayout] = useState(() => readLocalDashboardLayout(userId));
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saveStatus, setSaveStatus] = useState('Saved');
    const [named, setNamed] = useState([]);
    const [namedLoading, setNamedLoading] = useState(true);
    const [namedLoadError, setNamedLoadError] = useState('');
    const [namedActionError, setNamedActionError] = useState('');
    const [namedActionBusy, setNamedActionBusy] = useState(false);
    const [selectedNamed, setSelectedNamed] = useState('');
    const [variant, setVariant] = useState(() => (typeof window !== 'undefined' && window.innerWidth < 768 ? 'mobile' : 'desktop'));
    const importRef = useRef(null);
    const saveTimer = useRef(null);
    const saveQueue = useRef(Promise.resolve());
    const layoutRef = useRef(layout);
    const selectedRef = useRef(selectedNamed);
    const selectedRevisionRef = useRef(1);
    const revisionsRef = useRef(new Map());
    const revisionRef = useRef(0);
    const pendingSaveRef = useRef(null);
    const deletingRecordRef = useRef('');
    const namedRequestRef = useRef(0);

    const flushSave = () => {
        clearTimeout(saveTimer.current);
        const pending = pendingSaveRef.current;
        if (!pending) return;
        pendingSaveRef.current = null;
        saveQueue.current = saveQueue.current.then(async () => {
            try {
                const expectedRevision = revisionsRef.current.get(pending.recordId) ?? pending.expectedRevision;
                const response = await api.put(`/dashboard-layouts/${pending.recordId}`, { definition: pending.definition, expected_revision: expectedRevision });
                const saved = response.data.data;
                const savedRevision = Number(saved.revision) || 1;
                setNamed((current) => current.map((item) => {
                    if (String(item.id) !== pending.recordId || Number(item.revision || 1) > savedRevision) return item;
                    return { ...item, definition: saved.definition, schema_version: saved.schema_version, revision: saved.revision, updated_at: saved.updated_at };
                }));
                const knownRevision = revisionsRef.current.get(pending.recordId) ?? 1;
                if (savedRevision >= Number(knownRevision)) revisionsRef.current.set(pending.recordId, savedRevision);
                if (selectedRef.current === pending.recordId && savedRevision >= Number(selectedRevisionRef.current)) selectedRevisionRef.current = savedRevision;
                if (revisionRef.current === pending.revision && selectedRef.current === pending.recordId) {
                    const latest = migrateDashboardLayout(saved.definition);
                    setLayout(latest);
                    layoutRef.current = latest;
                    onLayoutChange?.(latest);
                    setSaveStatus('Saved');
                }
            } catch (error) {
                if (revisionRef.current === pending.revision && selectedRef.current === pending.recordId) setSaveStatus('Failed');
                if (error?.response?.status === 409 && selectedRef.current === pending.recordId) setNamedActionError('This named dashboard changed on another device. Your local layout is preserved. Reload the page, then select this named dashboard to load the latest server copy.');
                showToast('Named dashboard update failed; local layout is still saved.', 'warning');
            }
        });
    };

    const loadNamedRecord = (record) => { if (selectedRef.current !== String(record.id)) flushSave(); setNamedActionError(''); const next = migrateDashboardLayout(record.definition); const revision = Number(record.revision) || 1; setSelectedNamed(String(record.id)); selectedRef.current = String(record.id); selectedRevisionRef.current = revision; revisionsRef.current.set(String(record.id), revision); setLayout(next); layoutRef.current = next; onLayoutChange?.(next); setSaveStatus('Saved'); };

    const loadNamedRecords = async ({ allowDefault = false } = {}) => {
        const requestId = ++namedRequestRef.current;
        setNamedLoading(true);
        setNamedLoadError('');
        try {
            const res = await api.get('/dashboard-layouts', { skipErrorToast: true });
            if (requestId !== namedRequestRef.current) return;
            const records = res.data?.data || [];
            setNamed((current) => records.map((record) => mergeDashboardLayoutRecord(current.find((item) => String(item.id) === String(record.id)), record)));
            const fallback = records.find((item) => item.is_default);
            if (allowDefault && !hasLocalDashboardLayout(userId) && fallback) loadNamedRecord(fallback);
        } catch {
            if (requestId === namedRequestRef.current) setNamedLoadError('Named dashboards could not be loaded. Please retry.');
        } finally {
            if (requestId === namedRequestRef.current) setNamedLoading(false);
        }
    };

    useEffect(() => {
        const next = readLocalDashboardLayout(userId);
        setNamed([]);
        revisionsRef.current = new Map();
        selectedRevisionRef.current = 1;
        setLayout(next);
        layoutRef.current = next;
        onLayoutChange?.(next);
        selectedRef.current = '';
        setSelectedNamed('');
        loadNamedRecords({ allowDefault: true });
        return () => { namedRequestRef.current += 1; flushSave(); };
    }, [userId, onLayoutChange]);

    const update = (next) => {
        const migrated = migrateDashboardLayout(next);
        setLayout(migrated); layoutRef.current = migrated; writeLocalDashboardLayout(userId, migrated); onLayoutChange?.(migrated);
        if (selectedRef.current && selectedRef.current !== deletingRecordRef.current) {
            const recordId = selectedRef.current;
            const revision = ++revisionRef.current;
            pendingSaveRef.current = { recordId, definition: migrated, revision, expectedRevision: selectedRevisionRef.current };
            setSaveStatus('Saving');
            clearTimeout(saveTimer.current);
            saveTimer.current = setTimeout(flushSave, 500);
        }
    };
    const toggle = (collection, id, visible) => {
        const next = structuredClone(layout);
        const item = next[variant][collection].find((entry) => entry.id === id);
        if (!item) return;
        if (collection === 'cards' && !visible && next[variant].cards.filter((entry) => entry.visible !== false).length <= 1) return;
        if (collection === 'summaryFields' && !visible && DASHBOARD_SUMMARY_FIELDS.find((entry) => entry.id === id)?.core) return;
        item.visible = visible; update(next);
    };
    const moveCard = (id, delta) => {
        const next = structuredClone(layout); const cards = next[variant].cards; const index = cards.findIndex((card) => card.id === id); const target = index + delta;
        if (index < 0 || target < 0 || target >= cards.length) return;
        [cards[index], cards[target]] = [cards[target], cards[index]]; cards.forEach((card, order) => { card.order = order; }); update(next);
    };
    const reorderCard = (sourceId, targetId) => {
        if (!sourceId || sourceId === targetId || layout.locked) return;
        const next = structuredClone(layout); const cards = next[variant].cards;
        const source = cards.findIndex((card) => card.id === sourceId); const target = cards.findIndex((card) => card.id === targetId);
        if (source < 0 || target < 0) return;
        const [moved] = cards.splice(source, 1); cards.splice(target, 0, moved); cards.forEach((card, order) => { card.order = order; }); update(next);
    };
    const moveSummaryField = (id, delta) => {
        const next = structuredClone(layout); const fields = next[variant].summaryFields; const index = fields.findIndex((field) => field.id === id); const target = index + delta;
        if (index < 0 || target < 0 || target >= fields.length) return;
        [fields[index], fields[target]] = [fields[target], fields[index]]; fields.forEach((field, order) => { field.order = order; }); update(next);
    };
    const resizeCard = (id, size) => { const next = structuredClone(layout); const card = next[variant].cards.find((entry) => entry.id === id); if (card) card.size = size; update(next); };
    const saveAsNamed = async () => {
        const name = window.prompt('Name this dashboard'); if (!name?.trim()) return;
        setSaving(true);
        try { const response = await api.post('/dashboard-layouts', { name: name.trim(), definition: layout }); setNamed((current) => [...current, response.data.data].sort((a, b) => a.name.localeCompare(b.name))); showToast('Dashboard saved.'); }
        catch { showToast('Dashboard could not be saved.', 'danger'); } finally { setSaving(false); }
    };
    const loadNamed = (id) => { const record = named.find((item) => String(item.id) === String(id)); if (record) loadNamedRecord(record); };
    const renameNamed = async () => { const record = named.find((item) => String(item.id) === selectedNamed); if (!record) return; const name = window.prompt('Rename dashboard', record.name); if (!name?.trim() || name.trim() === record.name) return; try { const response = await api.put(`/dashboard-layouts/${record.id}`, { name: name.trim() }); setNamed((current) => current.map((item) => String(item.id) === String(record.id) ? mergeDashboardLayoutRecord(item, response.data.data) : item).sort((a, b) => a.name.localeCompare(b.name))); } catch { showToast('Dashboard could not be renamed.', 'danger'); } };
    const duplicateToLocal = () => { const record = named.find((item) => String(item.id) === selectedNamed); if (!record) return; flushSave(); const next = migrateDashboardLayout(layoutRef.current); setSelectedNamed(''); selectedRef.current = ''; update(next); showToast('Dashboard copied to this device.'); };
    const setDefault = async () => {
        const record = named.find((item) => String(item.id) === selectedNamed);
        if (!record || namedActionBusy) return;
        setNamedActionBusy(true);
        setNamedActionError('');
        try {
            const response = await api.put(`/dashboard-layouts/${record.id}`, { is_default: true });
            const confirmed = response.data?.data;
            setNamed((current) => current.map((item) => {
                if (String(item.id) !== String(record.id)) return { ...item, is_default: false };
                return { ...mergeDashboardLayoutRecord(item, confirmed || item), is_default: confirmed?.is_default ?? true };
            }));
            showToast('Default dashboard updated.');
        } catch {
            setNamedActionError('Default dashboard could not be confirmed. Review named dashboards before retrying.');
        } finally {
            setNamedActionBusy(false);
        }
    };
    const retryNamedSave = () => {
        const recordId = selectedRef.current;
        if (!recordId || deletingRecordRef.current === recordId) return;
        const revision = ++revisionRef.current;
        pendingSaveRef.current = { recordId, definition: migrateDashboardLayout(layoutRef.current), revision, expectedRevision: selectedRevisionRef.current };
        setSaveStatus('Saving');
        flushSave();
    };
    const duplicateNamed = async () => { const record = named.find((item) => String(item.id) === selectedNamed); if (!record) return; const name = window.prompt('Name the duplicated dashboard', `${record.name} copy`); if (!name?.trim()) return; try { flushSave(); await saveQueue.current; const response = await api.post(`/dashboard-layouts/${record.id}/duplicate`, { name: name.trim() }); setNamed((current) => [...current, response.data.data].sort((a, b) => a.name.localeCompare(b.name))); showToast('Dashboard duplicated.'); } catch { showToast('Dashboard could not be duplicated.', 'danger'); } };
    const deleteNamed = async () => {
        const record = named.find((item) => String(item.id) === selectedNamed);
        if (!record || !window.confirm(`Delete ${record.name}?`)) return;
        const recordId = String(record.id);
        deletingRecordRef.current = recordId;
        flushSave();
        try {
            await saveQueue.current;
            await api.delete(`/dashboard-layouts/${record.id}`);
            clearTimeout(saveTimer.current);
            if (pendingSaveRef.current?.recordId === recordId) pendingSaveRef.current = null;
            selectedRef.current = '';
            setSelectedNamed('');
            deletingRecordRef.current = '';
            setNamed((current) => current.filter((item) => String(item.id) !== recordId));
            setSaveStatus('Saved');
        } catch {
            deletingRecordRef.current = '';
            showToast('Dashboard could not be deleted.', 'danger');
        }
    };
    const importJson = async (event) => {
        const input = event.target;
        const file = input.files?.[0];
        if (!file) return;
        let valid = false;
        setSaving(true);
        try {
            if (file.size > 32768) throw new Error('too large');
            const raw = JSON.parse(await file.text());
            const warnings = inspectDashboardLayoutCompatibility(raw);
            const imported = migrateDashboardLayout(raw);
            valid = true;
            const name = window.prompt('Name the imported dashboard');
            if (!name?.trim()) return;
            const response = await api.post('/dashboard-layouts', { name: name.trim(), definition: imported });
            setNamed((current) => [...current, response.data.data].sort((a, b) => a.name.localeCompare(b.name)));
            showToast(warnings.length ? `Imported with compatibility adjustments: ${warnings.join(' ')}` : 'Dashboard imported.', warnings.length ? 'warning' : undefined);
        } catch {
            showToast(valid ? 'Dashboard import could not be confirmed. Your file is unchanged; retry the import.' : 'Dashboard JSON is invalid or exceeds the 32 KB limit.', 'danger');
        } finally { input.value = ''; setSaving(false); }
    };
    const exportJson = () => {
        const blob = new Blob([JSON.stringify(layout, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'stox-dashboard-layout.json'; anchor.click(); URL.revokeObjectURL(url);
    };
    return (
        <div className="card mb-3" data-testid="dashboard-layout-toolbar">
            <div className="card-body py-2">
                {namedLoadError && <div className="alert alert-danger py-2" role="alert"><span>{namedLoadError}</span><button type="button" className="btn btn-sm btn-outline-primary ms-2" disabled={namedLoading} onClick={() => loadNamedRecords()}>Retry named dashboards</button></div>}
                {namedActionError && <div className="alert alert-danger py-2" role="alert">{namedActionError}</div>}
                <div className="d-flex flex-wrap align-items-center gap-2">
                    <strong className="small">Dashboard layout</strong>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setEditing((value) => !value)} aria-expanded={editing}>{editing ? 'Done' : 'Customize'}</button>
                    <button type="button" className="btn btn-sm btn-outline-secondary" disabled={layout.locked} onClick={() => { flushSave(); setSelectedNamed(''); selectedRef.current = ''; setSaveStatus('Saved'); update(factoryDashboardLayout()); }}>Reset to defaults</button>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={exportJson}>Export JSON</button>
                    <button type="button" className="btn btn-sm btn-outline-secondary" disabled={saving} onClick={() => importRef.current?.click()}>Import JSON</button>
                    <input ref={importRef} aria-label="Dashboard layout JSON file" disabled={saving} type="file" accept="application/json,.json" className="d-none" onChange={importJson} />
                    <ExportDataButton dataset="dashboard-summary" label="Export dashboard data" fields={['field', 'value']} />
                    <ExportBasketPanel />
                    <button type="button" className="btn btn-sm btn-outline-primary" onClick={saveAsNamed} disabled={saving}>{saving ? 'Saving…' : 'Save as named dashboard'}</button>
                    {named.length > 0 && <><label className="visually-hidden" htmlFor="named-dashboard-select">Named dashboard</label><select id="named-dashboard-select" className="form-select form-select-sm w-auto" value={selectedNamed} disabled={namedLoading || namedActionBusy} onChange={(event) => { setNamedActionError(''); loadNamed(event.target.value); }}><option value="">Named dashboards</option>{named.map((item) => <option value={item.id} key={item.id}>{item.name}{item.is_default ? ' (default)' : ''}</option>)}</select>{selectedNamed && <><button type="button" className="btn btn-sm btn-outline-secondary" disabled={namedActionBusy} onClick={setDefault}>Set default</button><button type="button" className="btn btn-sm btn-outline-secondary" disabled={namedActionBusy} onClick={renameNamed}>Rename</button><button type="button" className="btn btn-sm btn-outline-secondary" disabled={namedActionBusy} onClick={duplicateNamed}>Duplicate</button><button type="button" className="btn btn-sm btn-outline-secondary" disabled={namedActionBusy} onClick={duplicateToLocal}>Copy to this device</button><button type="button" className="btn btn-sm btn-outline-danger" disabled={namedActionBusy} onClick={deleteNamed}>Delete named</button><span role="status" aria-live="polite" className="small text-muted">{saveStatus}</span>{saveStatus === 'Failed' && <button type="button" className="btn btn-sm btn-outline-primary" onClick={retryNamedSave}>Retry named dashboard save</button>}</>}</>}
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => update({ ...layout, locked: !layout.locked })}>{layout.locked ? 'Unlock layout' : 'Lock layout'}</button>
                </div>
                {editing && !layout.locked && <div className="row g-3 mt-1">
                    <div className="col-12"><label className="small" htmlFor="dashboard-variant">Editing variant</label><select id="dashboard-variant" className="form-select form-select-sm w-auto" value={variant} onChange={(event) => setVariant(event.target.value)}><option value="desktop">Desktop</option><option value="mobile">Mobile</option></select></div>
                    <fieldset className="col-12 col-lg-6"><legend className="fs-6">Summary fields</legend>{[...DASHBOARD_SUMMARY_FIELDS].sort((a, b) => (layout[variant].summaryFields.find((entry) => entry.id === a.id)?.order ?? 0) - (layout[variant].summaryFields.find((entry) => entry.id === b.id)?.order ?? 0)).map((field) => <div className="d-flex align-items-center gap-2 mb-1" key={field.id}><label className="small flex-grow-1"><input type="checkbox" className="form-check-input me-2" checked={layout[variant].summaryFields.find((entry) => entry.id === field.id)?.visible !== false} disabled={field.core || layout.locked} onChange={(event) => toggle('summaryFields', field.id, event.target.checked)} />{field.label}{field.core ? ' (required)' : ''}</label><button type="button" className="btn btn-sm btn-link" disabled={layout.locked} onClick={() => moveSummaryField(field.id, -1)} aria-label={`Move ${field.label} up`}>↑</button><button type="button" className="btn btn-sm btn-link" disabled={layout.locked} onClick={() => moveSummaryField(field.id, 1)} aria-label={`Move ${field.label} down`}>↓</button></div>)}</fieldset>
                    <fieldset className="col-12 col-lg-6"><legend className="fs-6">Cards</legend>{[...DASHBOARD_CARDS].sort((a, b) => (layout[variant].cards.find((entry) => entry.id === a.id)?.order ?? 0) - (layout[variant].cards.find((entry) => entry.id === b.id)?.order ?? 0)).map((card) => { const saved = layout[variant].cards.find((entry) => entry.id === card.id); const required = card.id === 'portfolio-summary'; return <div className="d-flex align-items-center gap-2 mb-1" key={card.id} draggable={!layout.locked} onDragStart={(event) => event.dataTransfer.setData('text/dashboard-card', card.id)} onDragOver={(event) => event.preventDefault()} onDrop={(event) => { event.preventDefault(); reorderCard(event.dataTransfer.getData('text/dashboard-card'), card.id); }}><label className="small flex-grow-1"><input type="checkbox" className="form-check-input me-2" checked={saved?.visible !== false} disabled={layout.locked || required} onChange={(event) => toggle('cards', card.id, event.target.checked)} />{card.label}{required ? ' (required)' : ''}</label><select aria-label={`${card.label} size`} className="form-select form-select-sm w-auto" disabled={layout.locked} value={saved?.size || card.defaultSize} onChange={(event) => resizeCard(card.id, event.target.value)}>{card.sizes.map((size) => <option key={size} value={size}>{size}</option>)}</select><button type="button" className="btn btn-sm btn-link" disabled={layout.locked} onClick={() => moveCard(card.id, -1)} aria-label={`Move ${card.label} up`}>↑</button><button type="button" className="btn btn-sm btn-link" disabled={layout.locked} onClick={() => moveCard(card.id, 1)} aria-label={`Move ${card.label} down`}>↓</button></div>; })}</fieldset>
                </div>}
            </div>
        </div>
    );
}
