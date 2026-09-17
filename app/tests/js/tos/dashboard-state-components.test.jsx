import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { DataTableCard } from '../../../resources/js/src/components/DataTable';
import { DashboardCalendarCard } from '../../../resources/js/src/components/calendar/CalendarDayEventsDialog';

describe('Dashboard optional section state semantics', () => {
    it('distinguishes an empty calendar from an unavailable calendar', () => {
        const { rerender } = render(
            <DashboardCalendarCard events={[]} loading={false} />,
        );

        expect(screen.getByText('No upcoming events in the next month.')).toBeInTheDocument();

        rerender(
            <DashboardCalendarCard
                events={[]}
                loading={false}
                error="Upcoming events are temporarily unavailable."
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('Upcoming events are temporarily unavailable.');
        expect(screen.queryByText('No upcoming events in the next month.')).not.toBeInTheDocument();
    });

    it('distinguishes an empty table from an unavailable table', () => {
        const columns = [{ accessorKey: 'name', header: 'Name' }];
        const { rerender } = render(
            <DataTableCard
                columns={columns}
                data={[]}
                emptyMessage="No actionable patterns on your holdings right now."
            />,
        );

        expect(screen.getByText('No actionable patterns on your holdings right now.')).toBeInTheDocument();

        rerender(
            <DataTableCard
                columns={columns}
                data={[]}
                emptyMessage="No actionable patterns on your holdings right now."
                error="Pattern signals are temporarily unavailable."
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('Pattern signals are temporarily unavailable.');
        expect(screen.queryByText('No actionable patterns on your holdings right now.')).not.toBeInTheDocument();
    });
});
