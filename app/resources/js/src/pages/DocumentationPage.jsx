import React, { useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { appUrl } from '../appBase';
import { findDocumentationByKeyword } from '../utils/documentationLinks';

/**
 * Legacy /documentation?q=… entry — redirects to static HTML under /docs/.
 */
export default function DocumentationPage() {
    const [searchParams] = useSearchParams();
    const q = searchParams.get('q') || 'overview';

    useEffect(() => {
        const doc = findDocumentationByKeyword(q);
        const keyword = (doc?.keyword || 'overview').trim() || 'overview';
        const target = appUrl(`/docs/${encodeURIComponent(keyword)}.html`);
        window.location.replace(target);
    }, [q]);

    return (
        <div className="container-fluid py-4">
            <p className="text-muted mb-2">Opening static documentation…</p>
            <p className="small mb-0">
                If you are not redirected, open{' '}
                <a href={appUrl('/docs/index.html')}>{appUrl('/docs/index.html')}</a>.
            </p>
        </div>
    );
}
