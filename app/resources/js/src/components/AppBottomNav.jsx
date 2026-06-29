import React, { useCallback, useEffect, useRef, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { FOOTER_NAV_ITEMS } from '../config/mainNav';

const BOTTOM_EDGE_PX = 32;
const SCROLL_BOTTOM_PX = 32;

function isAtPageBottom() {
    const scrollY = window.scrollY || document.documentElement.scrollTop;
    const viewportHeight = window.innerHeight;
    const docHeight = document.documentElement.scrollHeight;

    return scrollY + viewportHeight >= docHeight - SCROLL_BOTTOM_PX;
}

export default function AppBottomNav() {
    const { pathname } = useLocation();
    const hoveringFooter = useRef(false);
    const lastMouseY = useRef(window.innerHeight);
    const [visible, setVisible] = useState(() => isAtPageBottom());

    const recomputeVisibility = useCallback(() => {
        const nearBottomEdge = lastMouseY.current >= window.innerHeight - BOTTOM_EDGE_PX;
        setVisible(isAtPageBottom() || nearBottomEdge || hoveringFooter.current);
    }, []);

    useEffect(() => {
        const onScroll = () => recomputeVisibility();
        const onMouseMove = (event) => {
            lastMouseY.current = event.clientY;
            recomputeVisibility();
        };
        const onResize = () => recomputeVisibility();

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('mousemove', onMouseMove, { passive: true });
        window.addEventListener('resize', onResize);

        recomputeVisibility();

        return () => {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('mousemove', onMouseMove);
            window.removeEventListener('resize', onResize);
        };
    }, [recomputeVisibility]);

    useEffect(() => {
        document.documentElement.classList.toggle('lido-footer-visible', visible);

        return () => {
            document.documentElement.classList.remove('lido-footer-visible');
        };
    }, [visible]);

    const onFooterEnter = () => {
        hoveringFooter.current = true;
        recomputeVisibility();
    };

    const onFooterLeave = () => {
        hoveringFooter.current = false;
        recomputeVisibility();
    };

    return (
        <nav
            className={`lido-bottom-nav${visible ? ' lido-bottom-nav--visible' : ''}`}
            aria-label="Footer navigation"
            onMouseEnter={onFooterEnter}
            onMouseLeave={onFooterLeave}
        >
            {FOOTER_NAV_ITEMS.map((tab) => {
                const isActive = tab.match(pathname);
                return (
                    <NavLink
                        key={tab.to}
                        to={tab.to}
                        end={tab.end}
                        className={`nav-link${isActive ? ' active' : ''}`}
                        tabIndex={visible ? 0 : -1}
                    >
                        {tab.label}
                    </NavLink>
                );
            })}
        </nav>
    );
}
