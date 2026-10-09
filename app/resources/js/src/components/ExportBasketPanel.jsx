import React, { useEffect, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';

const SNAPSHOT_RANGES = ['90d', '180d', '365d', 'all'];
const SORT_DIRECTIONS = ['asc', 'desc'];

function itemValidation(item, dataset) {
    if (!dataset) return 'This dataset is no longer available. Remove it and add a supported dataset.';
    if (!Array.isArray(item.fields) || item.fields.length === 0) return 'Select at least one field.';
    if (item.fields.some((field) => !dataset.fields?.includes(field))) return 'One or more fields are unavailable. Review the selection.';
    if (!dataset.scopes?.includes(item.scope)) return 'This scope is not supported for this dataset.';
    if (item.scope === 'current') {
        if (!SNAPSHOT_RANGES.includes(item.filters?.range)) return 'Choose a supported snapshot range.';
        if (item.filters?.sort_by !== 'snapshot_date' || !SORT_DIRECTIONS.includes(item.filters?.sort_direction)) return 'Choose a supported snapshot sort direction.';
    }
    if (item.scope === 'selected' && (!Array.isArray(item.selected) || item.selected.length === 0)) return 'Add at least one stable row ID for selected scope.';
    return '';
}

function normalizedSelectedIds(value) {
    return [...new Set(value.split(/[\s,]+/).map((id) => id.trim()).filter(Boolean))];
}

export default function ExportBasketPanel() {
    const [open, setOpen] = useState(false);
    const [catalog, setCatalog] = useState([]);
    const [items, setItems] = useState([]);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});

    useEffect(() => {
        if (!open) return;
        Promise.all([api.get('/exports/catalog'), api.get('/exports/basket')]).then(([catalogResponse, basketResponse]) => {
            const nextCatalog = catalogResponse.data?.data || [];
            setCatalog(nextCatalog);
            setItems((basketResponse.data?.data?.items || []).map((item) => {
                const dataset = nextCatalog.find((entry) => entry.id === item.dataset);
                const scope = dataset?.scopes?.includes(item.scope) ? item.scope : (dataset?.scopes?.[0] || item.scope || 'full');
                return { ...item, scope, fields: Array.isArray(item.fields) ? item.fields : [...(dataset?.fields || [])], filters: { range: '90d', sort_by: 'snapshot_date', sort_direction: 'asc', ...(item.filters || {}) }, selected: Array.isArray(item.selected) ? item.selected : [] };
            }));
        }).catch(() => showToast('Export basket could not be loaded.', 'danger'));
    }, [open]);

    const save = async (next, index) => {
        const nextErrors = { ...errors };
        if (typeof index === 'number') {
            const item = next[index];
            const message = itemValidation(item, catalog.find((entry) => entry.id === item.dataset));
            if (message) {
                nextErrors[index] = message;
                setErrors(nextErrors);
                showToast(message, 'danger');
                return false;
            }
            delete nextErrors[index];
        }
        setErrors(nextErrors);
        try {
            await api.put('/exports/basket', { items: next });
            setItems(next);
            showToast('Export basket configuration saved.', 'success');
            return true;
        } catch (error) {
            showToast(error?.response?.data?.message || 'Export basket could not be saved.', 'danger');
            return false;
        }
    };
    const updateItem = (index, changes) => setItems((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...changes } : item));
    const add = async (datasetId) => {
        if (items.some((item) => item.dataset === datasetId)) return;
        if (items.length >= 10) return showToast('Export basket limit reached.', 'danger');
        const dataset = catalog.find((entry) => entry.id === datasetId);
        if (!dataset) return;
        const next = [...items, { dataset: datasetId, sheet_name: datasetId, fields: [...(dataset.fields || [])], scope: dataset.scopes?.[0] || 'full', selected: [], filters: { range: '90d', sort_by: 'snapshot_date', sort_direction: 'asc' } }];
        setItems(next);
        try { await api.put('/exports/basket', { items: next }); } catch (error) { showToast(error?.response?.data?.message || 'Export basket could not be saved.', 'danger'); }
    };
    const remove = async (index) => {
        const next = items.filter((_, itemIndex) => itemIndex !== index);
        setItems(next);
        setErrors((current) => Object.fromEntries(Object.entries(current).filter(([key]) => Number(key) !== index).map(([key, value]) => [Number(key) > index ? Number(key) - 1 : Number(key), value])));
        try { await api.put('/exports/basket', { items: next }); } catch (error) { showToast(error?.response?.data?.message || 'Export basket could not be saved.', 'danger'); }
    };
    const exportBasket = async () => {
        const validationErrors = {};
        items.forEach((item, index) => { const message = itemValidation(item, catalog.find((entry) => entry.id === item.dataset)); if (message) validationErrors[index] = message; });
        if (Object.keys(validationErrors).length) {
            setErrors(validationErrors);
            showToast('Review the highlighted export basket configuration before exporting.', 'danger');
            return;
        }
        setBusy(true);
        try {
            const saved = await api.put('/exports/basket', { items });
            setItems(saved.data?.data?.items || items);
            const response = await api.post('/exports/basket/export');
            if (response.data?.data?.download_url) window.location.assign(response.data.data.download_url);
        } catch (error) { showToast(error?.response?.data?.message || 'Basket export failed.', 'danger'); } finally { setBusy(false); }
    };

    return <>
        <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setOpen((value) => !value)} aria-expanded={open}>Export basket ({items.length})</button>
        {open && <div className="border rounded p-2 mt-2" data-testid="export-basket-panel">
            <div className="small text-muted mb-2">Basket stores configuration only and fetches fresh authorized data at export time.</div>
            {items.map((item, index) => {
                const dataset = catalog.find((entry) => entry.id === item.dataset);
                const fields = dataset?.fields || [];
                const scopes = dataset?.scopes || [];
                return <section className="border rounded p-2 mb-2" key={`${item.dataset}-${index}`} aria-label={`Configuration for ${dataset?.label || item.dataset}`}>
                    <div className="d-flex gap-2 mb-2"><input className="form-control form-control-sm" value={item.sheet_name || item.dataset} aria-label={`Sheet name for ${item.dataset}`} onChange={(event) => updateItem(index, { sheet_name: event.target.value })} /><button type="button" className="btn btn-sm btn-outline-danger" onClick={() => remove(index)}>Remove</button></div>
                    <fieldset className="mb-2"><legend className="small">Included fields</legend>{fields.map((field) => <label className="form-check form-check-inline" key={field}><input className="form-check-input" type="checkbox" checked={(item.fields || []).includes(field)} aria-label={`Include ${dataset?.field_metadata?.[field]?.label || field} in ${dataset?.label || item.dataset}`} onChange={(event) => updateItem(index, { fields: event.target.checked ? [...(item.fields || []), field] : (item.fields || []).filter((value) => value !== field) })} /> <span className="form-check-label">{dataset?.field_metadata?.[field]?.label || field}</span></label>)}</fieldset>
                    <label className="form-label small" htmlFor={`basket-scope-${index}`}>Scope</label>
                    <select id={`basket-scope-${index}`} className="form-select form-select-sm mb-2" value={item.scope || scopes[0] || ''} onChange={(event) => updateItem(index, { scope: event.target.value })}>{scopes.map((scope) => <option key={scope} value={scope}>{scope}</option>)}</select>
                    {dataset?.scopes?.includes('current') && item.scope === 'current' && <div className="d-flex gap-2 mb-2">
                        <label className="form-label small flex-fill">Snapshot range<select className="form-select form-select-sm" aria-label={`Snapshot range for ${item.dataset}`} value={item.filters?.range || '90d'} onChange={(event) => updateItem(index, { filters: { ...item.filters, range: event.target.value, sort_by: 'snapshot_date' } })}>{SNAPSHOT_RANGES.map((range) => <option key={range} value={range}>{range}</option>)}</select></label>
                        <label className="form-label small flex-fill">Sort direction<select className="form-select form-select-sm" aria-label={`Snapshot sort direction for ${item.dataset}`} value={item.filters?.sort_direction || 'asc'} onChange={(event) => updateItem(index, { filters: { ...item.filters, sort_by: 'snapshot_date', sort_direction: event.target.value } })}>{SORT_DIRECTIONS.map((direction) => <option key={direction} value={direction}>{direction === 'asc' ? 'Oldest first' : 'Newest first'}</option>)}</select></label>
                    </div>}
                    {dataset?.scopes?.includes('selected') && item.scope === 'selected' && <label className="form-label small w-100">Stable selected row IDs<textarea className="form-control form-control-sm" rows="2" aria-label={`Stable selected row IDs for ${item.dataset}`} value={(item.selected || []).join('\n')} onChange={(event) => updateItem(index, { selected: normalizedSelectedIds(event.target.value) })} placeholder="Enter row IDs, separated by commas or lines" /></label>}
                    {errors[index] && <div className="small text-danger" role="alert">{errors[index]}</div>}
                    <button type="button" className="btn btn-sm btn-outline-primary mt-1" onClick={() => save(items, index)}>Save item configuration</button>
                </section>;
            })}
            <div className="d-flex flex-wrap gap-2 mt-2">{catalog.filter((dataset) => !items.some((item) => item.dataset === dataset.id)).map((dataset) => <button type="button" className="btn btn-sm btn-outline-secondary" key={dataset.id} onClick={() => add(dataset.id)}>Add {dataset.label}</button>)}<button type="button" className="btn btn-sm btn-primary" disabled={!items.length || busy} onClick={exportBasket}>{busy ? 'Preparing…' : 'Export XLSX'}</button></div>
        </div>}
    </>;
}
