import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    createNavAccessContext,
    ensureNavigationBootstrapped,
    favouritesStorageKey,
    MAX_NAV_FAVOURITES,
    navigationRegistry,
} from '../navigation';

function readIds(userId) {
    try {
        const raw = window.localStorage.getItem(favouritesStorageKey(userId));
        if (!raw) {
            return [];
        }
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return [];
        }
        return parsed.map(String).filter(Boolean);
    } catch {
        return [];
    }
}

function writeIds(userId, ids) {
    try {
        window.localStorage.setItem(favouritesStorageKey(userId), JSON.stringify(ids));
    } catch {
        /* ignore */
    }
}

function resolveEligible(ids, ctx) {
    ensureNavigationBootstrapped();
    const byId = new Map(
        navigationRegistry.getFavouriteEligiblePages(ctx).map((item) => [item.id, item]),
    );
    const seen = new Set();
    const items = [];
    for (const id of ids) {
        if (seen.has(id) || !byId.has(id)) {
            continue;
        }
        seen.add(id);
        items.push(byId.get(id));
        if (items.length >= MAX_NAV_FAVOURITES) {
            break;
        }
    }
    return items;
}

/**
 * Per-user favourite nav pages. Stored in localStorage keyed by user id.
 */
export default function useNavFavourites(userId, accessCtx) {
    const [ids, setIds] = useState(() => readIds(userId));
    const ctx = accessCtx || createNavAccessContext(null);

    useEffect(() => {
        setIds(readIds(userId));
    }, [userId]);

    useEffect(() => {
        writeIds(userId, ids);
    }, [userId, ids]);

    const favourites = useMemo(() => resolveEligible(ids, ctx), [ids, ctx]);

    const isFavourite = useCallback((navId) => ids.includes(navId), [ids]);

    const pin = useCallback((navId) => {
        ensureNavigationBootstrapped();
        const item = navigationRegistry.getItem(navId);
        if (!item?.favouriteEligible || !item.route) {
            return false;
        }
        setIds((prev) => {
            if (prev.includes(navId)) {
                return prev;
            }
            if (prev.length >= MAX_NAV_FAVOURITES) {
                return prev;
            }
            return [...prev, navId];
        });
        return true;
    }, []);

    const unpin = useCallback((navId) => {
        setIds((prev) => prev.filter((id) => id !== navId));
    }, []);

    const toggle = useCallback((navId) => {
        ensureNavigationBootstrapped();
        setIds((prev) => {
            if (prev.includes(navId)) {
                return prev.filter((id) => id !== navId);
            }
            const item = navigationRegistry.getItem(navId);
            if (!item?.favouriteEligible || !item.route) {
                return prev;
            }
            if (prev.length >= MAX_NAV_FAVOURITES) {
                return prev;
            }
            return [...prev, navId];
        });
    }, []);

    const reorder = useCallback((fromIndex, toIndex) => {
        setIds((prev) => {
            if (
                fromIndex === toIndex
                || fromIndex < 0
                || toIndex < 0
                || fromIndex >= prev.length
                || toIndex >= prev.length
            ) {
                return prev;
            }
            const next = prev.slice();
            const [moved] = next.splice(fromIndex, 1);
            next.splice(toIndex, 0, moved);
            return next;
        });
    }, []);

    const canPinMore = ids.length < MAX_NAV_FAVOURITES;

    return {
        favourites,
        ids,
        isFavourite,
        pin,
        unpin,
        toggle,
        reorder,
        canPinMore,
        max: MAX_NAV_FAVOURITES,
    };
}
