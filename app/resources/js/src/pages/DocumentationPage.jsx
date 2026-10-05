import React, { useEffect, useState } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { appUrl } from '../appBase';
import { findDocumentationByKeyword } from '../utils/documentationLinks';
import { explainJourneyMatch, JOURNEY_TOPICS, searchJourneyTopics } from '../data/journeyMetadata';
import api from '../api';

/**
 * Legacy /documentation?q=… entry — redirects to static HTML under /docs/.
 */
export default function DocumentationPage() {
    const [searchParams] = useSearchParams();
    const location = useLocation();
    const q = searchParams.get('q') || 'overview';
    const journeyId = searchParams.get('journey');
    const journeyQuery = searchParams.get('q') || location.state?.helpQuery || '';
    const topic = JOURNEY_TOPICS.find((item) => item.id === journeyId);
    const alternatives = topic ? searchJourneyTopics(journeyQuery || topic.title, { limit: 4, exclude: [topic.id] }) : [];
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
                        {topic.steps.length ? <ol aria-label="Task steps">{topic.steps.map((step) => <li key={step}>{step}</li>)}</ol> : <p>The journey has no concise steps marked for inline display. Open the full guide for the authoritative instructions.</p>}
                        {topic.prerequisites.length > 0 && <p className="small mb-1"><strong>Prerequisites:</strong> {topic.prerequisites.join(' ')}</p>}
                        {topic.warnings.length > 0 && <p className="small text-warning-emphasis mb-3"><strong>Warning:</strong> {topic.warnings.join(' ')}</p>}
                        <div className="d-flex flex-wrap gap-2 align-items-center">
                            <a className="btn btn-primary btn-sm" href={appUrl(topic.route)}>Open {topic.category}</a>
                            <a className="btn btn-outline-secondary btn-sm" href={appUrl(topic.guide)}>View full guide</a>
                            <span className="small text-muted ms-2">Search match is informational; normal StoX authorization still applies.</span>
                        </div>
                        <p className="small text-muted mt-3 mb-1">{journeyQuery ? `Why this matched: ${explainJourneyMatch(topic, journeyQuery)}.` : 'Why this matched: this topic’s title and approved search metadata match the selected help topic.'}</p>
                        {alternatives.length > 0 && <nav aria-label="Alternative help topics" className="small mt-2"><strong>Other possible matches</strong><ul className="mb-0">{alternatives.map((alternative) => <li key={alternative.id}><Link to={`/documentation?journey=${encodeURIComponent(alternative.id)}`} state={journeyQuery ? { helpQuery: journeyQuery } : undefined}>{alternative.title}</Link></li>)}</ul></nav>}
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
