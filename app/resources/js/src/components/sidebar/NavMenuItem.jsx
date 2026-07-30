import React from 'react';
import { NavLink } from 'react-router-dom';
import { ROUTES } from '../../navigation/routes';
import NavIcon from './NavIcon';
import NavBadge from './NavBadge';
import NavTooltip, { formatNavTooltipLabel } from './NavTooltip';

function buildClassName({
    active,
    disabled,
    external,
    collapsed,
    busy,
    variant,
}) {
    return [
        'lido-sidebar-link',
        variant === 'favourite' ? 'lido-sidebar-link--favourite' : '',
        variant === 'action' ? 'lido-sidebar-link--action' : '',
        active ? 'is-active' : '',
        disabled ? 'is-disabled' : '',
        external ? 'is-external' : '',
        busy ? 'is-busy' : '',
        collapsed ? 'lido-sidebar-link--ribbon' : '',
    ].filter(Boolean).join(' ');
}

/**
 * Reusable sidebar menu control — navigate / external / disabled / button.
 *
 * @param {{
 *   title: string,
 *   icon?: string|null,
 *   route?: string|null,
 *   badge?: string|number|null,
 *   tag?: string|null,
 *   disabled?: boolean,
 *   external?: boolean,
 *   active?: boolean,
 *   collapsed?: boolean,
 *   busy?: boolean,
 *   variant?: 'nav'|'favourite'|'action',
 *   onClick?: Function,
 *   showActiveBar?: boolean,
 *   showExternalHint?: boolean,
 *   end?: boolean,
 * }} props
 */
export default function NavMenuItem({
    title,
    icon,
    route = null,
    badge = null,
    tag = null,
    disabled = false,
    external = false,
    active = false,
    collapsed = false,
    busy = false,
    variant = 'nav',
    onClick,
    showActiveBar = true,
    showExternalHint = true,
    end,
}) {
    const className = buildClassName({
        active,
        disabled,
        external,
        collapsed,
        busy,
        variant,
    });

    const tooltip = formatNavTooltipLabel({ title, tag, disabled, external });
    const iconSize = collapsed ? 18 : 16;

    const inner = (
        <>
            {showActiveBar && (
                <span className="lido-sidebar-link-active-bar" aria-hidden="true" />
            )}
            <NavIcon name={icon} size={iconSize} />
            <span className="lido-sidebar-link-label">{title}</span>
            <NavBadge badge={badge} tag={tag} collapsed={collapsed} />
            {external && showExternalHint && !collapsed && (
                <NavIcon
                    name="ExternalLink"
                    size={12}
                    className="lido-sidebar-icon lido-sidebar-icon--external"
                />
            )}
            <NavTooltip visible={collapsed}>{tooltip}</NavTooltip>
        </>
    );

    if (disabled) {
        return (
            <span className={className} aria-disabled="true" title={title}>
                {inner}
            </span>
        );
    }

    if (variant === 'action' || (!route && typeof onClick === 'function')) {
        return (
            <button
                type="button"
                className={className}
                aria-label={title}
                disabled={busy}
                onClick={onClick}
            >
                {inner}
            </button>
        );
    }

    if (external && route) {
        return (
            <a
                href={route}
                className={className}
                aria-label={title}
                target="_blank"
                rel="noopener noreferrer"
                onClick={onClick}
            >
                {inner}
            </a>
        );
    }

    if (!route) {
        return null;
    }

    const isEnd = end ?? route === ROUTES.HOME;

    return (
        <NavLink
            to={route}
            end={isEnd}
            className={className}
            aria-label={title}
            aria-current={active ? 'page' : undefined}
            onClick={onClick}
        >
            {inner}
        </NavLink>
    );
}
