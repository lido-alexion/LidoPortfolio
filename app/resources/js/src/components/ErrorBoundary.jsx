import React from 'react';
import logger from '../services/logger';

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
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="alert alert-danger m-3">
                    <h2 className="h5">Something went wrong</h2>
                    <p className="mb-0">{this.state.message}</p>
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-danger mt-3"
                        onClick={() => window.location.reload()}
                    >
                        Reload page
                    </button>
                </div>
            );
        }

        return this.props.children;
    }
}
