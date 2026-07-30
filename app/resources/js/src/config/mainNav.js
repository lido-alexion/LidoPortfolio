/**
 * Primary sidebar navigation only.
 * Child pages (pending execution, registries, settings sub-pages, etc.) stay
 * out of the sidebar and are reached from their parent pages / Settings.
 */

export const PRIMARY_NAV_SECTIONS = [
    {
        id: 'portfolio',
        label: 'Portfolio',
        items: [
            { to: '/', label: 'Dashboard', end: true, match: (p) => p === '/' },
            { to: '/holdings', label: 'Portfolio', match: (p) => p.startsWith('/holdings') },
            { to: '/transactions', label: 'Transactions', match: (p) => p.startsWith('/transactions') },
            { to: '/cash', label: 'Cash', match: (p) => p === '/cash' || p.startsWith('/cash/') },
            { to: '/watchlist', label: 'Watchlist', match: (p) => p.startsWith('/watchlist') },
            {
                to: '/corporate-action',
                label: 'Corporate Actions',
                match: (p) => p === '/corporate-action' || p.startsWith('/corporate-action/'),
            },
        ],
    },
    {
        id: 'market',
        label: 'Market',
        items: [
            { to: '/explorer', label: 'Stock Explorer', match: (p) => p.startsWith('/explorer') },
            {
                to: '/candidates',
                label: 'Discovery',
                match: (p) => p.startsWith('/candidates') || p.startsWith('/evaluations'),
            },
            { to: '/indices', label: 'Indices', match: (p) => p.startsWith('/indices') },
            { to: '/market-depth', label: 'Market Depth', match: (p) => p.startsWith('/market-depth') },
            { to: '/calendar', label: 'Calendar', match: (p) => p.startsWith('/calendar') },
            { to: '/patterns', label: 'Patterns', match: (p) => p.startsWith('/patterns') },
        ],
    },
    {
        id: 'trading',
        label: 'Trading',
        items: [
            {
                to: '/recommendations',
                label: 'Recommendations',
                match: (p) => p.startsWith('/recommendations'),
            },
            { to: '/screeners', label: 'Screeners', match: (p) => p.startsWith('/screeners') },
            {
                to: '/strategy',
                label: 'Strategy',
                match: (p) => p === '/strategy' || p.startsWith('/strategy/'),
            },
            { to: '/review', label: 'Review', match: (p) => p === '/review' || p.startsWith('/review/') },
        ],
    },
    {
        id: 'knowledge',
        label: 'Knowledge',
        items: [
            {
                to: '/knowledge-board',
                label: 'Knowledge Board',
                match: (p) => p.startsWith('/knowledge-board'),
            },
        ],
    },
    {
        id: 'administration',
        label: 'Administration',
        items: [
            {
                to: '/settings/portfolio',
                label: 'Settings',
                match: (p) => p.startsWith('/settings'),
            },
        ],
    },
];

/** Flat list of primary nav items (active matching, docs, etc.). */
export const MAIN_TAB_ITEMS = PRIMARY_NAV_SECTIONS.flatMap((section) => section.items);

/** @deprecated Bottom nav removed; primary nav lives in the sidebar. */
export const FOOTER_NAV_ITEMS = [
    ...MAIN_TAB_ITEMS,
];
