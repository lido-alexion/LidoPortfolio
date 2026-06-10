import React from 'react';
import logger from '../services/logger';

function persistRenderError(message, info) {
    const text = [
        'React render error',
        message,
        info?.componentStack || '',
        `URL: ${window.location.href}`,
        `UA: ${navigator.userAgent}`,
    ].filter(Boolean).join('\n');
    try {
        sessionStorage.setItem('lido_boot_error', text);
    } catch {
        // ignore
    }
    if (typeof window.__lidoBootFail === 'function') {
        window.__lidoBootFail('React error', text);
    }
}

export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, message: '' };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, message: error?.message || 'Unexpected error' };
    }

    componentDidCatch(error, info) {
        logger.error('React rendering failure', {
            category: 'UI',
            message: error?.message,
            componentStack: info?.componentStack,
        });
        persistRenderError(error?.message || 'Unexpected error', info);
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="contentPane p-3">
                    <div className="alert alert-danger">
                        <h2 className="h5">Something went wrong</h2>
                        <p className="mb-2">{this.state.message}</p>
                        <p className="small mb-2">
                            <a href="mobile-debug.html">Open mobile-debug.html</a>
                        </p>
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-danger"
                            onClick={() => window.location.reload()}
                        >
                            Reload page
                        </button>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
