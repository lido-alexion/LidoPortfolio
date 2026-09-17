import React, { useCallback, useRef, useState } from 'react';
import { History } from 'lucide-react';
import usePageVisitHistory from '../../hooks/usePageVisitHistory';
import PageHistoryRail from './PageHistoryRail';
import PageHistorySheet from './PageHistorySheet';

export default function RightUtilityRail({ user }) {
    const history = usePageVisitHistory(user);
    const [mobileOpen, setMobileOpen] = useState(false);
    const triggerRef = useRef(null);
    const closeMobile = useCallback(() => {
        setMobileOpen(false);
        triggerRef.current?.focus();
    }, []);

    return (
        <>
            <PageHistoryRail {...history} />
            <button
                ref={triggerRef}
                type="button"
                className="lido-history-mobile-action"
                aria-label="Open page history"
                title="Page history"
                aria-haspopup="dialog"
                aria-expanded={mobileOpen}
                onClick={() => setMobileOpen(true)}
            >
                <History size={18} />
                <span>History</span>
            </button>
            {mobileOpen && <PageHistorySheet {...history} onClose={closeMobile} />}
        </>
    );
}
