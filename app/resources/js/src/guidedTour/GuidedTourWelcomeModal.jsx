import React, { useRef } from 'react';
import { t } from '../i18n';
import useGuidedTourDialog from './useGuidedTourDialog';

export default function GuidedTourWelcomeModal({
    open,
    onBegin,
    onSkip,
    onDismissForever,
}) {
    const dialogRef = useRef(null);
    useGuidedTourDialog(open, dialogRef, onSkip);

    if (!open) {
        return null;
    }

    return (
        <>
            <div className="modal-backdrop show" />
            <div ref={dialogRef} className="modal show d-block lido-guided-tour-modal" role="dialog" aria-modal="true" aria-labelledby="guided-tour-welcome-title" aria-describedby="guided-tour-welcome-description" tabIndex={-1}>
            <div className="modal-dialog modal-dialog-centered">
                <div className="modal-content">
                    <div className="modal-header">
                        <h2 className="modal-title h5" id="guided-tour-welcome-title">{t('guidedTour.welcome.title')}</h2>
                    </div>
                    <div className="modal-body">
                        <p className="mb-2" id="guided-tour-welcome-description">
                            {t('guidedTour.welcome.body')}
                        </p>
                        <p className="text-muted small mb-0">
                            {t('guidedTour.welcome.relaunch')}
                        </p>
                    </div>
                    <div className="modal-footer d-flex flex-wrap gap-2 justify-content-between">
                        <button type="button" className="btn btn-link btn-sm text-muted" onClick={onDismissForever}>
                            {t('guidedTour.action.dismissForever')}
                        </button>
                        <div className="d-flex flex-wrap gap-2">
                            <button type="button" className="btn btn-outline-light" onClick={onSkip}>
                                {t('guidedTour.action.skip')}
                            </button>
                            <button type="button" className="btn btn-primary" onClick={onBegin}>
                                {t('guidedTour.action.begin')}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </>
    );
}
