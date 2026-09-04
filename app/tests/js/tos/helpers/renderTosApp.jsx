import React, { useEffect, useRef, useState } from 'react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { render } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { getToastAutoDismissMs } from '../../../../resources/js/src/toast.js';
import AppHeader from '../../../../resources/js/src/components/AppHeader.jsx';
import PageChrome from '../../../../resources/js/src/components/navigation/PageChrome.jsx';
import Sidebar from '../../../../resources/js/src/components/sidebar/Sidebar.jsx';
import AuthContext from '../../../../resources/js/src/context/AuthContext.jsx';
import { PortfolioProvider } from '../../../../resources/js/src/context/PortfolioContext.jsx';
import { SidebarProvider } from '../../../../resources/js/src/context/SidebarContext.jsx';
import { ThemeProvider } from '../../../../resources/js/src/context/ThemeContext.jsx';
import CandidatesPage from '../../../../resources/js/src/pages/CandidatesPage.jsx';
import RecommendationsPage from '../../../../resources/js/src/pages/RecommendationsPage.jsx';
import ReviewDashboardPage from '../../../../resources/js/src/pages/ReviewDashboardPage.jsx';
import ReviewReportDetailPage from '../../../../resources/js/src/pages/ReviewReportDetailPage.jsx';
import ReviewReportsListPage from '../../../../resources/js/src/pages/ReviewReportsListPage.jsx';
import { TEST_USER } from '../fixtures/tosApi.js';
import { createAppQueryClient } from '../../../../resources/js/src/queryClient.ts';

function TosRoutes() {
    return (
        <Routes>
            <Route path="/recommendations" element={<RecommendationsPage />} />
            <Route path="/candidates" element={<CandidatesPage />} />
            <Route path="/review/reports/:id" element={<ReviewReportDetailPage />} />
            <Route path="/review/reports" element={<ReviewReportsListPage />} />
            <Route path="/review" element={<ReviewDashboardPage />} />
        </Routes>
    );
}

function TosAuthenticatedShell({ user }) {
    const [toast, setToast] = useState(null);
    const dismissTimerRef = useRef(null);

    useEffect(() => {
        const handler = (event) => {
            if (dismissTimerRef.current) {
                clearTimeout(dismissTimerRef.current);
            }
            setToast(event.detail);
            const duration = getToastAutoDismissMs(event.detail?.variant);
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

    return (
        <SidebarProvider>
            <div className="lido-app-frame">
                <AppHeader user={user} showSidebarToggle />
                {toast && (
                    <div
                        className={`alert alert-${toast.variant}`}
                        role="alert"
                    >
                        {toast.message}
                    </div>
                )}
                <div className="lido-shell">
                    <Sidebar />
                    <div className="lido-main">
                        <PageChrome />
                        <TosRoutes />
                    </div>
                </div>
            </div>
        </SidebarProvider>
    );
}

function TosAuthStub({ children }) {
    const value = {
        user: TEST_USER,
        loading: false,
        isAuthenticated: true,
        sessionExpired: false,
        login: async () => TEST_USER,
        register: async () => {
            throw new Error('invite-only');
        },
        logout: async () => {},
        refreshUser: async () => TEST_USER,
        consumeRedirectPath: () => null,
    };

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function renderTosApp({ route = '/recommendations' } = {}) {
    const queryClient = createAppQueryClient();
    return render(
        <QueryClientProvider client={queryClient}>
          <MemoryRouter initialEntries={[route]}>
            <ThemeProvider>
                <TosAuthStub>
                    <PortfolioProvider>
                        <TosAuthenticatedShell user={TEST_USER} />
                    </PortfolioProvider>
                </TosAuthStub>
            </ThemeProvider>
          </MemoryRouter>
        </QueryClientProvider>,
    );
}

export function renderSessionRestore() {
    const loadingValue = {
        user: null,
        loading: true,
        isAuthenticated: false,
        sessionExpired: false,
        login: async () => null,
        register: async () => null,
        logout: async () => {},
        refreshUser: async () => null,
        consumeRedirectPath: () => null,
    };

    return render(
        <MemoryRouter initialEntries={['/recommendations']}>
            <AuthContext.Provider value={loadingValue}>
                <div className="contentPane">
                    <AppHeader user={null} />
                    <div className="container py-5 text-center">
                        <div className="spinner-border text-info" role="status" />
                        <p className="text-muted mt-3 mb-0">Restoring your session…</p>
                    </div>
                </div>
            </AuthContext.Provider>
        </MemoryRouter>,
    );
}
