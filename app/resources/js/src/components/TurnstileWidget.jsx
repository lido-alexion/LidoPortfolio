import React, { useEffect, useRef } from 'react';

const SCRIPT_ID = 'cloudflare-turnstile-script';
const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

function loadTurnstileScript() {
    return new Promise((resolve, reject) => {
        if (window.turnstile) {
            resolve(window.turnstile);
            return;
        }
        const existing = document.getElementById(SCRIPT_ID);
        if (existing) {
            existing.addEventListener('load', () => resolve(window.turnstile));
            existing.addEventListener('error', reject);
            return;
        }
        const script = document.createElement('script');
        script.id = SCRIPT_ID;
        script.src = SCRIPT_SRC;
        script.async = true;
        script.defer = true;
        script.onload = () => resolve(window.turnstile);
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

export default function TurnstileWidget({ siteKey, onToken, onExpire }) {
    const containerRef = useRef(null);
    const widgetIdRef = useRef(null);

    useEffect(() => {
        if (!siteKey || !containerRef.current) {
            return undefined;
        }

        let cancelled = false;

        loadTurnstileScript()
            .then((turnstile) => {
                if (cancelled || !containerRef.current) {
                    return;
                }
                widgetIdRef.current = turnstile.render(containerRef.current, {
                    sitekey: siteKey,
                    callback: (token) => onToken?.(token),
                    'expired-callback': () => onExpire?.(),
                    'error-callback': () => onExpire?.(),
                });
            })
            .catch(() => onExpire?.());

        return () => {
            cancelled = true;
            if (widgetIdRef.current != null && window.turnstile) {
                try {
                    window.turnstile.remove(widgetIdRef.current);
                } catch {
                    // ignore teardown races
                }
            }
        };
    }, [siteKey, onToken, onExpire]);

    if (!siteKey) {
        return null;
    }

    return <div ref={containerRef} className="mb-3" />;
}
