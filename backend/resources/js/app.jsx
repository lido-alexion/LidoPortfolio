import './src/themeInit';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap/dist/js/bootstrap.bundle.min.js';
import './src/styles/lido-app.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './src/App';
import { AuthProvider } from './src/context/AuthContext';
import { ThemeProvider } from './src/context/ThemeContext';
import { getAppBase } from './src/appBase';

const routerBasename = getAppBase() || undefined;

createRoot(document.getElementById('app')).render(
    <React.StrictMode>
        <BrowserRouter basename={routerBasename}>
            <ThemeProvider>
                <AuthProvider>
                    <App />
                </AuthProvider>
            </ThemeProvider>
        </BrowserRouter>
    </React.StrictMode>,
);
