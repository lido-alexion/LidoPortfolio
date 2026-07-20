import React, { useMemo } from 'react';
import ComboButton from './ComboButton';

/**
 * Add a stock to the active watchlist (primary) or pick another list from the menu.
 */
export default function AddToWatchlistComboButton({
    watchlists,
    activeWatchlistId,
    membershipIds = [],
    onAdd,
    saving = false,
    disabled = false,
}) {
    const activeWatchlist = watchlists.find((row) => row.id === activeWatchlistId) ?? null;
    const isOnActiveWatchlist = membershipIds.includes(activeWatchlistId);

    const menuItems = useMemo(() => {
        return watchlists
            .filter((row) => row.id !== activeWatchlistId)
            .map((row) => {
                const alreadyOn = membershipIds.includes(row.id);

                return {
                    key: row.id,
                    label: alreadyOn ? `${row.name} (already added)` : `Add to ${row.name}`,
                    disabled: alreadyOn,
                    onClick: alreadyOn ? undefined : () => onAdd(row.id),
                };
            });
    }, [watchlists, activeWatchlistId, membershipIds, onAdd]);

    if (isOnActiveWatchlist || !activeWatchlist) {
        return null;
    }

    return (
        <ComboButton
            label={saving ? 'Adding…' : `Add to ${activeWatchlist.name}`}
            variant="primary"
            onPrimaryClick={() => {
                if (!saving && !disabled) {
                    onAdd(activeWatchlistId);
                }
            }}
            menuItems={menuItems.filter((item) => !item.disabled).map((item) => ({
                ...item,
                onClick: () => {
                    if (!saving && !disabled) {
                        item.onClick?.();
                    }
                },
            }))}
        />
    );
}
