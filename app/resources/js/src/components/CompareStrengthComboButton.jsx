import React, { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import ComboButton from './ComboButton';
import {
    buildExplorerComparePath,
    compareStrengthLabel,
    pickPrimaryBenchmark,
} from '../utils/explorerLinks';

/**
 * Combo: compare selected stock vs primary index (primary click) or another Explorer benchmark (menu).
 */
export default function CompareStrengthComboButton({
    stockSymbol,
    indexes = [],
    variant = 'outline-secondary',
    size = 'sm',
    className = '',
}) {
    const navigate = useNavigate();
    const primary = useMemo(() => pickPrimaryBenchmark(indexes), [indexes]);
    const menuItems = useMemo(() => {
        const primarySymbol = String(primary?.symbol || '').toUpperCase();

        return (indexes || [])
            .filter((row) => String(row.symbol || '').toUpperCase() !== primarySymbol)
            .map((row) => ({
                key: row.symbol,
                label: compareStrengthLabel(row.name || row.symbol),
                onClick: () => {
                    navigate(buildExplorerComparePath(stockSymbol, row.symbol));
                },
            }));
    }, [indexes, navigate, primary?.symbol, stockSymbol]);

    if (!stockSymbol || !primary?.symbol) {
        return null;
    }

    return (
        <ComboButton
            className={className}
            label={compareStrengthLabel(primary.name || primary.symbol)}
            variant={variant}
            size={size}
            onPrimaryClick={() => {
                navigate(buildExplorerComparePath(stockSymbol, primary.symbol));
            }}
            menuItems={menuItems}
        />
    );
}
