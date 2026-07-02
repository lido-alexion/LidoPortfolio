import { useEffect, useRef } from 'react';
import api from '../api';
import { showToast } from '../toast';

/**
 * Shows a warning toast once per authenticated admin session when unacknowledged alerts exist.
 */
export default function AdminAlertsLoginNotifier({ user, loading }) {
    const checkedForUserId = useRef(null);

    useEffect(() => {
        if (loading || !user?.is_admin) {
            if (!user?.is_admin) {
                checkedForUserId.current = null;
            }
            return;
        }

        if (checkedForUserId.current === user.id) {
            return;
        }

        let cancelled = false;

        (async () => {
            try {
                const { data } = await api.get('/operational-alerts');
                if (cancelled) {
                    return;
                }

                const count = data.data?.unacknowledged_count ?? 0;
                checkedForUserId.current = user.id;

                if (count > 0) {
                    const label = count === 1 ? 'alert' : 'alerts';
                    showToast(
                        `You have ${count} operational ${label}. Open Settings → Admin alerts to review.`,
                        'warning',
                    );
                }
            } catch {
                if (!cancelled) {
                    checkedForUserId.current = user.id;
                }
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [loading, user?.id, user?.is_admin]);

    return null;
}
