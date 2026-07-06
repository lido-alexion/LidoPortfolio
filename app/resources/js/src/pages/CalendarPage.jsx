import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';
import CalendarDayMarker from '../components/calendar/CalendarDayMarker';
import CalendarDayEventsDialog from '../components/calendar/CalendarDayEventsDialog';
import CalendarEventFormDialog from '../components/calendar/CalendarEventFormDialog';
import {
    buildMonthGrid,
    calendarMonthsToRender,
    dateKey,
    groupOccurrencesByDate,
    monthLabel,
} from '../utils/calendarEvents';

const WEEKDAY_HEADERS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function MonthGrid({ year, monthIndex, occurrencesByDate, onDayClick }) {
    const cells = buildMonthGrid(year, monthIndex);
    const todayKey = dateKey(new Date());

    return (
        <div className="calendar-month-grid">
            <div className="calendar-month-weekdays">
                {WEEKDAY_HEADERS.map((label) => (
                    <div key={label} className="calendar-weekday-label">{label}</div>
                ))}
            </div>
            <div className="calendar-month-days">
                {cells.map((date, index) => {
                    if (!date) {
                        return <div key={`empty-${index}`} className="calendar-day-cell is-empty" aria-hidden="true" />;
                    }
                    const key = dateKey(date);
                    const dayEvents = occurrencesByDate[key] ?? [];
                    const title = dayEvents.map((e) => e.title).join(', ');
                    const isToday = key === todayKey;

                    return (
                        <button
                            key={key}
                            type="button"
                            className={`calendar-day-cell${dayEvents.length ? ' has-events' : ''}${isToday ? ' is-today' : ''}`}
                            title={title || undefined}
                            onClick={() => onDayClick(key, dayEvents)}
                        >
                            <span className="calendar-day-number-wrap">
                                {dayEvents.length > 0 ? (
                                    <CalendarDayMarker events={dayEvents} className="calendar-day-marker--behind" />
                                ) : null}
                                <span className="calendar-day-number">{date.getDate()}</span>
                            </span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export default function CalendarPage() {
    const [events, setEvents] = useState([]);
    const [occurrences, setOccurrences] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [editingEvent, setEditingEvent] = useState(null);
    const [dayDialog, setDayDialog] = useState({ open: false, date: null, events: [] });

    const months = useMemo(() => calendarMonthsToRender(new Date()), []);
    const range = useMemo(() => {
        if (months.length === 0) {
            return { from: '', to: '' };
        }
        const first = months[0];
        const last = months[months.length - 1];
        const from = `${first.year}-${String(first.month + 1).padStart(2, '0')}-01`;
        const lastDay = new Date(last.year, last.month + 1, 0).getDate();
        const to = `${last.year}-${String(last.month + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
        return { from, to };
    }, [months]);

    const occurrencesByDate = useMemo(
        () => groupOccurrencesByDate(occurrences),
        [occurrences],
    );

    const loadData = useCallback(async () => {
        setLoading(true);
        try {
            const [eventsRes, occRes] = await Promise.all([
                api.get('/calendar/events'),
                api.get('/calendar/occurrences', { params: range }),
            ]);
            setEvents(eventsRes.data?.data ?? []);
            setOccurrences(occRes.data?.data ?? []);
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to load calendar.', 'danger');
        } finally {
            setLoading(false);
        }
    }, [range]);

    useEffect(() => {
        if (range.from && range.to) {
            loadData();
        }
    }, [loadData, range.from, range.to]);

    const openCreate = () => {
        setEditingEvent(null);
        setFormOpen(true);
    };

    const openEdit = (eventId) => {
        const event = events.find((item) => item.id === eventId);
        if (!event) {
            return;
        }
        setEditingEvent(event);
        setFormOpen(true);
        setDayDialog({ open: false, date: null, events: [] });
    };

    const handleSave = async (payload) => {
        setSaving(true);
        try {
            if (editingEvent?.id) {
                await api.put(`/calendar/events/${editingEvent.id}`, payload);
                showToast('Event updated.', 'success');
            } else {
                await api.post('/calendar/events', payload);
                showToast('Event created.', 'success');
            }
            setFormOpen(false);
            setEditingEvent(null);
            await loadData();
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to save event.', 'danger');
        } finally {
            setSaving(false);
        }
    };

    const handleDelete = async (event) => {
        if (!window.confirm(`Delete "${event.title}"?`)) {
            return;
        }
        setSaving(true);
        try {
            await api.delete(`/calendar/events/${event.id}`);
            showToast('Event deleted.', 'success');
            setFormOpen(false);
            setEditingEvent(null);
            await loadData();
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to delete event.', 'danger');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="container py-3 calendar-page">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Stocks calendar</h1>
                    <p className="text-muted small mb-0">
                        Track F&amp;O expiry, options expiry, and other market dates.{' '}
                        <Link to="/">Dashboard</Link> shows upcoming events for the next month.
                    </p>
                </div>
                <button type="button" className="btn btn-primary" onClick={openCreate}>
                    + New event
                </button>
            </div>

            {loading ? (
                <div className="text-center text-muted py-5">Loading calendar…</div>
            ) : (
                <div className="calendar-year-grid">
                    {months.map(({ year, month }) => (
                        <section key={`${year}-${month}`} className="card calendar-month-card">
                            <div className="card-header py-2">
                                <h2 className="h6 mb-0">{monthLabel(year, month)}</h2>
                            </div>
                            <div className="card-body p-2">
                                <MonthGrid
                                    year={year}
                                    monthIndex={month}
                                    occurrencesByDate={occurrencesByDate}
                                    onDayClick={(date, dayEvents) => setDayDialog({ open: true, date, events: dayEvents })}
                                />
                            </div>
                        </section>
                    ))}
                </div>
            )}

            <CalendarEventFormDialog
                open={formOpen}
                event={editingEvent}
                saving={saving}
                onClose={() => {
                    setFormOpen(false);
                    setEditingEvent(null);
                }}
                onSave={handleSave}
                onDelete={handleDelete}
            />

            <CalendarDayEventsDialog
                open={dayDialog.open}
                date={dayDialog.date}
                events={dayDialog.events}
                onClose={() => setDayDialog({ open: false, date: null, events: [] })}
                onEdit={openEdit}
            />
        </div>
    );
}
