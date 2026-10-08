import React, { useState } from 'react';
import api from '../api';
import { showToast } from '../toast';

/** Reusable, scope-first export control for table/chart/data surfaces. */
export default function ExportDataButton({ dataset, label = 'Export data', fields = [], scopes = ['full'] }) {
    const [busy, setBusy] = useState(false);

    const exportData = async () => {
        const format = window.prompt('Export format: csv or xlsx', 'csv')?.trim().toLowerCase();
        if (!['csv', 'xlsx'].includes(format)) return;
        const scope = window.prompt(`Choose scope: ${scopes.join(', ')}`, scopes[0])?.trim().toLowerCase();
        if (!scopes.includes(scope)) return;
        const available = fields.length ? fields : [];
        const chosen = available.length
            ? window.prompt(`Fields to include (comma separated): ${available.join(', ')}`, available.join(', '))
            : null;
        if (available.length && chosen === null) return;
        const selectedFields = available.length
            ? chosen.split(',').map((field) => field.trim()).filter(Boolean)
            : undefined;
        if (selectedFields && (!selectedFields.length || selectedFields.some((field) => !available.includes(field)))) {
            showToast('Choose one or more fields from the available list.', 'danger');
            return;
        }
        let selected;
        if (scope === 'selected') {
            const identities = window.prompt('Selected row IDs (comma separated)');
            if (identities === null) return;
            selected = identities.split(',').map((value) => value.trim()).filter(Boolean);
            if (!selected.length) {
                showToast('Enter one or more stable row IDs.', 'danger');
                return;
            }
        }
        setBusy(true);
        try {
            const response = await api.post('/exports', { dataset, format, scope, fields: selectedFields, selected });
            const artifact = response.data?.data;
            if (artifact?.status === 'ready' && artifact.download_url) window.location.assign(artifact.download_url);
            else showToast('Export queued. You can check its status from the export response.');
        } catch (error) { showToast(error?.response?.data?.message || 'Export could not be created.', 'danger'); }
        finally { setBusy(false); }
    };

    return <button type="button" className="btn btn-sm btn-outline-secondary" onClick={exportData} disabled={busy}>{busy ? 'Preparing…' : label}</button>;
}
