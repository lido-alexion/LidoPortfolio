import api from '../api';
import { showToast } from '../toast';
import { buildAdminOperationalAlertsToastMessage } from './adminOperationalAlertsMessage';

export { buildAdminOperationalAlertsToastMessage } from './adminOperationalAlertsMessage';

/**
 * Fetch operational alerts and show a warning toast when unacknowledged items exist.
 * Intended after a server-side dashboard reload (not local cache).
 */
export async function showAdminOperationalAlertsToastIfAny(client = api) {
    try {
        const { data } = await client.get('/operational-alerts');
        const count = data.data?.unacknowledged_count ?? 0;
        if (count > 0) {
            showToast(buildAdminOperationalAlertsToastMessage(count), 'warning');
        }
    } catch {
        // Non-blocking — dashboard already loaded.
    }
}
