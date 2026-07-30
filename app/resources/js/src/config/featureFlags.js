/**
 * Feature flags for incremental UI rollouts.
 *
 * Sidebar shell (layout + responsive behaviour only):
 * - Enable:  VITE_SIDEBAR_SHELL=1  or  localStorage.setItem('lido-feature-sidebar-shell','1')
 * - Disable: VITE_SIDEBAR_SHELL=0  or  localStorage.setItem('lido-feature-sidebar-shell','0')
 * Default: enabled (shell framework phase).
 */
export function isSidebarShellEnabled() {
    const env = import.meta.env.VITE_SIDEBAR_SHELL;
    if (env === '0' || env === 'false') {
        return false;
    }
    if (env === '1' || env === 'true') {
        return true;
    }
    try {
        const stored = window.localStorage.getItem('lido-feature-sidebar-shell');
        if (stored === '0') {
            return false;
        }
        if (stored === '1') {
            return true;
        }
    } catch {
        /* ignore */
    }
    return true;
}
