import React, { useEffect, useMemo } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { createNavAccessContext } from '../../navigation';
import { buildBreadcrumbs, findActiveNavItem, getPageTitle } from '../../utils/navigationTree';
import NavBadge from '../sidebar/NavBadge';

/**
 * Breadcrumbs + current page title for the main content chrome.
 */
export default function PageChrome() {
    const { pathname } = useLocation();
    const { user } = useAuth();
    const accessCtx = useMemo(() => createNavAccessContext(user), [user]);

    const crumbs = useMemo(
        () => buildBreadcrumbs(pathname, accessCtx),
        [pathname, accessCtx],
    );
    const title = useMemo(
        () => getPageTitle(pathname, accessCtx),
        [pathname, accessCtx],
    );
    const active = useMemo(
        () => findActiveNavItem(pathname, accessCtx),
        [pathname, accessCtx],
    );

    useEffect(() => {
        const previous = document.title;
        document.title = `${title} · StoX`;
        return () => {
            document.title = previous;
        };
    }, [title]);

    return (
        <div className="lido-page-chrome">
            <nav className="lido-breadcrumbs" aria-label="Breadcrumb">
                <ol className="lido-breadcrumbs-list">
                    {crumbs.map((crumb, index) => {
                        const isLast = index === crumbs.length - 1;
                        return (
                            <li key={`${crumb.id}-${index}`} className="lido-breadcrumbs-item">
                                {!isLast && crumb.route ? (
                                    <Link to={crumb.route} className="lido-breadcrumbs-link">
                                        {crumb.title}
                                    </Link>
                                ) : (
                                    <span
                                        className={`lido-breadcrumbs-current${isLast ? ' is-current' : ''}`}
                                        aria-current={isLast ? 'page' : undefined}
                                    >
                                        {crumb.title}
                                    </span>
                                )}
                                {!isLast && (
                                    <span className="lido-breadcrumbs-sep" aria-hidden="true">/</span>
                                )}
                            </li>
                        );
                    })}
                </ol>
            </nav>
            <div className="lido-page-title-row">
                <h1 className="lido-page-title">{title}</h1>
                {active && (
                    <NavBadge badge={active.badge} tag={active.tag} />
                )}
            </div>
        </div>
    );
}
