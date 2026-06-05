export const MAIN_NAV_ITEMS = [
    { to: '/', label: 'Dashboard', end: true, match: (p) => p === '/' },
    { to: '/transactions', label: 'Transactions', match: (p) => p.startsWith('/transactions') },
    { to: '/holdings', label: 'Holdings', match: (p) => p.startsWith('/holdings') },
    { to: '/explorer', label: 'Explorer', match: (p) => p.startsWith('/explorer') },
    { to: '/settings', label: 'Settings', match: (p) => p.startsWith('/settings') },
];
