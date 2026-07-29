import React, { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { APP_DOCUMENTATION } from '../data/appDocumentation';
import DocCallout from '../components/docs/DocCallout';
import DocComparisonTable from '../components/docs/DocComparisonTable';
import DocConceptBox from '../components/docs/DocConceptBox';
import DocDecisionCards from '../components/docs/DocDecisionCards';
import DocMermaid from '../components/docs/DocMermaid';
import DocRichText from '../components/docs/DocRichText';
import DocSection, { DocItemCards } from '../components/docs/DocSection';
import DocWorkflow from '../components/docs/DocWorkflow';
import { getDocPresentation } from '../components/docs/docPresentation';
import {
    findDocumentationByKeyword,
    getDocumentationByKeyword,
    searchDocumentationIndex,
} from '../utils/documentationLinks';

export default function DocumentationPage() {
    const { isAuthenticated } = useAuth();
    const [searchParams, setSearchParams] = useSearchParams();
    const qParam = searchParams.get('q') || '';
    const [filter, setFilter] = useState('');

    const activeDoc = useMemo(() => findDocumentationByKeyword(qParam), [qParam]);
    const presentation = useMemo(
        () => getDocPresentation(activeDoc?.keyword || ''),
        [activeDoc?.keyword],
    );
    const indexItems = useMemo(() => searchDocumentationIndex(filter), [filter]);

    useEffect(() => {
        if (!qParam && activeDoc?.keyword) {
            setSearchParams({ q: activeDoc.keyword }, { replace: true });
        }
    }, [qParam, activeDoc, setSearchParams]);

    useEffect(() => {
        if (activeDoc?.id) {
            const el = document.getElementById(`doc-article-${activeDoc.id}`);
            el?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
    }, [activeDoc?.id]);

    const selectTopic = (keyword) => {
        setSearchParams({ q: keyword });
    };

    const relatedDocs = (activeDoc?.related || [])
        .map((keyword) => getDocumentationByKeyword(keyword))
        .filter(Boolean);

    return (
        <div className="container-fluid py-3 lido-docs-page">
            <div className="mb-3 d-flex flex-wrap align-items-start justify-content-between gap-2">
                <div>
                    <h1 className="h3 mb-1">Documentation</h1>
                    <p className="text-muted small mb-0">
                        Context-aware help for Lido Alexion screens — overview, controls, and related concepts.
                        These pages are public; you do not need to sign in to read them.
                    </p>
                </div>
                {!isAuthenticated ? (
                    <Link to="/" className="btn btn-sm btn-outline-info flex-shrink-0">
                        Sign in
                    </Link>
                ) : null}
            </div>

            <div className="row g-3">
                <aside className="col-lg-4 col-xl-3">
                    <div className="lido-docs-index border rounded-3 p-3">
                        <label htmlFor="lido-docs-filter" className="form-label small fw-semibold mb-1">
                            Search topics
                        </label>
                        <input
                            id="lido-docs-filter"
                            type="search"
                            className="form-control form-control-sm mb-3"
                            placeholder="e.g. screener, cash, strategy…"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            autoComplete="off"
                        />
                        <div className="lido-docs-index-list" role="list">
                            {indexItems.length === 0 && (
                                <p className="small text-muted mb-0">No topics match that search.</p>
                            )}
                            {indexItems.map((doc) => {
                                const isActive = doc.keyword === activeDoc?.keyword;
                                const icon = getDocPresentation(doc.keyword).icon || 'bi-file-text';
                                return (
                                    <button
                                        key={doc.id}
                                        type="button"
                                        role="listitem"
                                        className={`lido-docs-index-item${isActive ? ' is-active' : ''}`}
                                        onClick={() => selectTopic(doc.keyword)}
                                        aria-current={isActive ? 'true' : undefined}
                                    >
                                        <span className="lido-docs-index-title">
                                            <i className={`bi ${icon} me-1 opacity-75`} aria-hidden="true" />
                                            {doc.title}
                                        </span>
                                        <span className="lido-docs-index-meta text-muted">
                                            {doc.routeLabel}
                                            {' · '}
                                            <code>{doc.keyword}</code>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <p className="small text-muted mt-3 mb-0">
                            {APP_DOCUMENTATION.length} topics indexed
                        </p>
                    </div>
                </aside>

                <div className="col-lg-8 col-xl-9">
                    {activeDoc ? (
                        <article
                            id={`doc-article-${activeDoc.id}`}
                            className="lido-docs-article border rounded-3 p-3 p-md-4"
                        >
                            <header className="mb-3 pb-3 border-bottom">
                                <div className="d-flex flex-wrap align-items-baseline gap-2 mb-1">
                                    {presentation.icon ? (
                                        <i
                                            className={`bi ${presentation.icon} lido-docs-topic-icon`}
                                            aria-hidden="true"
                                        />
                                    ) : null}
                                    <h2 className="h4 mb-0">{activeDoc.title}</h2>
                                    <code className="small">{activeDoc.keyword}</code>
                                </div>
                                <p className="mb-2">{activeDoc.summary}</p>
                                <p className="small text-muted mb-0">
                                    Source route:{' '}
                                    {activeDoc.routeLabel.includes(':') ? (
                                        <code>{activeDoc.routeLabel}</code>
                                    ) : (
                                        <Link to={activeDoc.routeLabel}>{activeDoc.routeLabel}</Link>
                                    )}
                                    {qParam && normalizeDisplay(qParam) !== activeDoc.keyword && (
                                        <>
                                            {' · '}matched from <code>{qParam}</code>
                                        </>
                                    )}
                                </p>
                            </header>

                            {presentation.purpose ? (
                                <DocSection
                                    title="Purpose"
                                    subtitle="Why this page exists"
                                    icon="bi-bullseye"
                                    id="doc-purpose"
                                >
                                    <DocCallout variant="info" title="Purpose">
                                        {presentation.purpose}
                                    </DocCallout>
                                </DocSection>
                            ) : null}

                            {presentation.workflow?.length ? (
                                <DocSection
                                    title="Workflow"
                                    subtitle="Where this page fits"
                                    icon="bi-signpost-2"
                                    id="doc-workflow"
                                >
                                    <DocWorkflow steps={presentation.workflow} />
                                </DocSection>
                            ) : null}

                            {(presentation.callouts || []).map((callout) => (
                                <DocCallout
                                    key={`${callout.title || ''}-${callout.body.slice(0, 40)}`}
                                    variant={callout.variant}
                                    title={callout.title}
                                >
                                    {callout.body}
                                </DocCallout>
                            ))}

                            <DocSection
                                title="Overview"
                                subtitle="What this page is and how it works"
                                icon="bi-book"
                                id="doc-overview"
                            >
                                <DocRichText text={activeDoc.overview} />
                            </DocSection>

                            {(presentation.comparisons || []).map((table) => (
                                <DocComparisonTable
                                    key={table.caption || table.headers.join('-')}
                                    caption={table.caption}
                                    headers={table.headers}
                                    rows={table.rows}
                                />
                            ))}

                            {(presentation.conceptBoxes || []).map((box) => (
                                <DocConceptBox
                                    key={box.title}
                                    title={box.title}
                                    icon={box.icon}
                                    rows={box.rows}
                                />
                            ))}

                            {presentation.decisions?.length ? (
                                <DocSection
                                    title="Decision points"
                                    subtitle="When action is allowed, cautious, or blocked"
                                    icon="bi-traffic-light"
                                    id="doc-decisions"
                                >
                                    <DocDecisionCards items={presentation.decisions} />
                                </DocSection>
                            ) : null}

                            <DocSection
                                title="Controls"
                                subtitle="What each control does"
                                icon="bi-toggles"
                                id="doc-controls"
                            >
                                <DocItemCards items={activeDoc.controls} />
                            </DocSection>

                            <DocSection
                                title="Concepts"
                                subtitle="What the numbers and states mean"
                                icon="bi-lightbulb"
                                id="doc-concepts"
                            >
                                <DocItemCards items={activeDoc.concepts} />
                            </DocSection>

                            {presentation.behindTheScenes ? (
                                <DocSection
                                    title="Behind the scenes"
                                    subtitle="What happens internally"
                                    icon="bi-cpu"
                                    id="doc-behind"
                                >
                                    {presentation.behindTheScenes.summary ? (
                                        <p className="small mb-3">{presentation.behindTheScenes.summary}</p>
                                    ) : null}
                                    {presentation.behindTheScenes.mermaid ? (
                                        <DocMermaid chart={presentation.behindTheScenes.mermaid} />
                                    ) : null}
                                </DocSection>
                            ) : null}

                            {presentation.commonMistakes?.length ? (
                                <DocSection
                                    title="Common mistakes"
                                    subtitle="Short troubleshooting tips"
                                    icon="bi-question-circle"
                                    id="doc-mistakes"
                                >
                                    <div className="accordion lido-docs-accordion" id={`doc-mistakes-${activeDoc.id}`}>
                                        {presentation.commonMistakes.map((item, idx) => {
                                            const collapseId = `mistake-${activeDoc.id}-${idx}`;
                                            return (
                                                <div className="accordion-item" key={item.q}>
                                                    <h3 className="accordion-header">
                                                        <button
                                                            className="accordion-button collapsed py-2 small"
                                                            type="button"
                                                            data-bs-toggle="collapse"
                                                            data-bs-target={`#${collapseId}`}
                                                            aria-expanded="false"
                                                            aria-controls={collapseId}
                                                        >
                                                            {item.q}
                                                        </button>
                                                    </h3>
                                                    <div
                                                        id={collapseId}
                                                        className="accordion-collapse collapse"
                                                        data-bs-parent={`#doc-mistakes-${activeDoc.id}`}
                                                    >
                                                        <div className="accordion-body small">{item.a}</div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </DocSection>
                            ) : null}

                            {relatedDocs.length > 0 && (
                                <DocSection
                                    title="Related topics"
                                    subtitle="Where to go next"
                                    icon="bi-link-45deg"
                                    id="doc-related"
                                >
                                    <ul className="lido-docs-related-list list-unstyled mb-0">
                                        {relatedDocs.map((doc) => {
                                            const icon = getDocPresentation(doc.keyword).icon || 'bi-file-text';
                                            return (
                                                <li key={doc.id}>
                                                    <Link
                                                        to={`/documentation?q=${encodeURIComponent(doc.keyword)}`}
                                                        className="lido-docs-related-link"
                                                    >
                                                        <i className={`bi ${icon} me-1`} aria-hidden="true" />
                                                        {doc.title}
                                                    </Link>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </DocSection>
                            )}
                        </article>
                    ) : (
                        <div className="border rounded-3 p-4 text-muted">
                            No documentation topic found.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function normalizeDisplay(value) {
    return String(value || '').trim().toLowerCase();
}
