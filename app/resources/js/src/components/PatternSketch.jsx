import React from 'react';

const STROKE = 'currentColor';
const BULL = '#198754';
const BEAR = '#dc3545';
const MUTED = 'var(--lido-text-muted, #6c757d)';
const LINE_STROKE = '3';
const CANDLE_WICK_STROKE = '3';

function Candle({ x, o, h, l, c, w = 10 }) {
    // SVG Y grows downward: smaller Y = higher price. Bullish when close is above open (c <= o).
    const top = Math.min(o, c);
    const bottom = Math.max(o, c);
    const color = c <= o ? BULL : BEAR;
    const bodyH = Math.max(bottom - top, 1.5);
    return (
        <g>
            <line x1={x} y1={h} x2={x} y2={l} stroke={color} strokeWidth={CANDLE_WICK_STROKE} />
            <rect x={x - w / 2} y={top} width={w} height={bodyH} fill={color} rx="0.5" />
        </g>
    );
}

function LinePath({ d, color = STROKE, dash }) {
    return (
        <path
            d={d}
            fill="none"
            stroke={color}
            strokeWidth={LINE_STROKE}
            strokeDasharray={dash || undefined}
        />
    );
}

/** @type {Record<string, React.ReactNode>} */
const SKETCHES = {
    doji: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={40} o={22} h={10} l={38} c={22} w={4} />
        </svg>
    ),
    hammer: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={40} o={14} h={12} l={40} c={16} />
        </svg>
    ),
    inverted_hammer: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={40} o={32} h={10} l={34} c={30} />
        </svg>
    ),
    hanging_man: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <line x1="8" y1="38" x2="72" y2="18" stroke={MUTED} strokeWidth={LINE_STROKE} strokeDasharray="3 2" />
            <Candle x={40} o={14} h={12} l={40} c={16} />
        </svg>
    ),
    shooting_star: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <line x1="8" y1="38" x2="72" y2="18" stroke={MUTED} strokeWidth={LINE_STROKE} strokeDasharray="3 2" />
            <Candle x={40} o={32} h={10} l={34} c={30} />
        </svg>
    ),
    bullish_marubozu: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={40} o={34} h={12} l={36} c={12} w={14} />
        </svg>
    ),
    bearish_marubozu: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={40} o={12} h={36} l={12} c={36} w={14} />
        </svg>
    ),
    spinning_top: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={40} o={22} h={12} l={36} c={26} w={6} />
        </svg>
    ),
    bullish_engulfing: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={28} o={18} h={16} l={28} c={24} w={8} />
            <Candle x={52} o={28} h={10} l={34} c={12} w={12} />
        </svg>
    ),
    bearish_engulfing: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={28} o={24} h={12} l={22} c={18} w={8} />
            <Candle x={52} o={16} h={34} l={14} c={32} w={12} />
        </svg>
    ),
    bullish_harami: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={28} o={14} h={12} l={30} c={32} w={10} />
            <Candle x={52} o={22} h={20} l={24} c={24} w={6} />
        </svg>
    ),
    bearish_harami: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={28} o={32} h={12} l={14} c={16} w={10} />
            <Candle x={52} o={26} h={22} l={24} c={22} w={6} />
        </svg>
    ),
    piercing_line: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={28} o={14} h={12} l={28} c={30} w={9} />
            <Candle x={52} o={32} h={14} l={18} c={20} w={9} />
            <line x1="18" y1="22" x2="62" y2="22" stroke={MUTED} strokeWidth={LINE_STROKE} strokeDasharray="2 2" />
        </svg>
    ),
    dark_cloud_cover: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={28} o={30} h={12} l={28} c={14} w={9} />
            <Candle x={52} o={12} h={14} l={24} c={26} w={9} />
            <line x1="18" y1="22" x2="62" y2="22" stroke={MUTED} strokeWidth={LINE_STROKE} strokeDasharray="2 2" />
        </svg>
    ),
    morning_star: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={18} o={14} h={12} l={28} c={30} w={7} />
            <Candle x={40} o={32} h={28} l={30} c={31} w={4} />
            <Candle x={62} o={28} h={10} l={22} c={14} w={8} />
        </svg>
    ),
    evening_star: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={18} o={30} h={12} l={28} c={14} w={7} />
            <Candle x={40} o={16} h={20} l={18} c={17} w={4} />
            <Candle x={62} o={18} h={34} l={14} c={32} w={8} />
        </svg>
    ),
    three_white_soldiers: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={18} o={28} h={24} l={20} c={16} w={7} />
            <Candle x={40} o={22} h={18} l={14} c={10} w={7} />
            <Candle x={62} o={14} h={10} l={6} c={4} w={7} />
        </svg>
    ),
    three_black_crows: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <Candle x={18} o={16} h={12} l={28} c={30} w={7} />
            <Candle x={40} o={28} h={22} l={32} c={36} w={7} />
            <Candle x={62} o={34} h={30} l={38} c={42} w={7} />
        </svg>
    ),
    cup_and_handle: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <LinePath d="M8 16 L20 28 Q40 40 60 28 L72 16" />
            <LinePath d="M60 28 L68 32 L72 28" color={BULL} />
        </svg>
    ),
    head_and_shoulders: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <LinePath d="M6 34 L18 18 L30 30 L42 10 L54 30 L66 18 L74 34" />
            <LinePath d="M18 30 L66 30" color={MUTED} dash="3 2" />
        </svg>
    ),
    inverse_head_and_shoulders: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <LinePath d="M6 14 L18 30 L30 18 L42 38 L54 18 L66 30 L74 14" />
            <LinePath d="M18 18 L66 18" color={MUTED} dash="3 2" />
        </svg>
    ),
    double_top: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <LinePath d="M8 34 L22 12 L40 28 L58 12 L72 34" />
            <LinePath d="M22 28 L58 28" color={MUTED} dash="3 2" />
        </svg>
    ),
    double_bottom: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <LinePath d="M8 14 L22 36 L40 20 L58 36 L72 14" />
            <LinePath d="M22 20 L58 20" color={MUTED} dash="3 2" />
        </svg>
    ),
    ascending_triangle: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <line x1="8" y1="14" x2="72" y2="14" stroke={STROKE} strokeWidth={LINE_STROKE} />
            <line x1="8" y1="36" x2="72" y2="18" stroke={STROKE} strokeWidth={LINE_STROKE} />
            <LinePath d="M12 32 L28 26 L44 22 L60 18" color={BULL} />
        </svg>
    ),
    descending_triangle: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <line x1="8" y1="34" x2="72" y2="34" stroke={STROKE} strokeWidth={LINE_STROKE} />
            <line x1="8" y1="12" x2="72" y2="28" stroke={STROKE} strokeWidth={LINE_STROKE} />
            <LinePath d="M12 16 L28 22 L44 26 L60 30" color={BEAR} />
        </svg>
    ),
    symmetrical_triangle: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <line x1="8" y1="12" x2="72" y2="28" stroke={STROKE} strokeWidth={LINE_STROKE} />
            <line x1="8" y1="36" x2="72" y2="20" stroke={STROKE} strokeWidth={LINE_STROKE} />
        </svg>
    ),
    bull_flag: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <LinePath d="M8 40 L20 14" color={BULL} />
            <LinePath d="M24 18 L72 30" />
            <line x1="24" y1="14" x2="72" y2="26" stroke={STROKE} strokeWidth={LINE_STROKE} strokeDasharray="2 2" />
        </svg>
    ),
    bear_flag: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <LinePath d="M8 8 L20 34" color={BEAR} />
            <LinePath d="M24 30 L72 18" />
            <line x1="24" y1="34" x2="72" y2="22" stroke={STROKE} strokeWidth={LINE_STROKE} strokeDasharray="2 2" />
        </svg>
    ),
    rising_wedge: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <line x1="8" y1="36" x2="72" y2="16" stroke={STROKE} strokeWidth={LINE_STROKE} />
            <line x1="8" y1="28" x2="72" y2="8" stroke={STROKE} strokeWidth={LINE_STROKE} />
        </svg>
    ),
    falling_wedge: (
        <svg viewBox="0 0 80 48" className="lido-pattern-sketch-svg" aria-hidden>
            <line x1="8" y1="12" x2="72" y2="32" stroke={STROKE} strokeWidth={LINE_STROKE} />
            <line x1="8" y1="20" x2="72" y2="40" stroke={STROKE} strokeWidth={LINE_STROKE} />
        </svg>
    ),
};

export default function PatternSketch({ patternId, className = '' }) {
    const sketch = SKETCHES[patternId];
    if (!sketch) {
        return (
            <div className={['lido-pattern-sketch lido-pattern-sketch--placeholder', className].join(' ')}>
                <span className="small text-muted">—</span>
            </div>
        );
    }
    return (
        <div className={['lido-pattern-sketch', className].join(' ')} title="Pattern sketch">
            {sketch}
        </div>
    );
}
