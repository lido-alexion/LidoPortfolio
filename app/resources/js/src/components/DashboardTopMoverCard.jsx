import React from 'react';
import TopMoverPeriodToggle from './TopMoverPeriodToggle';
import { formatSignedPercent2, percentChangeColorClass } from '../utils/tableFormat';

function MoverLine({ mover, align = 'start' }) {
    const symbol = mover?.symbol;
    const changePercent = mover?.change_percent;

    if (!symbol) {
        return (
            <div className={`h5 m-0 text-muted text-${align === 'end' ? 'end' : 'start'}`}>
                N/A
            </div>
        );
    }

    return (
        <div
            className={[
                'h5 m-0 d-flex flex-wrap align-items-baseline gap-1',
                align === 'end' ? 'justify-content-end text-end' : '',
            ].join(' ').trim()}
        >
            <span>{symbol}</span>
            {changePercent != null && !Number.isNaN(Number(changePercent)) ? (
                <span
                    className={[
                        'lido-dashboard-top-mover-pct',
                        percentChangeColorClass(changePercent),
                    ].join(' ')}
                >
                    ({formatSignedPercent2(changePercent)})
                </span>
            ) : null}
        </div>
    );
}

/**
 * Combined Top gainer / loser card for the Dashboard Portfolio section.
 *
 * @param {{
 *   gainer: object|null|undefined,
 *   loser: object|null|undefined,
 *   period: string,
 *   onPeriodChange: (period: string) => void,
 * }} props
 */
export default function DashboardTopMoverCard({
    gainer,
    loser,
    period,
    onPeriodChange,
}) {
    return (
        <div className="col-12 col-md-6 col-lg-4">
            <div className="card h-100">
                <div className="card-body">
                    <div className="d-flex justify-content-between align-items-center gap-2 mb-1">
                        <div className="text-muted small">Top gainer/loser</div>
                        <TopMoverPeriodToggle value={period} onChange={onPeriodChange} />
                    </div>
                    <div className="d-flex justify-content-between align-items-baseline gap-2">
                        <div className="min-w-0 flex-grow-1">
                            <MoverLine mover={gainer} align="start" />
                        </div>
                        <div className="min-w-0 flex-grow-1">
                            <MoverLine mover={loser} align="end" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
