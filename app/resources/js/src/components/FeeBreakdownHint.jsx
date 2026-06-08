import React, { useEffect, useMemo, useRef } from 'react';
import { Tooltip as BootstrapTooltip } from 'bootstrap';
import { formatFeeBreakdownHtml, formatFeeBreakdownText } from '../utils/feeCalculator';

export default function FeeBreakdownHint({ breakdown, total, id = 'fee-breakdown-hint' }) {
    const triggerRef = useRef(null);
    const tooltipText = useMemo(
        () => formatFeeBreakdownText(breakdown, total),
        [breakdown, total],
    );
    const tooltipHtml = useMemo(
        () => formatFeeBreakdownHtml(breakdown, total),
        [breakdown, total],
    );

    useEffect(() => {
        const el = triggerRef.current;
        if (!el) {
            return undefined;
        }

        BootstrapTooltip.getInstance(el)?.dispose();

        const tooltip = new BootstrapTooltip(el, {
            title: tooltipHtml,
            html: true,
            placement: 'top',
            trigger: 'hover focus',
            customClass: 'lido-fee-breakdown-tooltip',
            sanitize: false,
        });

        return () => tooltip.dispose();
    }, [tooltipHtml]);

    return (
        <button
            ref={triggerRef}
            id={id}
            type="button"
            className="lido-fee-breakdown-hint flex-shrink-0"
            tabIndex={0}
            aria-label={`Show fee breakdown. ${tooltipText.replace(/\n/g, '; ')}`}
        >
            <span aria-hidden="true">ⓘ</span>
        </button>
    );
}
