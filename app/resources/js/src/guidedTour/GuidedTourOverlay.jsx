import React, { useEffect, useMemo, useRef } from 'react';
import { t } from '../i18n';

function tooltipStyle(targetRect, placement) {
    if (!targetRect) {
        return {
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
            maxWidth: 'min(420px, calc(100vw - 2rem))',
        };
    }

    const margin = 12;
    const base = { maxWidth: 'min(420px, calc(100vw - 2rem))' };

    if (placement === 'right') {
        return {
            ...base,
            top: targetRect.top + targetRect.height / 2,
            left: targetRect.left + targetRect.width + margin,
            transform: 'translateY(-50%)',
        };
    }
    if (placement === 'left') {
        return {
            ...base,
            top: targetRect.top + targetRect.height / 2,
            left: targetRect.left - margin,
            transform: 'translate(-100%, -50%)',
        };
    }
    if (placement === 'bottom') {
        return {
            ...base,
            top: targetRect.top + targetRect.height + margin,
            left: targetRect.left + targetRect.width / 2,
            transform: 'translateX(-50%)',
        };
    }

    return {
        ...base,
        top: targetRect.top - margin,
        left: targetRect.left + targetRect.width / 2,
        transform: 'translate(-50%, -100%)',
    };
}

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
    const tooltipPosition = tooltipStyle(targetRect, step.placement || 'bottom');

    return (
        <div className="lido-guided-tour" aria-live="polite">
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
                tabIndex={-1}
            >
                <div className="card-body">
                    <p className="text-muted small mb-1">
                        {t('guidedTour.step.progress', { current: stepIndex + 1, total: stepCount })}
                    </p>
                    <h3 className="h6" id="lido-guided-tour-title">{t(step.titleKey)}</h3>
                    <p className="small mb-3">{t(step.bodyKey)}</p>
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
                                className="btn btn-outline-secondary btn-sm"
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
