import React from 'react';
import NavIcon, { ChevronRight } from './NavIcon';
import NavMenuItem from './NavMenuItem';
import { FavouritePinButton } from './SidebarFavourites';

/**
 * Reusable collapsible nav group with page children.
 */
export default function NavGroup({
    group,
    collapsed,
    expanded,
    onToggle,
    onNavigate,
    activePageId,
    isFavourite,
    canPinMore,
    onToggleFavourite,
}) {
    const groupHasActive = (group.children || []).some((page) => page.id === activePageId);

    return (
        <div
            className={[
                'lido-sidebar-section',
                expanded ? 'is-expanded' : 'is-collapsed',
                groupHasActive ? 'has-active' : '',
            ].filter(Boolean).join(' ')}
        >
            {collapsed ? (
                <div
                    className="lido-sidebar-section-divider"
                    title={group.title}
                    aria-hidden="true"
                />
            ) : (
                <button
                    type="button"
                    className="lido-sidebar-section-toggle"
                    aria-expanded={expanded}
                    onClick={onToggle}
                >
                    <NavIcon name={group.icon} size={15} className="lido-sidebar-icon lido-sidebar-icon--group" />
                    <span className="lido-sidebar-section-label">{group.title}</span>
                    <ChevronRight
                        className={`lido-sidebar-section-chevron${expanded ? ' is-open' : ''}`}
                        size={14}
                        strokeWidth={2}
                        aria-hidden="true"
                    />
                </button>
            )}
            <div
                className={`lido-sidebar-section-body${expanded ? ' is-open' : ''}`}
                aria-hidden={!expanded}
                {...(!expanded ? { inert: true } : {})}
            >
                <ul className="lido-sidebar-list">
                    {(group.children || []).map((page) => (
                        <li key={page.id}>
                            <div className="lido-sidebar-page-row">
                                <NavMenuItem
                                    title={page.title}
                                    icon={page.icon}
                                    route={page.route}
                                    badge={page.badge}
                                    tag={page.tag}
                                    disabled={page.disabled}
                                    external={page.external}
                                    active={!page.disabled && activePageId === page.id}
                                    collapsed={collapsed}
                                    variant="nav"
                                    onClick={onNavigate}
                                />
                                {!collapsed && !page.disabled && !page.external && (
                                    <FavouritePinButton
                                        navId={page.id}
                                        eligible={Boolean(page.favouriteEligible)}
                                        isFavourite={isFavourite(page.id)}
                                        canPinMore={canPinMore}
                                        onToggle={onToggleFavourite}
                                    />
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
