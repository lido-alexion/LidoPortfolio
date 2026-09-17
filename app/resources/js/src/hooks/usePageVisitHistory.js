import { useCallback, useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import {
    addPageVisit,
    getPageVisitDescriptor,
    normalizeVisitPath,
    readPageVisitHistory,
    writePageVisitHistory,
} from '../utils/pageVisitHistory';

function getSessionStorage() {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

export default function usePageVisitHistory(user) {
    const { pathname } = useLocation();
    const userId = user?.id ?? null;
    const [visits, setVisits] = useState(() => readPageVisitHistory(getSessionStorage(), userId));
    const visitKey = normalizeVisitPath(pathname);

    useEffect(() => {
        setVisits(readPageVisitHistory(getSessionStorage(), userId));
    }, [userId]);

    const recordVisit = useCallback((nextPathname) => {
        const descriptor = getPageVisitDescriptor(nextPathname, user);
        if (!descriptor) return;
        setVisits((current) => {
            const next = addPageVisit(current, descriptor);
            if (next !== current) writePageVisitHistory(getSessionStorage(), userId, next);
            return next;
        });
    }, [user, userId]);

    useEffect(() => {
        recordVisit(pathname);
    }, [pathname, recordVisit]);

    return useMemo(() => ({
        visits,
        activeVisitKey: visitKey,
        recordVisit,
    }), [recordVisit, visitKey, visits]);
}
