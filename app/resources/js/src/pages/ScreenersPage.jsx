import React from 'react';
import { useSearchParams } from 'react-router-dom';
import ScreenerGuideTab from '../components/screener/ScreenerGuideTab';
import ScreenerMyScreensTab from '../components/screener/ScreenerMyScreensTab';
import ScreenerSharedTab from '../components/screener/ScreenerSharedTab';

const TABS = [
    { id: 'my', label: 'My screens' },
    { id: 'shared', label: 'Shared screens' },
    { id: 'guide', label: 'Guide' },
];

function normalizeTab(tab) {
    return TABS.some((t) => t.id === tab) ? tab : 'my';
}

export default function ScreenersPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const activeTab = normalizeTab(searchParams.get('tab') || 'my');

    const setTab = (tabId) => {
        const next = new URLSearchParams(searchParams);
        if (tabId === 'my') {
            next.delete('tab');
        } else {
            next.set('tab', tabId);
        }
        setSearchParams(next, { replace: true });
    };

    return (
        <div className="container-fluid py-3 screeners-page">
            <div className="mb-3">
                <h1 className="h3 mb-0">Screener</h1>
                <p className="text-muted small mb-0">
                    OHLCV technical screens on holdings, a watchlist, or the equity universe.
                </p>
            </div>

            <ul className="nav nav-tabs mb-3" role="tablist">
                {TABS.map((tab) => (
                    <li className="nav-item" key={tab.id} role="presentation">
                        <button
                            type="button"
                            className={`nav-link${activeTab === tab.id ? ' active' : ''}`}
                            role="tab"
                            aria-selected={activeTab === tab.id}
                            onClick={() => setTab(tab.id)}
                        >
                            {tab.label}
                        </button>
                    </li>
                ))}
            </ul>

            {activeTab === 'my' && <ScreenerMyScreensTab />}
            {activeTab === 'shared' && <ScreenerSharedTab />}
            {activeTab === 'guide' && <ScreenerGuideTab />}
        </div>
    );
}
