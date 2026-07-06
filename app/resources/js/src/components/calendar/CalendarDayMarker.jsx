import React from 'react';
import { conicGradientForColors, distinctColorsForDate } from '../../utils/calendarEvents';

export default function CalendarDayMarker({ events, className = '' }) {
    const colors = distinctColorsForDate(events);
    if (colors.length === 0) {
        return null;
    }

    const background = conicGradientForColors(colors);

    return (
        <span
            className={`calendar-day-marker ${className}`.trim()}
            style={{ background }}
            aria-hidden="true"
        />
    );
}
