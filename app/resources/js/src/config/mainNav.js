export const MAIN_TAB_ITEMS = [
    { to: '/', label: 'Dashboard', end: true, match: (p) => p === '/' },
    { to: '/transactions', label: 'Transactions', match: (p) => p.startsWith('/transactions') },
    { to: '/cash', label: 'Cash', match: (p) => p === '/cash' || p.startsWith('/cash/') },
    { to: '/holdings', label: 'Portfolio', match: (p) => p.startsWith('/holdings') },
    { to: '/watchlist', label: 'Watchlist', match: (p) => p.startsWith('/watchlist') },
    { to: '/explorer', label: 'Explorer', match: (p) => p.startsWith('/explorer') },
    { to: '/indices', label: 'Indices', match: (p) => p.startsWith('/indices') },
    { to: '/screeners', label: 'Screener', match: (p) => p.startsWith('/screeners') },
    { to: '/candidates', label: 'Discovery', match: (p) => p.startsWith('/candidates') || p.startsWith('/evaluations') },
    { to: '/recommendations', label: 'Recommendations', match: (p) => p.startsWith('/recommendations') },
    { to: '/strategy', label: 'Strategy', match: (p) => p === '/strategy' || p.startsWith('/strategy/') },
    { to: '/review', label: 'Review', match: (p) => p === '/review' || p.startsWith('/review/') },
    { to: '/notification-history', label: 'Notifications', match: (p) => p.startsWith('/notification-history') },
    { to: '/patterns', label: 'Patterns', match: (p) => p.startsWith('/patterns') },
    { to: '/calendar', label: 'Calendar', match: (p) => p.startsWith('/calendar') },
    { to: '/knowledge-board', label: 'Knowledge', match: (p) => p.startsWith('/knowledge-board') },
];

export const FOOTER_NAV_ITEMS = [
    ...MAIN_TAB_ITEMS,
    { to: '/settings/portfolio', label: 'Settings', match: (p) => p.startsWith('/settings') },
];
