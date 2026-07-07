import React, { useCallback, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { createPopper } from '@popperjs/core';

const COMBO_BUTTON_OPEN_EVENT = 'lido-combo-button-open';

/**
 * Split primary + menu button (Carbon ComboButton pattern).
 * Menu is portaled and positioned with Popper so it clears table overflow/stacking.
 */
export default function ComboButton({
    label,
    onPrimaryClick,
    menuItems = [],
    variant = 'outline-secondary',
    size = 'sm',
    className = '',
}) {
    const menuId = useId();
    const [open, setOpen] = useState(false);
    const anchorRef = useRef(null);
    const menuRef = useRef(null);
    const popperRef = useRef(null);

    useEffect(() => {
        const handleOtherMenuOpen = (event) => {
            if (event.detail?.id !== menuId) {
                setOpen(false);
            }
        };

        document.addEventListener(COMBO_BUTTON_OPEN_EVENT, handleOtherMenuOpen);

        return () => {
            document.removeEventListener(COMBO_BUTTON_OPEN_EVENT, handleOtherMenuOpen);
        };
    }, [menuId]);

    const toggleMenu = useCallback((event) => {
        event.stopPropagation();
        setOpen((current) => {
            const next = !current;
            if (next) {
                document.dispatchEvent(new CustomEvent(COMBO_BUTTON_OPEN_EVENT, {
                    detail: { id: menuId },
                }));
            }
            return next;
        });
    }, [menuId]);

    useEffect(() => {
        if (!open || !anchorRef.current || !menuRef.current) {
            return undefined;
        }

        popperRef.current = createPopper(anchorRef.current, menuRef.current, {
            placement: 'bottom-end',
            strategy: 'fixed',
            modifiers: [
                { name: 'offset', options: { offset: [0, 4] } },
                { name: 'preventOverflow', options: { padding: 8 } },
                {
                    name: 'flip',
                    options: {
                        fallbackPlacements: ['top-end', 'bottom-start', 'top-start'],
                    },
                },
            ],
        });

        return () => {
            popperRef.current?.destroy();
            popperRef.current = null;
        };
    }, [open]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const handleDocumentClick = (event) => {
            if (anchorRef.current?.contains(event.target) || menuRef.current?.contains(event.target)) {
                return;
            }
            setOpen(false);
        };

        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('click', handleDocumentClick);
        document.addEventListener('keydown', handleEscape);

        return () => {
            document.removeEventListener('click', handleDocumentClick);
            document.removeEventListener('keydown', handleEscape);
        };
    }, [open]);

    const buttonClass = `btn btn-${size} btn-${variant}`;

    return (
        <>
            <div
                ref={anchorRef}
                className={`lido-combo-button btn-group ${className}`.trim()}
            >
                <button
                    type="button"
                    className={buttonClass}
                    onClick={onPrimaryClick}
                >
                    {label}
                </button>
                {menuItems.length > 0 ? (
                    <button
                        type="button"
                        className={`${buttonClass} dropdown-toggle dropdown-toggle-split`}
                        aria-expanded={open}
                        aria-haspopup="menu"
                        aria-controls={menuId}
                        onClick={toggleMenu}
                    >
                        <span className="visually-hidden">Open menu</span>
                    </button>
                ) : null}
            </div>
            {open && menuItems.length > 0 && createPortal(
                <div
                    ref={menuRef}
                    id={menuId}
                    className="dropdown-menu show lido-combo-button-menu shadow-sm"
                    role="menu"
                    style={{ position: 'fixed', zIndex: 1080 }}
                >
                    {menuItems.map((item) => (
                        <button
                            key={item.key ?? item.label}
                            type="button"
                            className={`dropdown-item ${item.danger ? 'text-danger' : ''}`.trim()}
                            role="menuitem"
                            onClick={() => {
                                setOpen(false);
                                item.onClick?.();
                            }}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>,
                document.body,
            )}
        </>
    );
}
