import React, { useState } from 'react';
import api from '../api';
import { showToast } from '../toast';
import { buildStockAnalysisPrompt } from '../utils/stockAnalysisPrompt';

function PuzzlePieceIcon({ size = 14 }) {
    return (
        <svg
            viewBox="0 0 24 24"
            width={size}
            height={size}
            aria-hidden="true"
            focusable="false"
            fill="currentColor"
        >
            <path d="M20.5 11H19V7c0-1.1-.9-2-2-2h-4V3.5C13 2.12 11.88 1 10.5 1S8 2.12 8 3.5V5H4c-1.1 0-1.99.9-1.99 2v3.8H3.5c1.49 0 2.7 1.21 2.7 2.7s-1.21 2.7-2.7 2.7H2V20c0 1.1.9 2 2 2h3.8v-1.5c0-1.49 1.21-2.7 2.7-2.7 1.49 0 2.7 1.21 2.7 2.7V22H17c1.1 0 2-.9 2-2v-4h1.5c1.38 0 2.5-1.12 2.5-2.5S21.88 11 20.5 11z" />
        </svg>
    );
}

/**
 * Icon button that builds an AI analysis prompt (with 7-day OHLCV) and copies it.
 */
export default function AnalyseStockButton({
    stockId,
    symbol = '',
    name = '',
    className = '',
    size = 14,
    stopPropagation = false,
}) {
    const [busy, setBusy] = useState(false);
    const disabled = !stockId || busy;

    const handleClick = async (event) => {
        if (stopPropagation) {
            event.preventDefault();
            event.stopPropagation();
        }
        if (disabled) {
            return;
        }

        setBusy(true);
        try {
            const res = await api.get(`/stocks/${stockId}/market-prices`, {
                skipErrorToast: true,
            });
            const payload = res.data || {};
            const rows = Array.isArray(payload.data) ? payload.data : [];
            const stock = payload.stock || {};
            const prompt = buildStockAnalysisPrompt({
                symbol: symbol || stock.symbol,
                name: name || stock.name,
                ohlcvRows: rows,
            });

            if (!navigator.clipboard?.writeText) {
                throw new Error('Clipboard unavailable');
            }
            await navigator.clipboard.writeText(prompt);
            showToast('AI analysis prompt copied to clipboard.');
        } catch {
            showToast('Could not copy AI analysis prompt.', 'danger');
        } finally {
            setBusy(false);
        }
    };

    return (
        <button
            type="button"
            className={['btn btn-link p-0 lido-analyse-stock-btn', className].filter(Boolean).join(' ')}
            title="Generates a prompt for analysing this stock with AI"
            aria-label="Generate AI analysis prompt"
            disabled={disabled}
            onClick={handleClick}
            onMouseDown={stopPropagation ? ((event) => event.stopPropagation()) : undefined}
        >
            {busy ? (
                <span
                    className="spinner-border spinner-border-sm"
                    role="status"
                    aria-label="Building analysis prompt"
                />
            ) : (
                <PuzzlePieceIcon size={size} />
            )}
        </button>
    );
}
