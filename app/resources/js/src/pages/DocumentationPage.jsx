import React, { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { APP_DOCUMENTATION } from '../data/appDocumentation';
import {
    findDocumentationByKeyword,
    getDocumentationByKeyword,
    searchDocumentationIndex,
} from '../utils/documentationLinks';

function DocSection({ title, children }) {
    return (
        <section className="lido-docs-section mb-4">
            <h2 className="h5 mb-2">{title}</h2>
            {children}
        </section>
    );
}

function DocItemList({ items }) {
    if (!items?.length) {
        return <p className="small text-muted mb-0">None listed for this topic.</p>;
    }
    return (
        <ul className="lido-docs-item-list list-unstyled mb-0">
            {items.map((item) => (
                <li key={item.name} className="lido-docs-item mb-3">
                    <div className="fw-semibold">{item.name}</div>
                    <div className="small text-muted lido-docs-item-body">
                        <DocOverview text={item.description} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function DocOverview({ text }) {
    const raw = String(text || '').trim();
    if (!raw) {
        return <p className="mb-0 text-muted">No overview available.</p>;
    }

    const parts = [];
    const fenceRegex = /```[\w-]*\n([\s\S]*?)```/g;
    let lastIndex = 0;
    let match = fenceRegex.exec(raw);
    while (match) {
        const before = raw.slice(lastIndex, match.index).trim();
        if (before) {
            parts.push({ type: 'text', value: before });
        }
        parts.push({ type: 'pre', value: match[1].replace(/\n$/, '') });
        lastIndex = fenceRegex.lastIndex;
        match = fenceRegex.exec(raw);
    }
    const tail = raw.slice(lastIndex).trim();
    if (tail) {
        parts.push({ type: 'text', value: tail });
    }

    return (
        <>
            {parts.map((part, partIdx) => {
                if (part.type === 'pre') {
                    return (
                        <pre key={`pre-${partIdx}`} className="lido-docs-pre small mb-3">
                            {part.value}
                        </pre>
                    );
                }
                const paragraphs = part.value
                    .split(/\n{2,}/)
                    .map((line) => line.trim())
                    .filter(Boolean);
                return paragraphs.map((paragraph, idx) => (
                    <p
                        key={`p-${partIdx}-${idx}`}
                        className={`lido-docs-paragraph${partIdx === parts.length - 1 && idx === paragraphs.length - 1 ? ' mb-0' : ''}`}
                    >
                        {paragraph}
                    </p>
                ));
            })}
        </>
    );
}

export default function DocumentationPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const qParam = searchParams.get('q') || '';
    const [filter, setFilter] = useState('');

    const activeDoc = useMemo(() => findDocumentationByKeyword(qParam), [qParam]);
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
            <div className="mb-3">
                <h1 className="h3 mb-1">Documentation</h1>
                <p className="text-muted small mb-0">
                    Context-aware help for Lido Alexion screens — overview, controls, and related concepts.
                </p>
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
                                return (
                                    <button
                                        key={doc.id}
                                        type="button"
                                        role="listitem"
                                        className={`lido-docs-index-item${isActive ? ' is-active' : ''}`}
                                        onClick={() => selectTopic(doc.keyword)}
                                        aria-current={isActive ? 'true' : undefined}
                                    >
                                        <span className="lido-docs-index-title">{doc.title}</span>
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

                            <DocSection title="About this page">
                                <DocOverview text={activeDoc.overview} />
                            </DocSection>

                            <DocSection title="Controls">
                                <DocItemList items={activeDoc.controls} />
                            </DocSection>

                            <DocSection title="Concepts">
                                <DocItemList items={activeDoc.concepts} />
                            </DocSection>

                            {relatedDocs.length > 0 && (
                                <DocSection title="Related topics">
                                    <ul className="lido-docs-related-list list-unstyled mb-0">
                                        {relatedDocs.map((doc) => (
                                            <li key={doc.id}>
                                                <Link
                                                    to={`/documentation?q=${encodeURIComponent(doc.keyword)}`}
                                                    className="lido-docs-related-link"
                                                >
                                                    {doc.title}
                                                </Link>
                                            </li>
                                        ))}
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
