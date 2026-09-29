import React, { useEffect, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';

export default function ExportBasketPanel() {
    const [open, setOpen] = useState(false);
    const [catalog, setCatalog] = useState([]);
    const [items, setItems] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        Promise.all([api.get('/exports/catalog'), api.get('/exports/basket')]).then(([catalogResponse, basketResponse]) => {
            setCatalog(catalogResponse.data?.data || []); setItems(basketResponse.data?.data?.items || []);
        }).catch(() => showToast('Export basket could not be loaded.', 'danger'));
    }, [open]);

    const save = async (next) => { setItems(next); await api.put('/exports/basket', { items: next }); };
    const add = async (dataset) => { if (items.some((item) => item.dataset === dataset)) return; if (items.length >= 10) return showToast('Export basket limit reached.', 'danger'); await save([...items, { dataset, sheet_name: dataset }]); };
    const exportBasket = async () => { setBusy(true); try { const response = await api.post('/exports/basket/export'); if (response.data?.data?.download_url) window.location.assign(response.data.data.download_url); } catch (error) { showToast(error?.response?.data?.message || 'Basket export failed.', 'danger'); } finally { setBusy(false); } };

    return <>
        <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setOpen((value) => !value)} aria-expanded={open}>Export basket ({items.length})</button>
        {open && <div className="border rounded p-2 mt-2" data-testid="export-basket-panel">
            <div className="small text-muted mb-2">Basket stores configuration only and fetches fresh authorized data at export time.</div>
            {items.map((item, index) => <div className="d-flex gap-2 mb-1" key={`${item.dataset}-${index}`}><input className="form-control form-control-sm" value={item.sheet_name || item.dataset} aria-label={`Sheet name for ${item.dataset}`} onChange={(event) => setItems((current) => current.map((entry, itemIndex) => itemIndex === index ? { ...entry, sheet_name: event.target.value } : entry))} onBlur={() => save(items)} /><button type="button" className="btn btn-sm btn-outline-danger" onClick={() => save(items.filter((_, itemIndex) => itemIndex !== index))}>Remove</button></div>)}
            <div className="d-flex flex-wrap gap-2 mt-2">{catalog.filter((dataset) => !items.some((item) => item.dataset === dataset.id)).map((dataset) => <button type="button" className="btn btn-sm btn-outline-secondary" key={dataset.id} onClick={() => add(dataset.id)}>Add {dataset.label}</button>)}<button type="button" className="btn btn-sm btn-primary" disabled={!items.length || busy} onClick={exportBasket}>{busy ? 'Preparing…' : 'Export XLSX'}</button></div>
        </div>}
    </>;
}
