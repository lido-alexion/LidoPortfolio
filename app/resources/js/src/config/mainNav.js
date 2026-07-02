export const MAIN_TAB_ITEMS = [
    { to: '/', label: 'Dashboard', end: true, match: (p) => p === '/' },
    { to: '/transactions', label: 'Transactions', match: (p) => p.startsWith('/transactions') },
    { to: '/holdings', label: 'Holdings', match: (p) => p.startsWith('/holdings') },
    { to: '/watchlist', label: 'Watchlist', match: (p) => p.startsWith('/watchlist') },
    { to: '/explorer', label: 'Explorer', match: (p) => p.startsWith('/explorer') },
    { to: '/patterns', label: 'Patterns', match: (p) => p.startsWith('/patterns') },
    { to: '/knowledge-board', label: 'Knowledge', match: (p) => p.startsWith('/knowledge-board') },
];

export const FOOTER_NAV_ITEMS = [
    ...MAIN_TAB_ITEMS,
    { to: '/settings', label: 'Settings', match: (p) => p.startsWith('/settings') },
];
