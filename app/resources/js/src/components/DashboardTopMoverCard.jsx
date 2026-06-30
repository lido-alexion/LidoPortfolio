import React from 'react';
import TopMoverPeriodToggle from './TopMoverPeriodToggle';
import { formatSignedPercent2, percentChangeColorClass } from '../utils/tableFormat';

export default function DashboardTopMoverCard({
    title,
    mover,
    period,
    onPeriodChange,
}) {
    const symbol = mover?.symbol;
    const changePercent = mover?.change_percent;

    return (
        <div className="col-12 col-md-6 col-lg-4">
            <div className="card h-100">
                <div className="card-body">
                    <div className="d-flex justify-content-between align-items-center gap-2 mb-1">
                        <div className="text-muted small">{title}</div>
                        <TopMoverPeriodToggle value={period} onChange={onPeriodChange} />
                    </div>
                    {symbol ? (
                        <div className="h5 m-0 d-flex flex-wrap align-items-baseline gap-1">
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
                    ) : (
                        <div className="h5 m-0 text-muted">N/A</div>
                    )}
                </div>
            </div>
        </div>
    );
}
