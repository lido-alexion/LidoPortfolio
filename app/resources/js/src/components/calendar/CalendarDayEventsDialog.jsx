import React from 'react';
import { formatDaysAhead, recurrenceSummary } from '../../utils/calendarEvents';
import { formatTransactionDateDisplay } from '../../utils/transactionDate';

export default function CalendarDayEventsDialog({
    open,
    date,
    events,
    isAdmin = false,
    onClose,
    onEdit,
}) {
    if (!open || !date) {
        return null;
    }

    const dateLabel = formatTransactionDateDisplay(date);

    return (
        <>
            <div className="modal show d-block" tabIndex={-1} role="dialog" aria-modal="true">
                <div className="modal-dialog modal-dialog-scrollable">
                    <div className="modal-content">
                        <div className="modal-header">
                            <h5 className="modal-title">Events on {dateLabel}</h5>
                            <button type="button" className="btn-close" aria-label="Close" onClick={onClose} />
                        </div>
                        <div className="modal-body">
                            {events.length === 0 ? (
                                <p className="text-muted mb-0">No events on this date.</p>
                            ) : (
                                <ul className="list-group list-group-flush calendar-day-events-list">
                                    {events.map((event) => {
                                        const canEdit = onEdit && (!event.is_trade_holiday || isAdmin);
                                        return (
                                            <li key={`${event.event_id}-${event.date}`} className="list-group-item px-0">
                                                <div className="d-flex align-items-start gap-2">
                                                    <span
                                                        className="calendar-event-dot flex-shrink-0"
                                                        style={{ backgroundColor: event.color }}
                                                        aria-hidden="true"
                                                    />
                                                    <div className="flex-grow-1 min-w-0">
                                                        <div className="fw-semibold">
                                                            {event.title}
                                                            {event.is_trade_holiday ? (
                                                                <span className="badge text-bg-warning ms-2 fw-normal">Trade holiday</span>
                                                            ) : null}
                                                        </div>
                                                        {event.description ? (
                                                            <div className="small text-muted">{event.description}</div>
                                                        ) : null}
                                                        <div className="small text-muted">{recurrenceSummary(event)}</div>
                                                    </div>
                                                    {canEdit ? (
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline-secondary flex-shrink-0"
                                                            onClick={() => onEdit(event.event_id)}
                                                        >
                                                            Edit
                                                        </button>
                                                    ) : event.is_trade_holiday ? (
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline-secondary flex-shrink-0"
                                                            onClick={() => onEdit(event.event_id)}
                                                        >
                                                            View
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>
                        <div className="modal-footer">
                            <button type="button" className="btn btn-outline-secondary" onClick={onClose}>
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div className="modal-backdrop show" onClick={onClose} aria-hidden="true" />
        </>
    );
}

export function DashboardCalendarCard({ events, loading, onOpenCalendar }) {
    return (
        <div className="card h-100">
            <div className="card-header d-flex justify-content-between align-items-center gap-2">
                <div className="mb-0">Upcoming calendar events</div>
                {onOpenCalendar ? (
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={onOpenCalendar}>
                        Open calendar
                    </button>
                ) : null}
            </div>
            <div className="card-body p-0">
                {loading ? (
                    <div className="text-center text-muted py-4">Loading…</div>
                ) : events.length === 0 ? (
                    <div className="text-muted p-3">No upcoming events in the next month.</div>
                ) : (
                    <ul className="list-group list-group-flush">
                        {events.map((event) => (
                            <li key={`${event.event_id}-${event.date}`} className="list-group-item">
                                <div className="d-flex align-items-start gap-2">
                                    <span
                                        className="calendar-event-dot flex-shrink-0"
                                        style={{ backgroundColor: event.color }}
                                        aria-hidden="true"
                                    />
                                    <div className="flex-grow-1 min-w-0">
                                        <div className="fw-semibold">
                                            {event.title}
                                            {event.is_trade_holiday ? (
                                                <span className="badge text-bg-warning ms-2 fw-normal">Holiday</span>
                                            ) : null}
                                        </div>
                                        <div className="small text-muted">
                                            {formatTransactionDateDisplay(event.date)}
                                            {' · '}
                                            {formatDaysAhead(event.days_ahead)}
                                        </div>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
