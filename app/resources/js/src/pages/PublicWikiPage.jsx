import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../api';

export default function PublicWikiPage() {
    const { token } = useParams();
    const [page, setPage] = useState(null);
    const [missing, setMissing] = useState(false);

    useEffect(() => {
        api.get(`/wiki/shared/${token}`, { skipErrorToast: true })
            .then((response) => setPage(response.data?.data || null))
            .catch(() => setMissing(true));
    }, [token]);

    if (missing) return <main className="container py-5"><div className="alert alert-secondary">This shared Wiki Page is unavailable or its link has been revoked.</div></main>;
    if (!page) return <main className="container py-5 text-center"><div className="spinner-border" aria-label="Loading shared Wiki Page" /></main>;

    return <main className="container py-5" style={{ maxWidth: 960 }}>
        <div className="small text-muted mb-3">StoX shared Wiki Page</div>
        <article className="card"><div className="card-body p-4 p-md-5"><h1>{page.title}</h1><div className="lido-knowledge-markdown-preview" dangerouslySetInnerHTML={{ __html: page.rendered_html || '' }} /></div></article>
    </main>;
}
