import React, { useCallback, useEffect, useRef, useState } from 'react';
import { History, NotebookPen } from 'lucide-react';
import usePageVisitHistory from '../../hooks/usePageVisitHistory';
import ContextualNotesDesktopPane from '../ContextualNotesDesktopPane';
import ContextualNotesSheet from '../ContextualNotesSheet';
import PageHistoryRail from './PageHistoryRail';
import PageHistorySheet from './PageHistorySheet';

function useConstrainedWidth() {
    const [isConstrained, setIsConstrained] = useState(() => (
        typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(max-width: 1199.98px)').matches
    ));

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return undefined;
        const media = window.matchMedia('(max-width: 1199.98px)');
        const update = () => setIsConstrained(media.matches);
        update();
        media.addEventListener?.('change', update);
        return () => media.removeEventListener?.('change', update);
    }, []);

    return isConstrained;
}

export default function RightUtilityRail({ user }) {
    const history = usePageVisitHistory(user);
    const isConstrained = useConstrainedWidth();
    const [activeUtility, setActiveUtility] = useState(null);
    const historyTriggerRef = useRef(null);
    const notesOriginRef = useRef(null);

    const closeHistory = useCallback(() => {
        setActiveUtility(null);
        historyTriggerRef.current?.focus();
    }, []);

    const closeNotes = useCallback(() => {
        setActiveUtility(null);
        notesOriginRef.current?.focus();
    }, []);

    const openHistory = useCallback((event) => {
        historyTriggerRef.current = event.currentTarget;
        window.dispatchEvent(new CustomEvent('lido-mobile-utility-open', { detail: { modal: isConstrained } }));
        setActiveUtility('history');
    }, [isConstrained]);

    const openNotes = useCallback((event) => {
        notesOriginRef.current = event.currentTarget;
        window.dispatchEvent(new CustomEvent('lido-mobile-utility-open', { detail: { modal: isConstrained } }));
        setActiveUtility((current) => (current === 'notes' ? null : 'notes'));
    }, [isConstrained]);

    useEffect(() => {
        const closeForGlobalSearch = (event) => {
            if (event.detail?.modal) setActiveUtility(null);
        };
        window.addEventListener('lido-global-search-open', closeForGlobalSearch);
        return () => window.removeEventListener('lido-global-search-open', closeForGlobalSearch);
    }, []);

    return (
        <>
            <PageHistoryRail {...history} />
            <button
                type="button"
                className="lido-notes-utility-action lido-notes-utility-action--desktop"
                aria-label="Open contextual notes"
                title="Contextual notes"
                aria-expanded={activeUtility === 'notes'}
                onClick={openNotes}
            >
                <NotebookPen size={18} />
            </button>
            <div className="lido-mobile-utility-actions" aria-label="Contextual utilities">
                <button
                    ref={historyTriggerRef}
                    type="button"
                    className="lido-history-mobile-action lido-history-mobile-action--grouped"
                    aria-label="Open page history"
                    title="Page history"
                    aria-haspopup="dialog"
                    aria-expanded={activeUtility === 'history'}
                    onClick={openHistory}
                >
                    <History size={18} />
                    <span>History</span>
                </button>
                <button
                    type="button"
                    className="lido-notes-utility-action lido-notes-utility-action--mobile"
                    aria-label="Open contextual notes"
                    title="Contextual notes"
                    aria-haspopup="dialog"
                    aria-expanded={activeUtility === 'notes'}
                    onClick={openNotes}
                >
                    <NotebookPen size={18} />
                    <span>Notes</span>
                </button>
            </div>
            {activeUtility === 'history' && <PageHistorySheet {...history} onClose={closeHistory} />}
            {activeUtility === 'notes' && !isConstrained && <ContextualNotesDesktopPane onClose={closeNotes} />}
            {activeUtility === 'notes' && isConstrained && <ContextualNotesSheet onClose={closeNotes} />}
        </>
    );
}
