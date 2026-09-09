import api from '../api';
import { showToast } from '../toast';

export async function downloadPortfolioCsv(dataset, params = {}) {
    try {
        const response = await api.get(`/portfolio/exports/${dataset}`, {
            params,
            responseType: 'blob',
            skipErrorToast: true,
        });
        const disposition = String(response.headers?.['content-disposition'] || '');
        const matched = disposition.match(/filename="?([^";]+)"?/i);
        const filename = matched?.[1] || `stox-${dataset}.csv`;
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
        return true;
    } catch {
        showToast('CSV export failed', 'danger');
        return false;
    }
}
