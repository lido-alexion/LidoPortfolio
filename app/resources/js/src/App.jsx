import React, { useCallback, useEffect, useRef, useState } from 'react';
import { getToastAutoDismissMs } from './toast';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import AppBottomNav from './components/AppBottomNav';
import AppHeader from './components/AppHeader';
import AppTabs from './components/AppTabs';
import ErrorBoundary from './components/ErrorBoundary';
import BootErrorBanner, { clearBootError } from './components/BootErrorBanner';
import { hideBootPanel } from './utils/bootPanel';
import { useAuth } from './context/AuthContext';
import DashboardPage from './pages/DashboardPage';
import HoldingsPage from './pages/HoldingsPage';
import StockPricesPage from './pages/StockPricesPage';
import TransactionsPage from './pages/TransactionsPage';
import CorporateActionPage from './pages/CorporateActionPage';
import ClosedTransactionsPage from './pages/ClosedTransactionsPage';
import SettingsPage from './pages/SettingsPage';
import ProfilePage from './pages/ProfilePage';
import SyncLogsPage from './pages/SyncLogsPage';
import UniversePriceSyncPage from './pages/UniversePriceSyncPage';
import GapFillFailuresPage from './pages/GapFillFailuresPage';
import IgnoredPriceGapsPage from './pages/IgnoredPriceGapsPage';
import UserManagementPage from './pages/UserManagementPage';
import AdminAlertsPage from './pages/AdminAlertsPage';
import AdminRoute from './components/AdminRoute';
import StockExplorerPage from './pages/StockExplorerPage';
import IndicesPage from './pages/IndicesPage';
import MarketDepthPage from './pages/MarketDepthPage';
import WatchlistPage from './pages/WatchlistPage';
import PatternGuidePage from './pages/PatternGuidePage';
import KnowledgeBoardPage from './pages/KnowledgeBoardPage';
import KnowledgeBoardTagsPage from './pages/KnowledgeBoardTagsPage';
import CalendarPage from './pages/CalendarPage';
import LoginPage from './pages/LoginPage';
import AcceptInvitePage from './pages/AcceptInvitePage';
import ResetPasswordPage from './pages/ResetPasswordPage';
import PortfoliosPage from './pages/PortfoliosPage';
import AlertPoliciesPage from './pages/AlertPoliciesPage';
import ScreenersPage from './pages/ScreenersPage';
import ScreenerEditorPage from './pages/ScreenerEditorPage';
import RecommendationsPage from './pages/RecommendationsPage';
import CandidatesPage from './pages/CandidatesPage';
import EvaluationsPage from './pages/EvaluationsPage';
import ReviewDashboardPage from './pages/ReviewDashboardPage';
import NotificationHistoryPage from './pages/NotificationHistoryPage';
import CashManagementPage from './pages/CashManagementPage';
import StrategyPage from './pages/StrategyPage';
import DocumentationPage from './pages/DocumentationPage';

const FOOTER_NAV_ENABLED = true;

function App() {
    const { user, isAuthenticated, loading } = useAuth();
    const { pathname } = useLocation();
    const [toast, setToast] = useState(null);
    const dismissTimerRef = useRef(null);
    const isDocumentationRoute = pathname === '/documentation' || pathname.startsWith('/documentation/');

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
            hideBootPanel();
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
                    <Routes>
                        <Route path="/invite/:token" element={<AcceptInvitePage />} />
                        <Route path="/reset-password/:token" element={<ResetPasswordPage />} />
                        <Route path="*" element={<LoginPage />} />
                    </Routes>
                ) : (
                    <>
                        <div className="lido-main">
                            {!isDocumentationRoute && <AppTabs />}
                            <Routes>
                                <Route path="/" element={<DashboardPage />} />
                                <Route path="/transactions" element={<TransactionsPage />} />
                                <Route path="/transactions/pending" element={<TransactionsPage />} />
                                <Route path="/cash" element={<CashManagementPage />} />
                                <Route path="/corporate-action" element={<CorporateActionPage />} />
                                <Route path="/transactions/closed" element={<ClosedTransactionsPage />} />
                                <Route path="/holdings" element={<HoldingsPage />} />
                                <Route path="/holdings/:stockId/prices" element={<StockPricesPage />} />
                                <Route path="/watchlist/:symbol?" element={<WatchlistPage />} />
                                <Route path="/explorer" element={<StockExplorerPage />} />
                                <Route path="/indices" element={<IndicesPage />} />
                                <Route path="/market-depth" element={<MarketDepthPage />} />
                                <Route path="/screeners" element={<ScreenersPage />} />
                                <Route path="/screeners/:id" element={<ScreenerEditorPage />} />
                                <Route path="/candidates" element={<CandidatesPage />} />
                                <Route path="/evaluations" element={<EvaluationsPage />} />
                                <Route path="/recommendations" element={<RecommendationsPage />} />
                                <Route path="/strategy" element={<StrategyPage />} />
                                <Route path="/review" element={<ReviewDashboardPage />} />
                                <Route path="/notification-history" element={<NotificationHistoryPage />} />
                                <Route path="/patterns" element={<PatternGuidePage />} />
                                <Route path="/knowledge-board" element={<KnowledgeBoardPage />} />
                                <Route path="/knowledge-board/tags" element={<KnowledgeBoardTagsPage />} />
                                <Route path="/calendar" element={<CalendarPage />} />
                                <Route path="/profile" element={<ProfilePage />} />
                                <Route path="/documentation" element={<DocumentationPage />} />
                                <Route path="/portfolios" element={<PortfoliosPage />} />
                                <Route path="/settings" element={<Navigate to="/settings/portfolio" replace />} />
                                <Route path="/settings/global" element={<SettingsPage />} />
                                <Route path="/settings/portfolio" element={<SettingsPage />} />
                                <Route path="/settings/account" element={<SettingsPage />} />
                                <Route path="/settings/alert-policies" element={<AlertPoliciesPage />} />
                                <Route path="/settings/sync-logs" element={(
                                    <AdminRoute>
                                        <SyncLogsPage />
                                    </AdminRoute>
                                )} />
                                <Route path="/settings/admin-alerts" element={(
                                    <AdminRoute>
                                        <AdminAlertsPage />
                                    </AdminRoute>
                                )} />
                                <Route path="/settings/universe-price-sync" element={(
                                    <AdminRoute>
                                        <UniversePriceSyncPage />
                                    </AdminRoute>
                                )} />
                                <Route path="/settings/universe-price-sync/gap-failures" element={(
                                    <AdminRoute>
                                        <GapFillFailuresPage />
                                    </AdminRoute>
                                )} />
                                <Route path="/settings/universe-price-sync/ignored-gaps" element={(
                                    <AdminRoute>
                                        <IgnoredPriceGapsPage />
                                    </AdminRoute>
                                )} />
                                <Route
                                    path="/settings/users"
                                    element={(
                                        <AdminRoute>
                                            <UserManagementPage />
                                        </AdminRoute>
                                    )}
                                />
                            </Routes>
                        </div>
                        {FOOTER_NAV_ENABLED && !isDocumentationRoute && <AppBottomNav />}
                    </>
                )}
            </div>
        </ErrorBoundary>
    );
}

export default App;
