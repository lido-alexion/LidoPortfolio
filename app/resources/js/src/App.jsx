import React, { useCallback, useEffect, useRef, useState } from 'react';
import { getToastAutoDismissMs } from './toast';
import { Route, Routes } from 'react-router-dom';
import AppBottomNav from './components/AppBottomNav';
import AppHeader from './components/AppHeader';
import AppTabs from './components/AppTabs';
import ErrorBoundary from './components/ErrorBoundary';
import BootErrorBanner, { clearBootError } from './components/BootErrorBanner';
import { useAuth } from './context/AuthContext';
import DashboardPage from './pages/DashboardPage';
import HoldingsPage from './pages/HoldingsPage';
import StockPricesPage from './pages/StockPricesPage';
import TransactionsPage from './pages/TransactionsPage';
import ClosedTransactionsPage from './pages/ClosedTransactionsPage';
import SettingsPage from './pages/SettingsPage';
import StockExplorerPage from './pages/StockExplorerPage';
import LoginPage from './pages/LoginPage';

function App() {
    const { user, isAuthenticated, loading } = useAuth();
    const [toast, setToast] = useState(null);
    const dismissTimerRef = useRef(null);

    const dismissToast = useCallback(() => {
        if (dismissTimerRef.current) {
            clearTimeout(dismissTimerRef.current);
            dismissTimerRef.current = null;
        }
        setToast(null);
    }, []);

    useEffect(() => {
        if (!loading) {
            clearBootError();
            window.dispatchEvent(new CustomEvent('lido-boot-cleared'));
            if (typeof window.__lidoBootSuccess === 'function') {
                window.__LIDO_APP_BOOTED = true;
                window.__lidoBootSuccess();
            }
        }
    }, [loading]);

    useEffect(() => {
        const handler = (event) => {
            if (dismissTimerRef.current) {
                clearTimeout(dismissTimerRef.current);
            }
            const detail = event.detail;
            setToast(detail);
            const duration = getToastAutoDismissMs(detail?.variant);
            dismissTimerRef.current = setTimeout(() => {
                setToast(null);
                dismissTimerRef.current = null;
            }, duration);
        };
        window.addEventListener('portfolio-toast', handler);
        return () => {
            window.removeEventListener('portfolio-toast', handler);
            if (dismissTimerRef.current) {
                clearTimeout(dismissTimerRef.current);
            }
        };
    }, []);

    if (loading) {
        return (
            <div className="contentPane">
                <BootErrorBanner />
                <AppHeader user={null} />
                <div className="container py-5 text-center">
                    <div className="spinner-border text-info" role="status" />
                    <p className="text-muted mt-3 mb-0">Restoring your session…</p>
                </div>
            </div>
        );
    }

    return (
        <ErrorBoundary>
            <div className="contentPane">
                <BootErrorBanner />
                <AppHeader user={isAuthenticated ? user : null} />

                {toast && (
                    <div
                        className={`alert alert-${toast.variant} position-fixed top-0 end-0 m-3 shadow d-flex align-items-start gap-2 lido-toast`}
                        style={{ zIndex: 2100, maxWidth: 'min(420px, calc(100vw - 1.5rem))' }}
                        role="alert"
                    >
                        <span className="flex-grow-1">{toast.message}</span>
                        <button
                            type="button"
                            className="btn-close flex-shrink-0"
                            aria-label="Close notification"
                            onClick={dismissToast}
                        />
                    </div>
                )}

                {!isAuthenticated ? (
                    <LoginPage />
                ) : (
                    <>
                        <div className="lido-main">
                            <AppTabs />
                            <Routes>
                                <Route path="/" element={<DashboardPage />} />
                                <Route path="/transactions" element={<TransactionsPage />} />
                                <Route path="/transactions/closed" element={<ClosedTransactionsPage />} />
                                <Route path="/holdings" element={<HoldingsPage />} />
                                <Route path="/holdings/:stockId/prices" element={<StockPricesPage />} />
                                <Route path="/explorer" element={<StockExplorerPage />} />
                                <Route path="/settings" element={<SettingsPage />} />
                            </Routes>
                        </div>
                        <AppBottomNav />
                    </>
                )}
            </div>
        </ErrorBoundary>
    );
}

export default App;
