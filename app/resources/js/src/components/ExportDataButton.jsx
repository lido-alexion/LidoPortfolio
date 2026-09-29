import React, { useState } from 'react';
import api from '../api';
import { showToast } from '../toast';

/** Reusable, scope-first export control for table/chart/data surfaces. */
export default function ExportDataButton({ dataset, label = 'Export data', fields = [] }) {
    const [busy, setBusy] = useState(false);

    const exportData = async () => {
        const format = window.prompt('Export format: csv or xlsx', 'csv')?.trim().toLowerCase();
        if (!['csv', 'xlsx'].includes(format)) return;
        const scope = window.prompt('Scope: current, full, or selected', 'full')?.trim().toLowerCase();
        if (!['current', 'full', 'selected'].includes(scope)) return;
        setBusy(true);
        try {
            const response = await api.post('/exports', { dataset, format, scope, fields: fields.length ? fields : undefined });
            const artifact = response.data?.data;
            if (artifact?.status === 'ready' && artifact.download_url) window.location.assign(artifact.download_url);
            else showToast('Export queued. You can check its status from the export response.');
        } catch (error) { showToast(error?.response?.data?.message || 'Export could not be created.', 'danger'); }
        finally { setBusy(false); }
    };

    return <button type="button" className="btn btn-sm btn-outline-secondary" onClick={exportData} disabled={busy}>{busy ? 'Preparing…' : label}</button>;
}
