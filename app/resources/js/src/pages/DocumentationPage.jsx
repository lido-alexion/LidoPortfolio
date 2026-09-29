import React, { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { appUrl } from '../appBase';
import { findDocumentationByKeyword } from '../utils/documentationLinks';
import { JOURNEY_TOPICS } from '../data/journeyMetadata';
import api from '../api';

/**
 * Legacy /documentation?q=… entry — redirects to static HTML under /docs/.
 */
export default function DocumentationPage() {
    const [searchParams] = useSearchParams();
    const q = searchParams.get('q') || 'overview';
    const journeyId = searchParams.get('journey');
    const topic = JOURNEY_TOPICS.find((item) => item.id === journeyId);
    const [feedback, setFeedback] = useState(null);

    useEffect(() => {
        if (topic) {
            api.post('/help-feedback', { topic_id: topic.id, event: 'selected' }, { skipErrorToast: true }).catch(() => {});
            return undefined;
        }
        const doc = findDocumentationByKeyword(q);
        const keyword = (doc?.keyword || 'overview').trim() || 'overview';
        const target = appUrl(`/docs/${encodeURIComponent(keyword)}.html`);
        window.location.replace(target);
    }, [q, topic]);

    if (topic) {
        const recordFeedback = (event) => {
            setFeedback(event === 'helpful' ? 'Thanks for the feedback.' : 'Thanks — this topic is marked for review.');
            api.post('/help-feedback', { topic_id: topic.id, event }, { skipErrorToast: true }).catch(() => {});
        };
        return (
            <div className="container-fluid py-4" data-testid="journey-help-topic">
                <div className="card shadow-sm">
                    <div className="card-body">
                        <div className="text-muted small mb-1">{topic.id} · {topic.category}</div>
                        <h1 className="h4">{topic.title}</h1>
                        <ol>{topic.steps.map((step) => <li key={step}>{step}</li>)}</ol>
                        {topic.prerequisites.length > 0 && <p className="small mb-1"><strong>Prerequisites:</strong> {topic.prerequisites.join(' ')}</p>}
                        {topic.warnings.length > 0 && <p className="small text-warning-emphasis mb-3"><strong>Warning:</strong> {topic.warnings.join(' ')}</p>}
                        <div className="d-flex flex-wrap gap-2 align-items-center">
                            <a className="btn btn-primary btn-sm" href={topic.route}>Open {topic.category}</a>
                            <a className="btn btn-outline-secondary btn-sm" href={appUrl(topic.guide)}>View full guide</a>
                            <span className="small text-muted ms-2">Search match is informational; normal StoX authorization still applies.</span>
                        </div>
                        <div className="mt-3 d-flex align-items-center gap-2" aria-label="Help feedback">
                            <span className="small text-muted">Was this helpful?</span>
                            <button type="button" className="btn btn-sm btn-outline-success" onClick={() => recordFeedback('helpful')}>Helpful</button>
                            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => recordFeedback('not_helpful')}>Not helpful</button>
                            {feedback && <span className="small text-muted" role="status">{feedback}</span>}
                        </div>
                    </div>
                </div>
            </div>
        );
    }

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
