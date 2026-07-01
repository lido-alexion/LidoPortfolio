import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import SegmentToggle from '../components/SegmentToggle';
import PatternGuideCard from '../components/PatternGuideCard';
import { CHART_PATTERNS } from '../data/chartPatterns';
import { CANDLESTICK_PATTERNS } from '../data/candlestickPatterns';
import { CHART_PATTERN_TERMS, OHLCV_CANDLE_TERMS } from '../data/ohlcvCandleTerms';
import {
    normalizePatternHash,
    patternGuideSectionForId,
} from '../utils/patternGuideLinks';

const SECTION_KEY = 'portfolio_pattern_guide_section';

const SECTION_OPTIONS = [
    { value: 'chart', label: 'Chart patterns' },
    { value: 'candle', label: 'Candlesticks' },
];

function loadSection() {
    try {
        return localStorage.getItem(SECTION_KEY) === 'candle' ? 'candle' : 'chart';
    } catch {
        return 'chart';
    }
}

function saveSection(section) {
    try {
        localStorage.setItem(SECTION_KEY, section);
    } catch {
        // ignore
    }
}

function TermsGlossary({ title, terms }) {
    return (
        <div className="card">
            <div className="card-header py-2">
                <div className="mb-0 small fw-semibold">{title}</div>
            </div>
            <div className="card-body py-2">
                <dl className="row small mb-0 g-2">
                    {terms.map((term) => (
                        <React.Fragment key={term.symbol}>
                            <dt className="col-sm-4 col-lg-3">
                                <code>{term.symbol}</code>
                            </dt>
                            <dd className="col-sm-8 col-lg-9 mb-0 text-muted">{term.meaning}</dd>
                        </React.Fragment>
                    ))}
                </dl>
            </div>
        </div>
    );
}

export default function PatternGuidePage() {
    const location = useLocation();
    const navigate = useNavigate();
    const hashId = normalizePatternHash(location.hash);

    const [section, setSection] = useState(() => {
        const fromHash = patternGuideSectionForId(hashId);
        return fromHash || loadSection();
    });
    const [query, setQuery] = useState('');
    const [openPatternId, setOpenPatternId] = useState(hashId || null);

    const applyPatternHash = useCallback((patternId) => {
        const id = normalizePatternHash(patternId);
        if (!id) {
            setOpenPatternId(null);
            return;
        }

        const patternSection = patternGuideSectionForId(id);
        if (patternSection) {
            setSection(patternSection);
            saveSection(patternSection);
        }
        setQuery('');
        setOpenPatternId(id);
    }, []);

    useEffect(() => {
        if (hashId) {
            applyPatternHash(hashId);
        } else {
            setOpenPatternId(null);
        }
    }, [hashId, applyPatternHash]);

    useEffect(() => {
        if (!openPatternId) {
            return;
        }
        const expectedSection = patternGuideSectionForId(openPatternId);
        if (expectedSection && expectedSection !== section) {
            return;
        }
        const element = document.getElementById(openPatternId);
        if (!element) {
            return;
        }
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [openPatternId, section]);

    const handleSectionChange = (value) => {
        setSection(value);
        saveSection(value);
        setOpenPatternId(null);
        navigate('/patterns', { replace: true });
    };

    const handleCardOpenChange = useCallback((patternId, nextOpen) => {
        if (nextOpen) {
            setOpenPatternId(patternId);
            navigate(`/patterns#${patternId}`, { replace: true });
            return;
        }
        setOpenPatternId(null);
        if (normalizePatternHash(location.hash) === patternId) {
            navigate('/patterns', { replace: true });
        }
    }, [location.hash, navigate]);

    const patterns = section === 'candle' ? CANDLESTICK_PATTERNS : CHART_PATTERNS;

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) {
            return patterns;
        }
        return patterns.filter((pattern) => (
            pattern.name.toLowerCase().includes(q)
            || pattern.category.toLowerCase().includes(q)
            || pattern.characteristics.some((line) => line.toLowerCase().includes(q))
            || pattern.meaning.toLowerCase().includes(q)
        ));
    }, [patterns, query]);

    const terms = section === 'candle' ? OHLCV_CANDLE_TERMS : CHART_PATTERN_TERMS;

    return (
        <div className="d-grid gap-3">
            <div className="card">
                <div className="card-body">
                    <h1 className="h5 mb-2">Chart &amp; candlestick patterns</h1>
                    <p className="text-muted small mb-3">
                        Reference guide for common technical patterns. Each entry includes a sketch,
                        characteristics, trading interpretation, and OHLCV math rules used by the scanners.
                        Use Scan my watchlist on the Watchlist tab or see holdings signals on the Dashboard.
                        Each pattern has a shareable link (URL ends with #pattern_id).
                        This page is educational only — not trading advice.
                    </p>
                    <div className="d-flex flex-wrap align-items-end gap-3">
                        <SegmentToggle
                            compact
                            label="Section"
                            value={section}
                            onChange={handleSectionChange}
                            options={SECTION_OPTIONS}
                            ariaLabel="Pattern guide section"
                        />
                        <div className="flex-grow-1" style={{ minWidth: '12rem', maxWidth: '20rem' }}>
                            <label className="form-label small text-muted mb-1" htmlFor="pattern-guide-search">
                                Filter
                            </label>
                            <input
                                id="pattern-guide-search"
                                type="search"
                                className="form-control form-control-sm"
                                placeholder="Search patterns…"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </div>
                    </div>
                </div>
            </div>

            <TermsGlossary
                title={section === 'candle' ? 'Candle OHLCV terms' : 'Multi-bar chart terms'}
                terms={terms}
            />

            {filtered.length === 0 ? (
                <div className="text-muted small">No patterns match your search.</div>
            ) : (
                <div className="d-grid gap-2">
                    {filtered.map((pattern) => (
                        <PatternGuideCard
                            key={pattern.id}
                            pattern={pattern}
                            variant={section === 'candle' ? 'candle' : 'chart'}
                            open={openPatternId === pattern.id}
                            onOpenChange={(nextOpen) => handleCardOpenChange(pattern.id, nextOpen)}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
