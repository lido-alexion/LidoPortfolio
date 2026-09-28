import React from 'react';

export default function GuidedTourWelcomeModal({
    open,
    onBegin,
    onSkip,
    onDismissForever,
}) {
    if (!open) {
        return null;
    }

    return (
        <div className="modal show d-block" role="dialog" aria-modal="true" aria-labelledby="guided-tour-welcome-title">
            <div className="modal-dialog modal-dialog-centered">
                <div className="modal-content">
                    <div className="modal-header">
                        <h2 className="modal-title h5" id="guided-tour-welcome-title">Welcome to StoX</h2>
                    </div>
                    <div className="modal-body">
                        <p className="mb-2">
                            Take a short guided tour of the main areas — navigation, portfolio, screeners, and help.
                        </p>
                        <p className="text-muted small mb-0">
                            You can relaunch the tour later from your Profile page.
                        </p>
                    </div>
                    <div className="modal-footer d-flex flex-wrap gap-2 justify-content-between">
                        <button type="button" className="btn btn-link btn-sm text-muted" onClick={onDismissForever}>
                            Don&apos;t show again
                        </button>
                        <div className="d-flex flex-wrap gap-2">
                            <button type="button" className="btn btn-outline-secondary" onClick={onSkip}>
                                Skip for now
                            </button>
                            <button type="button" className="btn btn-primary" onClick={onBegin}>
                                Begin tour
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div className="modal-backdrop show" />
        </div>
    );
}
