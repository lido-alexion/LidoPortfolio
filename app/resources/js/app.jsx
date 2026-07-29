import './src/themeInit';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import 'bootstrap/dist/js/bootstrap.bundle.min.js';
import './src/styles/lido-app.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './src/App';
import { AuthProvider } from './src/context/AuthContext';
import { PortfolioProvider } from './src/context/PortfolioContext';
import { ThemeProvider } from './src/context/ThemeContext';
import { getAppBase } from './src/appBase';

const routerBasename = getAppBase() || undefined;

function showBootFailure(message, error) {
    const lines = [
        message,
        error?.stack || error?.message || String(error),
        `URL: ${window.location.href}`,
        `UA: ${navigator.userAgent}`,
    ].filter(Boolean);
    if (typeof window.__lidoBootFail === 'function') {
        window.__lidoBootFail('App failed to start', lines.join('\n'));
    } else if (typeof window.__lidoBootLog === 'function') {
        lines.forEach((line) => window.__lidoBootLog(line));
    }
    const root = document.getElementById('app');
    if (root) {
        root.innerHTML = `
            <div style="padding:1rem;font-family:system-ui,sans-serif;max-width:640px;margin:0 auto;color:#e5e7eb;background:#1a1a1a;min-height:100vh">
                <h1 style="font-size:1.1rem">App failed to start</h1>
                <pre style="white-space:pre-wrap;word-break:break-word;font-size:.75rem">${lines.join('\n')}</pre>
                <p style="font-size:.85rem"><a href="mobile-debug.html" style="color:#7ec8ff">mobile-debug.html</a></p>
            </div>`;
    }
}

try {
    const rootEl = document.getElementById('app');
    createRoot(rootEl).render(
        <React.StrictMode>
            <BrowserRouter basename={routerBasename}>
                <ThemeProvider>
                    <AuthProvider>
                        <PortfolioProvider>
                            <App />
                        </PortfolioProvider>
                    </AuthProvider>
                </ThemeProvider>
            </BrowserRouter>
        </React.StrictMode>,
    );
} catch (error) {
    showBootFailure('React mount failed', error);
}
