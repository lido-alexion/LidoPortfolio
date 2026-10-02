import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import api from '../api';
import useGuidedTourDialog from '../guidedTour/useGuidedTourDialog';

function isEnabled() {
    try {
        return window.localStorage.getItem('devOptions') === 'true';
    } catch {
        return false;
    }
}

// TEMPORARY TEST/DEVELOPMENT INFRASTRUCTURE. UI discoverability only, not authorization.
// Additional temporary actions can live here; remove before final public hardening.
export default function DeveloperOptions() {
    const [enabled, setEnabled] = useState(isEnabled);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [success, setSuccess] = useState(false);
    const [error, setError] = useState('');
    const dialogRef = useRef(null);
    const close = useCallback(() => setOpen(false), []);
    useGuidedTourDialog(enabled && open, dialogRef, close);

    useEffect(() => {
        const sync = () => {
            const next = isEnabled();
            setEnabled(next);
            if (!next) setOpen(false);
        };
        window.addEventListener('storage', sync);
        window.addEventListener('focus', sync);
        return () => {
            window.removeEventListener('storage', sync);
            window.removeEventListener('focus', sync);
        };
    }, []);

    async function resetGuidedTour() {
        setLoading(true);
        setSuccess(false);
        setError('');
        try {
            await api.post('/developer-options/guided-tour/reset');
            setSuccess(true);
        } catch (failure) {
            setError(failure?.response?.data?.message || 'Could not reset guided tour. Please try again.');
        } finally {
            setLoading(false);
        }
    }

    if (!enabled || !isEnabled()) return null;

    return createPortal(
        <>
            <button type="button" className="lido-developer-options-hotspot"
                aria-label="Open developer options" onClick={() => setOpen(true)} />
            {open && <>
                <div className="modal-backdrop show lido-developer-options-backdrop" />
                <div ref={dialogRef} className="modal show d-block lido-developer-options-modal"
                    role="dialog" aria-modal="true" aria-labelledby="developer-options-title" tabIndex={-1}>
                    <div className="modal-dialog modal-dialog-centered">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h2 className="modal-title h5" id="developer-options-title">Developer options</h2>
                                <button type="button" className="btn-close" aria-label="Close developer options" onClick={close} />
                            </div>
                            <div className="modal-body">
                                <p className="text-muted small">Temporary testing tools for your account.</p>
                                <button type="button" className="btn btn-primary" disabled={loading} onClick={resetGuidedTour}>
                                    {loading ? 'Resetting…' : 'Reset guided tour'}
                                </button>
                                {success && <p className="text-success mt-3 mb-0" role="status">Guided tour reset. Reload the page to see the welcome prompt.</p>}
                                {error && <p className="text-danger mt-3 mb-0" role="alert">{error}</p>}
                            </div>
                        </div>
                    </div>
                </div>
            </>}
        </>, document.body,
    );
}
