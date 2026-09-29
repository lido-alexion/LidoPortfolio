import React, { useEffect, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';
import ExportDataButton from './ExportDataButton';
import { DASHBOARD_CARDS, DASHBOARD_SUMMARY_FIELDS, factoryDashboardLayout, migrateDashboardLayout, readLocalDashboardLayout, writeLocalDashboardLayout } from '../utils/dashboardLayouts';

export default function DashboardLayoutToolbar({ userId, onLayoutChange }) {
    const [layout, setLayout] = useState(() => readLocalDashboardLayout(userId));
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [namedCount, setNamedCount] = useState(0);

    useEffect(() => {
        const next = readLocalDashboardLayout(userId);
        setLayout(next);
        onLayoutChange?.(next);
        api.get('/dashboard-layouts', { skipErrorToast: true }).then((res) => setNamedCount((res.data?.data || []).length)).catch(() => setNamedCount(0));
    }, [userId, onLayoutChange]);

    const update = (next) => {
        const migrated = migrateDashboardLayout(next);
        setLayout(migrated); writeLocalDashboardLayout(userId, migrated); onLayoutChange?.(migrated);
    };
    const toggle = (collection, id, visible) => {
        const next = structuredClone(layout);
        const item = next.desktop[collection].find((entry) => entry.id === id);
        if (!item) return;
        if (collection === 'cards' && !visible && next.desktop.cards.filter((entry) => entry.visible !== false).length <= 1) return;
        if (collection === 'summaryFields' && !visible && DASHBOARD_SUMMARY_FIELDS.find((entry) => entry.id === id)?.core) return;
        item.visible = visible; next.mobile[collection].find((entry) => entry.id === id).visible = visible; update(next);
    };
    const saveAsNamed = async () => {
        const name = window.prompt('Name this dashboard'); if (!name?.trim()) return;
        setSaving(true);
        try { await api.post('/dashboard-layouts', { name: name.trim(), definition: layout }); setNamedCount((count) => count + 1); showToast('Dashboard saved.'); }
        catch { showToast('Dashboard could not be saved.', 'danger'); } finally { setSaving(false); }
    };
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
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => update(factoryDashboardLayout())}>Reset to defaults</button>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={exportJson}>Export JSON</button>
                    <ExportDataButton dataset="dashboard-summary" label="Export dashboard data" fields={['field', 'value']} />
                    <button type="button" className="btn btn-sm btn-outline-primary" onClick={saveAsNamed} disabled={saving}>{saving ? 'Saving…' : 'Save as named dashboard'}</button>
                    {namedCount > 0 && <span className="small text-muted">{namedCount} named dashboard{namedCount === 1 ? '' : 's'}</span>}
                </div>
                {editing && !layout.locked && <div className="row g-3 mt-1">
                    <fieldset className="col-12 col-lg-6"><legend className="fs-6">Summary fields</legend>{DASHBOARD_SUMMARY_FIELDS.map((field) => <label className="d-block small" key={field.id}><input type="checkbox" className="form-check-input me-2" checked={layout.desktop.summaryFields.find((entry) => entry.id === field.id)?.visible !== false} disabled={field.core} onChange={(event) => toggle('summaryFields', field.id, event.target.checked)} />{field.label}{field.core ? ' (required)' : ''}</label>)}</fieldset>
                    <fieldset className="col-12 col-lg-6"><legend className="fs-6">Cards</legend>{DASHBOARD_CARDS.map((card) => <label className="d-block small" key={card.id}><input type="checkbox" className="form-check-input me-2" checked={layout.desktop.cards.find((entry) => entry.id === card.id)?.visible !== false} onChange={(event) => toggle('cards', card.id, event.target.checked)} />{card.label}</label>)}</fieldset>
                </div>}
            </div>
        </div>
    );
}
