import api from '../api';
import { showToast } from '../toast';

export async function downloadTaxCsv(dataset, params) {
    try {
        const response = await api.get(`/tax/exports/${dataset}`, {
            params,
            responseType: 'blob',
            skipErrorToast: true,
        });
        const disposition = String(response.headers?.['content-disposition'] || '');
        const matched = disposition.match(/filename="?([^";]+)"?/i);
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = matched?.[1] || `stox-tax-${dataset}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch {
        showToast('Tax CSV export failed', 'danger');
    }
}
