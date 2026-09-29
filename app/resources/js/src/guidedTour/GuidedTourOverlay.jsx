import React, { useEffect, useMemo, useRef } from 'react';
import { t } from '../i18n';
import { guidedTourTooltipStyle } from './guidedTourPosition';

export default function GuidedTourOverlay({
    active,
    step,
    stepIndex,
    stepCount,
    targetRect,
    onBack,
    onNext,
    onFinish,
    onClose,
}) {
    const panelRef = useRef(null);

    useEffect(() => {
        if (!active) {
            return undefined;
        }
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
                return;
            }
            if (event.key === 'Tab' && panelRef.current) {
                const focusable = Array.from(panelRef.current.querySelectorAll(
                    'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
                ));
                if (focusable.length === 0) {
                    event.preventDefault();
                    return;
                }
                const currentIndex = focusable.indexOf(document.activeElement);
                const nextIndex = event.shiftKey
                    ? (currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1)
                    : (currentIndex < 0 || currentIndex === focusable.length - 1 ? 0 : currentIndex + 1);
                if (currentIndex < 0 || (event.shiftKey && currentIndex === 0) || (!event.shiftKey && currentIndex === focusable.length - 1)) {
                    event.preventDefault();
                    focusable[nextIndex].focus();
                }
            }
        };
        document.addEventListener('keydown', onKey);
        const prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = prevOverflow;
        };
    }, [active, onClose]);

    useEffect(() => {
        if (active && panelRef.current) {
            panelRef.current.focus();
        }
    }, [active, step?.id]);

    const holeStyle = useMemo(() => {
        if (!targetRect) {
            return null;
        }
        const pad = 6;
        return {
            top: targetRect.top - pad,
            left: targetRect.left - pad,
            width: targetRect.width + pad * 2,
            height: targetRect.height + pad * 2,
        };
    }, [targetRect]);

    if (!active || !step) {
        return null;
    }

    const isLast = stepIndex >= stepCount - 1;
    const tooltipPosition = guidedTourTooltipStyle(targetRect, step.placement || 'bottom');

    return (
        <div className="lido-guided-tour">
            <div className="visually-hidden" role="status" aria-live="polite" aria-atomic="true">
                {t('guidedTour.step.progress', { current: stepIndex + 1, total: stepCount })}. {t(step.titleKey)}. {t(step.bodyKey)}
            </div>
            <div className="lido-guided-tour-scrim" onClick={onClose} role="presentation" />
            {holeStyle && (
                <div className="lido-guided-tour-spotlight" style={holeStyle} aria-hidden="true" />
            )}
            <div
                ref={panelRef}
                className="lido-guided-tour-panel card shadow"
                style={tooltipPosition}
                role="dialog"
                aria-modal="true"
                aria-labelledby="lido-guided-tour-title"
                aria-describedby="lido-guided-tour-description"
                tabIndex={-1}
            >
                <div className="card-body">
                    <p className="text-muted small mb-1">
                        {t('guidedTour.step.progress', { current: stepIndex + 1, total: stepCount })}
                    </p>
                    <h3 className="h6" id="lido-guided-tour-title">{t(step.titleKey)}</h3>
                    <p className="small mb-3" id="lido-guided-tour-description">{t(step.bodyKey)}</p>
                    {!targetRect && (
                        <p className="small text-warning mb-3">{t('guidedTour.step.hiddenTarget')}</p>
                    )}
                    <div className="d-flex flex-wrap gap-2 justify-content-between">
                        <button type="button" className="btn btn-link btn-sm px-0" onClick={onClose}>
                            {t('guidedTour.action.close')}
                        </button>
                        <div className="d-flex gap-2">
                            <button
                                type="button"
                                className="btn btn-outline-light btn-sm"
                                onClick={onBack}
                                disabled={stepIndex <= 0}
                            >
                                {t('guidedTour.action.back')}
                            </button>
                            {isLast ? (
                                <button type="button" className="btn btn-primary btn-sm" onClick={onFinish}>
                                    {t('guidedTour.action.finish')}
                                </button>
                            ) : (
                                <button type="button" className="btn btn-primary btn-sm" onClick={onNext}>
                                    {t('guidedTour.action.next')}
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
