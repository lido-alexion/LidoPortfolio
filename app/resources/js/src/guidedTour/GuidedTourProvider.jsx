import React, {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import api from '../api';
import { useSidebar } from '../context/SidebarContext';
import GuidedTourWelcomeModal from './GuidedTourWelcomeModal';
import GuidedTourOverlay from './GuidedTourOverlay';
import {
    INVESTOR_TOUR_STEPS,
    nextTourStep,
    resolveTourStep,
    stepIndex,
} from './investorTourSteps';
import { logGuidedTourEvent } from './guidedTourTelemetry';
import { t } from '../i18n';

const GuidedTourContext = createContext(null);

function waitForTarget(selector, timeoutMs, intervalMs = 200) {
    return new Promise((resolve) => {
        const started = Date.now();
        const tick = () => {
            const el = selector ? document.querySelector(selector) : null;
            if (el) {
                resolve(el);
                return;
            }
            if (Date.now() - started >= timeoutMs) {
                resolve(null);
                return;
            }
            window.setTimeout(tick, intervalMs);
        };
        tick();
    });
}

export function GuidedTourProvider({ children, user }) {
    const navigate = useNavigate();
    const { pathname } = useLocation();
    const { openOverlay, closeOverlay, isLayoutMode } = useSidebar();

    const [serverState, setServerState] = useState(null);
    const [loading, setLoading] = useState(true);
    const [welcomeOpen, setWelcomeOpen] = useState(false);
    const [resumeChoiceOpen, setResumeChoiceOpen] = useState(false);
    const [tourActive, setTourActive] = useState(false);
    const [currentStepId, setCurrentStepId] = useState(null);
    const [targetRect, setTargetRect] = useState(null);
    const [skippedSteps, setSkippedSteps] = useState([]);
    const welcomeRecordedRef = useRef(false);
    const pendingLaunchRef = useRef(null);
    const focusReturnRef = useRef(null);

    const targetWaitMs = serverState?.target_wait_ms ?? 4000;

    const refreshState = useCallback(async () => {
        const { data } = await api.get('/guided-tour');
        setServerState(data.data);
        return data.data;
    }, []);

    useEffect(() => {
        if (!user || user.is_admin) {
            setLoading(false);
            return;
        }
        refreshState()
            .then((payload) => {
                if (payload?.show_welcome_prompt) {
                    setWelcomeOpen(true);
                }
            })
            .catch(() => {
                /* fail soft */
            })
            .finally(() => setLoading(false));
    }, [user, refreshState]);

    useEffect(() => {
        if (!welcomeOpen || welcomeRecordedRef.current) {
            return;
        }
        welcomeRecordedRef.current = true;
        api.put('/guided-tour', { action: 'record_welcome_shown' }).catch(() => {});
        logGuidedTourEvent('welcome_shown');
    }, [welcomeOpen]);

    const applyAction = useCallback(async (action, body = {}) => {
        const { data } = await api.put('/guided-tour', { action, ...body });
        setServerState(data.data);
        return data.data;
    }, []);

    const rememberFocus = useCallback(() => {
        const active = document.activeElement;
        if (active && active !== document.body && typeof active.focus === 'function') {
            focusReturnRef.current = {
                element: active,
                selector: active.getAttribute('data-tour')
                    ? `[data-tour="${active.getAttribute('data-tour')}"]`
                    : null,
            };
        }
    }, []);

    const restoreFocus = useCallback(() => {
        const target = focusReturnRef.current;
        focusReturnRef.current = null;
        const focus = (attempt = 0) => {
            const element = target?.element?.isConnected
                ? target.element
                : (target?.selector ? document.querySelector(target.selector) : null);
            if (element && typeof element.focus === 'function') {
                element.focus();
                return;
            }
            if (attempt < 3) {
                window.setTimeout(() => focus(attempt + 1), 50);
            }
        };
        focus();
    }, []);

    const computeTargetRect = useCallback((el) => {
        if (!el) {
            return null;
        }
        const rect = el.getBoundingClientRect();
        return {
            top: rect.top,
            left: rect.left,
            width: rect.width,
            height: rect.height,
        };
    }, []);

    const prepareStep = useCallback(async (step) => {
        if (!step) {
            return null;
        }
        if (step.openSidebar && !isLayoutMode) {
            openOverlay();
        }
        if (step.route && pathname !== step.route) {
            navigate(step.route);
        }
        const el = await waitForTarget(step.target, targetWaitMs);
        if (!el) {
            return null;
        }
        el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
        return computeTargetRect(el);
    }, [computeTargetRect, isLayoutMode, navigate, openOverlay, pathname, targetWaitMs]);

    const startTour = useCallback(async ({ restart = false, fromManual = false } = {}) => {
        const payload = await applyAction('begin', {
            step_id: restart ? INVESTOR_TOUR_STEPS[0]?.id : (serverState?.current_step_id || INVESTOR_TOUR_STEPS[0]?.id),
            restart,
        });
        const step = resolveTourStep(
            restart ? INVESTOR_TOUR_STEPS[0]?.id : payload.current_step_id,
        );
        setCurrentStepId(step?.id ?? null);
        setTourActive(true);
        setSkippedSteps([]);
        logGuidedTourEvent(restart ? 'tour_restarted' : (payload.tour_in_progress ? 'tour_resumed' : 'tour_started'), {
            manual: fromManual,
            step_id: step?.id,
        });
        const rect = await prepareStep(step);
        if (!rect && step) {
            setSkippedSteps((prev) => [...prev, step.id]);
            const next = nextTourStep(step.id);
            if (next) {
                setCurrentStepId(next.id);
                await applyAction('update_step', { step_id: next.id });
                const nextRect = await prepareStep(next);
                setTargetRect(nextRect);
            } else {
                setTargetRect(null);
            }
            logGuidedTourEvent('step_skipped', { step_id: step.id });
            return;
        }
        setTargetRect(rect);
    }, [applyAction, prepareStep, serverState?.current_step_id]);

    const closeTour = useCallback(async () => {
        const stepId = currentStepId;
        setTourActive(false);
        setTargetRect(null);
        closeOverlay();
        await applyAction('close', { step_id: stepId ?? undefined });
        restoreFocus();
        logGuidedTourEvent('tour_closed', { step_id: stepId });
    }, [applyAction, closeOverlay, currentStepId, restoreFocus]);

    const completeTour = useCallback(async () => {
        setTourActive(false);
        setTargetRect(null);
        closeOverlay();
        await applyAction('complete');
        restoreFocus();
        logGuidedTourEvent('tour_completed');
    }, [applyAction, closeOverlay, restoreFocus]);

    const goToStep = useCallback(async (stepId) => {
        const step = resolveTourStep(stepId);
        if (!step) {
            return;
        }
        setCurrentStepId(step.id);
        await applyAction('update_step', { step_id: step.id });
        const rect = await prepareStep(step);
        if (!rect) {
            setSkippedSteps((prev) => [...prev, step.id]);
            logGuidedTourEvent('step_skipped', { step_id: step.id });
            const next = nextTourStep(step.id);
            if (next) {
                // Missing targets fail soft for every step, not only the
                // first step at launch. The final valid step still requires
                // an explicit Finish action for completion semantics.
                await goToStep(next.id);
                return;
            }
        }
        setTargetRect(rect);
    }, [applyAction, prepareStep]);

    const onWelcomeBegin = useCallback(() => {
        rememberFocus();
        setWelcomeOpen(false);
        const resume = serverState?.current_step_id && !serverState?.completed;
        if (resume) {
            setResumeChoiceOpen(true);
            return;
        }
        startTour({ restart: false });
    }, [rememberFocus, serverState, startTour]);

    const onWelcomeSkip = useCallback(async () => {
        setWelcomeOpen(false);
        await applyAction('skip_prompt');
        logGuidedTourEvent('skip_prompt');
    }, [applyAction]);

    const onWelcomeDismissForever = useCallback(async () => {
        setWelcomeOpen(false);
        await applyAction('dismiss_forever');
        logGuidedTourEvent('dismiss_forever');
    }, [applyAction]);

    useEffect(() => {
        const onLaunch = (event) => {
            const restart = Boolean(event.detail?.restart);
            rememberFocus();
            pendingLaunchRef.current = { restart, fromManual: true };
            setResumeChoiceOpen(false);
            setWelcomeOpen(false);
            startTour({ restart, fromManual: true });
        };
        window.addEventListener('lido-guided-tour-launch', onLaunch);
        return () => window.removeEventListener('lido-guided-tour-launch', onLaunch);
    }, [rememberFocus, startTour]);

    useEffect(() => {
        if (!tourActive || !currentStepId) {
            return undefined;
        }
        const onResize = () => {
            const step = resolveTourStep(currentStepId);
            const el = step?.target ? document.querySelector(step.target) : null;
            setTargetRect(computeTargetRect(el));
        };
        window.addEventListener('resize', onResize);
        window.addEventListener('scroll', onResize, true);
        return () => {
            window.removeEventListener('resize', onResize);
            window.removeEventListener('scroll', onResize, true);
        };
    }, [tourActive, currentStepId, computeTargetRect]);

    const value = useMemo(() => ({
        eligible: Boolean(serverState?.eligible),
        canManualRelaunch: Boolean(serverState?.can_manual_relaunch),
        launchTour: (restart = false) => {
            window.dispatchEvent(new CustomEvent('lido-guided-tour-launch', { detail: { restart } }));
        },
        loading,
    }), [serverState, loading]);

    const currentStep = resolveTourStep(currentStepId);
    const currentIdx = stepIndex(currentStepId);

    return (
        <GuidedTourContext.Provider value={value}>
            {children}
            {!user?.is_admin && !loading && (
                <>
                    <GuidedTourWelcomeModal
                        open={welcomeOpen}
                        onBegin={onWelcomeBegin}
                        onSkip={onWelcomeSkip}
                        onDismissForever={onWelcomeDismissForever}
                    />
                    {resumeChoiceOpen && (
                        <>
                            <div className="modal-backdrop show" />
                            <div className="modal show d-block lido-guided-tour-modal" role="dialog" aria-modal="true" aria-labelledby="guided-tour-resume-title">
                            <div className="modal-dialog modal-dialog-centered">
                                <div className="modal-content">
                                    <div className="modal-header">
                                        <h2 className="modal-title h5" id="guided-tour-resume-title">{t('guidedTour.resume.title')}</h2>
                                    </div>
                                    <div className="modal-body">
                                        <p className="mb-0">{t('guidedTour.resume.body')}</p>
                                    </div>
                                    <div className="modal-footer d-flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            className="btn btn-outline-secondary"
                                            onClick={() => {
                                                setResumeChoiceOpen(false);
                                                startTour({ restart: true });
                                            }}
                                        >
                                            {t('guidedTour.action.restart')}
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-primary"
                                            onClick={() => {
                                                setResumeChoiceOpen(false);
                                                startTour({ restart: false });
                                            }}
                                        >
                                            {t('guidedTour.action.resume')}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            </div>
                        </>
                    )}
                    <GuidedTourOverlay
                        active={tourActive}
                        step={currentStep}
                        stepIndex={currentIdx}
                        stepCount={INVESTOR_TOUR_STEPS.length}
                        targetRect={targetRect}
                        skippedCount={skippedSteps.length}
                        onBack={() => {
                            const prev = INVESTOR_TOUR_STEPS[currentIdx - 1];
                            if (prev) {
                                goToStep(prev.id);
                            }
                        }}
                        onNext={() => {
                            const next = INVESTOR_TOUR_STEPS[currentIdx + 1];
                            if (next) {
                                goToStep(next.id);
                            } else {
                                completeTour();
                            }
                        }}
                        onFinish={completeTour}
                        onClose={closeTour}
                    />
                </>
            )}
        </GuidedTourContext.Provider>
    );
}

export function useGuidedTour() {
    return useContext(GuidedTourContext);
}
