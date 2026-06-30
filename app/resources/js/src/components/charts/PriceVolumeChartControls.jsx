import React from 'react';
import SegmentToggle from '../SegmentToggle';
import { SAMPLING_OPTIONS, TIME_RANGE_OPTIONS } from './priceVolumeChartTypes';

export default function PriceVolumeChartControls({
    timeRange,
    onTimeRangeChange,
    sampling,
    onSamplingChange,
    disabled = false,
}) {
    return (
        <div className="lido-price-volume-chart-controls d-flex flex-wrap align-items-end gap-3">
            <SegmentToggle
                compact
                label="Range"
                value={timeRange}
                onChange={onTimeRangeChange}
                options={TIME_RANGE_OPTIONS}
                ariaLabel="Chart time range"
                disabled={disabled}
            />
            <SegmentToggle
                compact
                label="Sampling"
                value={sampling}
                onChange={onSamplingChange}
                options={SAMPLING_OPTIONS.map(({ value, label }) => ({ value, label }))}
                ariaLabel="Chart sampling interval"
                disabled={disabled}
            />
        </div>
    );
}
