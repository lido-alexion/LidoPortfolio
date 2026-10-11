import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../api';

export default function PublicWikiPage() {
    const { token } = useParams();
    const [page, setPage] = useState(null);
    const [missing, setMissing] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [retryKey, setRetryKey] = useState(0);

    useEffect(() => {
        let cancelled = false;
        setPage(null); setMissing(false); setLoadError(false);
        api.get(`/wiki/shared/${token}`, { skipErrorToast: true })
            .then((response) => { if (!cancelled) { const next = response.data?.data || null; setPage(next); setMissing(!next); } })
            .catch((error) => { if (!cancelled) { if (error?.response?.status === 404) setMissing(true); else setLoadError(true); } });
        return () => { cancelled = true; };
    }, [token, retryKey]);

    if (loadError) return <main className="container py-5"><div className="alert alert-danger" role="alert"><p>Shared Wiki Page could not be loaded. Please retry.</p><button type="button" className="btn btn-outline-primary" onClick={() => setRetryKey(key => key + 1)}>Retry shared Wiki Page</button></div></main>;
    if (missing) return <main className="container py-5"><div className="alert alert-secondary">This shared Wiki Page is unavailable or its link has been revoked.</div></main>;
    if (!page) return <main className="container py-5 text-center"><div className="spinner-border" aria-label="Loading shared Wiki Page" /></main>;

    return <main className="container py-5" style={{ maxWidth: 960 }}>
        <div className="small text-muted mb-3">StoX shared Wiki Page</div>
        <article className="card"><div className="card-body p-4 p-md-5"><h1>{page.title}</h1><div className="lido-knowledge-markdown-preview" dangerouslySetInnerHTML={{ __html: page.rendered_html || '' }} /></div></article>
    </main>;
}
