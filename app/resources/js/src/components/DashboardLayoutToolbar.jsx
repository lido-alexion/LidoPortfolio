import React, { useEffect, useRef, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';
import ExportDataButton from './ExportDataButton';
import ExportBasketPanel from './ExportBasketPanel';
import { DASHBOARD_CARDS, DASHBOARD_SUMMARY_FIELDS, factoryDashboardLayout, hasLocalDashboardLayout, migrateDashboardLayout, readLocalDashboardLayout, writeLocalDashboardLayout } from '../utils/dashboardLayouts';

export default function DashboardLayoutToolbar({ userId, onLayoutChange }) {
    const [layout, setLayout] = useState(() => readLocalDashboardLayout(userId));
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [named, setNamed] = useState([]);
    const [selectedNamed, setSelectedNamed] = useState('');
    const [variant, setVariant] = useState(() => (typeof window !== 'undefined' && window.innerWidth < 768 ? 'mobile' : 'desktop'));
    const importRef = useRef(null);

    useEffect(() => {
        const next = readLocalDashboardLayout(userId);
        setLayout(next);
        onLayoutChange?.(next);
        api.get('/dashboard-layouts', { skipErrorToast: true }).then((res) => { const records = res.data?.data || []; setNamed(records); const fallback = records.find((item) => item.is_default); if (!hasLocalDashboardLayout(userId) && fallback) loadNamedRecord(fallback); }).catch(() => setNamed([]));
    }, [userId, onLayoutChange]);

    const loadNamedRecord = (record) => { const next = migrateDashboardLayout(record.definition); setSelectedNamed(String(record.id)); setLayout(next); writeLocalDashboardLayout(userId, next); onLayoutChange?.(next); };

    const update = (next) => {
        const migrated = migrateDashboardLayout(next);
        setLayout(migrated); writeLocalDashboardLayout(userId, migrated); onLayoutChange?.(migrated);
        if (selectedNamed) {
            const record = named.find((item) => String(item.id) === String(selectedNamed));
            if (record) {
                api.put(`/dashboard-layouts/${record.id}`, { definition: migrated }).then((response) => {
                    setNamed((current) => current.map((item) => item.id === record.id ? response.data.data : item));
                }).catch(() => showToast('Named dashboard update failed; local layout is still saved.', 'warning'));
            }
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
    const setDefault = async () => { const record = named.find((item) => String(item.id) === selectedNamed); if (!record) return; await api.put(`/dashboard-layouts/${record.id}`, { is_default: true }); setNamed((current) => current.map((item) => ({ ...item, is_default: item.id === record.id }))); showToast('Default dashboard updated.'); };
    const duplicateNamed = async () => { const record = named.find((item) => String(item.id) === selectedNamed); if (!record) return; const name = window.prompt('Name the duplicated dashboard', `${record.name} copy`); if (!name?.trim()) return; try { const response = await api.post(`/dashboard-layouts/${record.id}/duplicate`, { name: name.trim() }); setNamed((current) => [...current, response.data.data].sort((a, b) => a.name.localeCompare(b.name))); showToast('Dashboard duplicated.'); } catch { showToast('Dashboard could not be duplicated.', 'danger'); } };
    const deleteNamed = async () => { const record = named.find((item) => String(item.id) === selectedNamed); if (!record || !window.confirm(`Delete ${record.name}?`)) return; await api.delete(`/dashboard-layouts/${record.id}`); setNamed((current) => current.filter((item) => item.id !== record.id)); setSelectedNamed(''); showToast('Dashboard deleted.'); };
    const importJson = async (event) => { const file = event.target.files?.[0]; if (!file) return; try { const imported = migrateDashboardLayout(JSON.parse(await file.text())); const name = window.prompt('Name the imported dashboard'); if (!name?.trim()) return; const response = await api.post('/dashboard-layouts', { name: name.trim(), definition: imported }); setNamed((current) => [...current, response.data.data].sort((a, b) => a.name.localeCompare(b.name))); showToast('Dashboard imported.'); } catch { showToast('Dashboard JSON is invalid.', 'danger'); } finally { event.target.value = ''; } };
    const exportJson = () => {
        const blob = new Blob([JSON.stringify(layout, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'stox-dashboard-layout.json'; anchor.click(); URL.revokeObjectURL(url);
    };
    return (
        <div className="card mb-3" data-testid="dashboard-layout-toolbar">
            <div className="card-body py-2">
                <div className="d-flex flex-wrap align-items-center gap-2">
                    <strong className="small">Dashboard layout</strong>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setEditing((value) => !value)} aria-expanded={editing}>{editing ? 'Done' : 'Customize'}</button>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => update(factoryDashboardLayout())} disabled={layout.locked}>Reset to defaults</button>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={exportJson}>Export JSON</button>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => importRef.current?.click()}>Import JSON</button>
                    <input ref={importRef} type="file" accept="application/json,.json" className="d-none" onChange={importJson} />
                    <ExportDataButton dataset="dashboard-summary" label="Export dashboard data" fields={['field', 'value']} />
                    <ExportBasketPanel />
                    <button type="button" className="btn btn-sm btn-outline-primary" onClick={saveAsNamed} disabled={saving}>{saving ? 'Saving…' : 'Save as named dashboard'}</button>
                    {named.length > 0 && <><label className="visually-hidden" htmlFor="named-dashboard-select">Named dashboard</label><select id="named-dashboard-select" className="form-select form-select-sm w-auto" value={selectedNamed} onChange={(event) => loadNamed(event.target.value)}><option value="">Named dashboards</option>{named.map((item) => <option value={item.id} key={item.id}>{item.name}{item.is_default ? ' (default)' : ''}</option>)}</select>{selectedNamed && <><button type="button" className="btn btn-sm btn-outline-secondary" onClick={setDefault}>Set default</button><button type="button" className="btn btn-sm btn-outline-secondary" onClick={duplicateNamed}>Duplicate</button><button type="button" className="btn btn-sm btn-outline-danger" onClick={deleteNamed}>Delete named</button></>}</>}
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => update({ ...layout, locked: !layout.locked })}>{layout.locked ? 'Unlock layout' : 'Lock layout'}</button>
                </div>
                {editing && !layout.locked && <div className="row g-3 mt-1">
                    <div className="col-12"><label className="small" htmlFor="dashboard-variant">Editing variant</label><select id="dashboard-variant" className="form-select form-select-sm w-auto" value={variant} onChange={(event) => setVariant(event.target.value)}><option value="desktop">Desktop</option><option value="mobile">Mobile</option></select></div>
                    <fieldset className="col-12 col-lg-6"><legend className="fs-6">Summary fields</legend>{DASHBOARD_SUMMARY_FIELDS.map((field) => <div className="d-flex align-items-center gap-2 mb-1" key={field.id}><label className="small flex-grow-1"><input type="checkbox" className="form-check-input me-2" checked={layout[variant].summaryFields.find((entry) => entry.id === field.id)?.visible !== false} disabled={field.core} onChange={(event) => toggle('summaryFields', field.id, event.target.checked)} />{field.label}{field.core ? ' (required)' : ''}</label><button type="button" className="btn btn-sm btn-link" onClick={() => moveSummaryField(field.id, -1)} aria-label={`Move ${field.label} up`}>↑</button><button type="button" className="btn btn-sm btn-link" onClick={() => moveSummaryField(field.id, 1)} aria-label={`Move ${field.label} down`}>↓</button></div>)}</fieldset>
                    <fieldset className="col-12 col-lg-6"><legend className="fs-6">Cards</legend>{DASHBOARD_CARDS.map((card) => { const saved = layout[variant].cards.find((entry) => entry.id === card.id); return <div className="d-flex align-items-center gap-2 mb-1" key={card.id}><label className="small flex-grow-1"><input type="checkbox" className="form-check-input me-2" checked={saved?.visible !== false} onChange={(event) => toggle('cards', card.id, event.target.checked)} />{card.label}</label><select aria-label={`${card.label} size`} className="form-select form-select-sm w-auto" value={saved?.size || card.defaultSize} onChange={(event) => resizeCard(card.id, event.target.value)}>{card.sizes.map((size) => <option key={size} value={size}>{size}</option>)}</select><button type="button" className="btn btn-sm btn-link" onClick={() => moveCard(card.id, -1)} aria-label={`Move ${card.label} up`}>↑</button><button type="button" className="btn btn-sm btn-link" onClick={() => moveCard(card.id, 1)} aria-label={`Move ${card.label} down`}>↓</button></div>; })}</fieldset>
                </div>}
            </div>
        </div>
    );
}
