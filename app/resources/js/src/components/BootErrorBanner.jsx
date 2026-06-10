import React, { useEffect, useMemo, useState } from 'react';

const STORAGE_KEY = 'lido_boot_error';

function stripTimestamp(line) {
    return line.replace(/^\d{4}-\d{2}-\d{2}T[\d:.]+Z\s+/, '');
}

/** Ignore leftover diagnostic noise from older boot probes (not real failures). */
export function isActionableBootError(text) {
    const trimmed = text.trim();
    if (!trimmed) {
        return false;
    }

    const lines = trimmed.split('\n').map(stripTimestamp).filter(Boolean);
    if (lines.length === 0) {
        return false;
    }

    const failurePattern = /error|failed|rejection|did not|mismatch|unauthenticated/i;
    return lines.some((line) => failurePattern.test(line));
}

export function readBootError() {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY) || '';
        return isActionableBootError(raw) ? raw : '';
    } catch {
        return '';
    }
}

export function clearBootError() {
    try {
        sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        // ignore
    }
}

export default function BootErrorBanner() {
    const initial = useMemo(() => readBootError(), []);
    const [message, setMessage] = useState(initial);

    useEffect(() => {
        const clear = () => {
            clearBootError();
            setMessage('');
        };

        if (window.__LIDO_APP_BOOTED) {
            clear();
            return undefined;
        }

        window.addEventListener('lido-boot-cleared', clear);
        return () => window.removeEventListener('lido-boot-cleared', clear);
    }, []);

    if (!message.trim()) {
        return null;
    }

    const copyReport = async () => {
        const text = `${message}\n\nUA: ${navigator.userAgent}\nURL: ${window.location.href}`;
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
        } else {
            window.prompt('Copy this report:', text);
        }
    };

    return (
        <div className="alert alert-danger m-3 lido-boot-error-banner" role="alert">
            <h2 className="h6 mb-2">App load problem</h2>
            <pre className="small mb-2 lido-boot-error-banner__log">{message}</pre>
            <p className="small mb-2">
                <a href="mobile-debug.html">Open mobile-debug.html</a>
                {' '}for asset checks.
            </p>
            <div className="d-flex flex-wrap gap-2">
                <button type="button" className="btn btn-sm btn-outline-danger" onClick={copyReport}>
                    Copy report
                </button>
                <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    onClick={() => {
                        clearBootError();
                        setMessage('');
                    }}
                >
                    Dismiss
                </button>
            </div>
        </div>
    );
}
