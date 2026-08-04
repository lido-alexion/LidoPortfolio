import React, { useEffect, useMemo, useState } from 'react';
import {
    CALENDAR_COLOR_PRESETS,
    CALENDAR_EVENT_PRESETS,
    MONTH_OPTIONS,
    RECURRENCE_TYPES,
    TRADE_HOLIDAY_CATEGORY,
    TRADE_HOLIDAY_COLOR,
    WEEKDAY_OPTIONS,
    WEEK_OF_MONTH_OPTIONS,
    defaultEventForm,
    eventToForm,
} from '../../utils/calendarEvents';

function ReminderDaysInput({ value, onChange }) {
    const [customDays, setCustomDays] = useState('');

    useEffect(() => {
        const extras = (value ?? []).filter((d) => d !== 0 && d !== 1 && d !== 3 && d !== 7);
        setCustomDays(extras.join(', '));
    }, [value]);

    const togglePreset = (days) => {
        const set = new Set(value ?? []);
        if (set.has(days)) {
            set.delete(days);
        } else {
            set.add(days);
        }
        const next = Array.from(set).sort((a, b) => a - b);
        onChange(next.length ? next : [0]);
    };

    const applyCustom = () => {
        const parsed = customDays
            .split(/[,\s]+/)
            .map((part) => parseInt(part.trim(), 10))
            .filter((n) => Number.isFinite(n) && n >= 0 && n <= 365);
        const merged = Array.from(new Set([...(value ?? []), ...parsed])).sort((a, b) => a - b);
        onChange(merged.length ? merged : [0]);
    };

    return (
        <div className="calendar-reminder-days">
            <div className="d-flex flex-wrap gap-2 mb-2">
                {[
                    { days: 0, label: 'On the day' },
                    { days: 1, label: '1 day before' },
                    { days: 3, label: '3 days before' },
                    { days: 7, label: '7 days before' },
                ].map(({ days, label }) => (
                    <button
                        key={days}
                        type="button"
                        className={`btn btn-sm ${(value ?? []).includes(days) ? 'btn-primary' : 'btn-outline-secondary'}`}
                        onClick={() => togglePreset(days)}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <div className="input-group input-group-sm">
                <span className="input-group-text">Custom days</span>
                <input
                    type="text"
                    className="form-control"
                    placeholder="e.g. 2, 5, 14"
                    value={customDays}
                    onChange={(e) => setCustomDays(e.target.value)}
                    onBlur={applyCustom}
                />
            </div>
            <div className="small text-muted mt-1">
                Selected: {(value ?? []).map((d) => (d === 0 ? 'on day' : `${d}d before`)).join(', ') || 'none'}
            </div>
        </div>
    );
}

export default function CalendarEventFormDialog({
    open,
    event,
    saving = false,
    isAdmin = false,
    onClose,
    onSave,
    onDelete,
}) {
    const [form, setForm] = useState(defaultEventForm());

    useEffect(() => {
        if (open) {
            setForm(eventToForm(event));
        }
    }, [open, event]);

    const isTradeHoliday = form.category === TRADE_HOLIDAY_CATEGORY
        || Boolean(event?.is_trade_holiday);
    const readOnlyGlobal = Boolean(event?.is_trade_holiday) && !isAdmin;

    const showWeekday = ['weekly', 'monthly_weekday', 'yearly_weekday'].includes(form.recurrence_type);
    const showMonthDay = ['monthly_day', 'yearly_day'].includes(form.recurrence_type);
    const showWeekOfMonth = ['monthly_weekday', 'yearly_weekday'].includes(form.recurrence_type);
    const showMonth = ['yearly_day', 'yearly_weekday'].includes(form.recurrence_type);

    const title = useMemo(() => {
        if (event?.is_trade_holiday) {
            return isAdmin ? 'Edit trade holiday' : 'Trade holiday';
        }
        return event?.id ? 'Edit event' : 'New event';
    }, [event, isAdmin]);

    if (!open) {
        return null;
    }

    const update = (patch) => setForm((prev) => ({ ...prev, ...patch }));
    const updateConfig = (patch) => setForm((prev) => ({
        ...prev,
        recurrence_config: { ...prev.recurrence_config, ...patch },
    }));

    const setTradeHoliday = (enabled) => {
        if (!isAdmin) {
            return;
        }
        if (enabled) {
            update({
                category: TRADE_HOLIDAY_CATEGORY,
                color: TRADE_HOLIDAY_COLOR,
                reminder_enabled: false,
                reminder_days_before: [],
            });
        } else {
            update({
                category: null,
                color: form.color === TRADE_HOLIDAY_COLOR ? '#6366f1' : form.color,
            });
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (readOnlyGlobal) {
            return;
        }
        onSave({
            ...form,
            category: isTradeHoliday ? TRADE_HOLIDAY_CATEGORY : null,
            color: isTradeHoliday ? TRADE_HOLIDAY_COLOR : form.color,
            recurrence_end_date: form.recurrence_end_date || null,
            reminder_days_before: form.reminder_enabled ? form.reminder_days_before : [],
        });
    };

    return (
        <>
            <div className="modal show d-block" tabIndex={-1} role="dialog" aria-modal="true">
                <div className="modal-dialog modal-lg modal-dialog-scrollable">
                    <form className="modal-content" onSubmit={handleSubmit}>
                        <div className="modal-header">
                            <h5 className="modal-title">{title}</h5>
                            <button type="button" className="btn-close" aria-label="Close" onClick={onClose} />
                        </div>
                        <div className="modal-body">
                            {readOnlyGlobal ? (
                                <div className="alert alert-secondary py-2 small">
                                    This is a global trade holiday (visible to everyone). Only an admin can edit it.
                                </div>
                            ) : null}

                            {isAdmin && !readOnlyGlobal ? (
                                <div className="form-check mb-3">
                                    <input
                                        className="form-check-input"
                                        type="checkbox"
                                        id="calendar-trade-holiday"
                                        checked={isTradeHoliday}
                                        onChange={(e) => setTradeHoliday(e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="calendar-trade-holiday">
                                        Trade holiday (global — shown on every portfolio calendar; skips trade-alert Telegram)
                                    </label>
                                </div>
                            ) : null}

                            {!event?.id && !isTradeHoliday && (
                                <div className="mb-3">
                                    <div className="small text-muted mb-2">Quick presets</div>
                                    <div className="d-flex flex-wrap gap-2">
                                        {CALENDAR_EVENT_PRESETS.map((preset) => (
                                            <button
                                                key={preset.label}
                                                type="button"
                                                className="btn btn-sm btn-outline-secondary"
                                                onClick={() => setForm((prev) => ({
                                                    ...prev,
                                                    title: preset.title,
                                                    color: preset.color,
                                                    category: null,
                                                    recurrence_type: preset.recurrence_type,
                                                    recurrence_config: {
                                                        ...prev.recurrence_config,
                                                        ...preset.recurrence_config,
                                                    },
                                                }))}
                                            >
                                                {preset.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div className="mb-3">
                                <label className="form-label" htmlFor="calendar-event-title">Title</label>
                                <input
                                    id="calendar-event-title"
                                    type="text"
                                    className="form-control"
                                    value={form.title}
                                    onChange={(e) => update({ title: e.target.value })}
                                    required
                                    maxLength={200}
                                    disabled={readOnlyGlobal}
                                />
                            </div>

                            <fieldset disabled={readOnlyGlobal} className="border-0 p-0 m-0">
                            <div className="mb-3">
                                <label className="form-label" htmlFor="calendar-event-desc">Description</label>
                                <textarea
                                    id="calendar-event-desc"
                                    className="form-control"
                                    rows={2}
                                    value={form.description}
                                    onChange={(e) => update({ description: e.target.value })}
                                />
                            </div>

                            <div className="row g-3 mb-3">
                                <div className="col-md-6">
                                    <label className="form-label" htmlFor="calendar-event-anchor">Anchor date</label>
                                    <input
                                        id="calendar-event-anchor"
                                        type="date"
                                        className="form-control"
                                        value={form.anchor_date}
                                        onChange={(e) => update({ anchor_date: e.target.value })}
                                        required
                                    />
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label" htmlFor="calendar-event-repeat">Repeat</label>
                                    <select
                                        id="calendar-event-repeat"
                                        className="form-select"
                                        value={form.recurrence_type}
                                        onChange={(e) => update({ recurrence_type: e.target.value })}
                                    >
                                        {Object.entries(RECURRENCE_TYPES).map(([value, label]) => (
                                            <option key={value} value={value}>{label}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            {form.recurrence_type !== 'none' && (
                                <div className="row g-3 mb-3">
                                    {showMonth && (
                                        <div className="col-md-4">
                                            <label className="form-label">Month</label>
                                            <select
                                                className="form-select"
                                                value={form.recurrence_config.month ?? 1}
                                                onChange={(e) => updateConfig({ month: Number(e.target.value) })}
                                            >
                                                {MONTH_OPTIONS.map((opt) => (
                                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                    )}
                                    {showMonthDay && (
                                        <div className="col-md-4">
                                            <label className="form-label">Day of month</label>
                                            <input
                                                type="number"
                                                min={1}
                                                max={31}
                                                className="form-control"
                                                value={form.recurrence_config.month_day ?? 1}
                                                onChange={(e) => updateConfig({ month_day: Number(e.target.value) })}
                                            />
                                        </div>
                                    )}
                                    {showWeekOfMonth && (
                                        <div className="col-md-4">
                                            <label className="form-label">Week of month</label>
                                            <select
                                                className="form-select"
                                                value={form.recurrence_config.week_of_month ?? 1}
                                                onChange={(e) => updateConfig({ week_of_month: Number(e.target.value) })}
                                            >
                                                {WEEK_OF_MONTH_OPTIONS.map((opt) => (
                                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                    )}
                                    {showWeekday && (
                                        <div className="col-md-4">
                                            <label className="form-label">Day of week</label>
                                            <select
                                                className="form-select"
                                                value={form.recurrence_config.weekday ?? 1}
                                                onChange={(e) => updateConfig({ weekday: Number(e.target.value) })}
                                            >
                                                {WEEKDAY_OPTIONS.map((opt) => (
                                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                    )}
                                    <div className="col-md-4">
                                        <label className="form-label">Repeat until (optional)</label>
                                        <input
                                            type="date"
                                            className="form-control"
                                            value={form.recurrence_end_date}
                                            onChange={(e) => update({ recurrence_end_date: e.target.value })}
                                        />
                                    </div>
                                </div>
                            )}

                            {!isTradeHoliday ? (
                                <div className="mb-3">
                                    <label className="form-label">Color</label>
                                    <div className="d-flex flex-wrap align-items-center gap-2">
                                        {CALENDAR_COLOR_PRESETS.map((color) => (
                                            <button
                                                key={color}
                                                type="button"
                                                className={`calendar-color-swatch${form.color === color ? ' is-selected' : ''}`}
                                                style={{ backgroundColor: color }}
                                                aria-label={`Color ${color}`}
                                                onClick={() => update({ color })}
                                            />
                                        ))}
                                        <input
                                            type="color"
                                            className="form-control form-control-color calendar-color-input"
                                            value={form.color}
                                            onChange={(e) => update({ color: e.target.value })}
                                            title="Pick custom color"
                                        />
                                    </div>
                                </div>
                            ) : (
                                <div className="mb-3 small text-muted">
                                    Trade holidays use a fixed amber marker on everyone’s calendar.
                                    <span
                                        className="calendar-event-dot ms-2 align-middle"
                                        style={{ backgroundColor: TRADE_HOLIDAY_COLOR }}
                                        aria-hidden="true"
                                    />
                                </div>
                            )}

                            {!isTradeHoliday ? (
                                <div className="mb-3">
                                    <div className="form-check form-switch">
                                        <input
                                            className="form-check-input"
                                            type="checkbox"
                                            id="calendar-reminder-enabled"
                                            checked={form.reminder_enabled}
                                            onChange={(e) => update({ reminder_enabled: e.target.checked })}
                                        />
                                        <label className="form-check-label" htmlFor="calendar-reminder-enabled">
                                            Telegram reminder
                                        </label>
                                    </div>
                                    <div className="small text-muted mb-2">
                                        Uses Telegram settings for the active portfolio (Settings → Portfolio).
                                    </div>
                                    {form.reminder_enabled && (
                                        <ReminderDaysInput
                                            value={form.reminder_days_before}
                                            onChange={(reminder_days_before) => update({ reminder_days_before })}
                                        />
                                    )}
                                </div>
                            ) : null}
                            </fieldset>
                        </div>
                        <div className="modal-footer justify-content-between">
                            <div>
                                {event?.id && onDelete && (!event.is_trade_holiday || isAdmin) ? (
                                    <button
                                        type="button"
                                        className="btn btn-outline-danger"
                                        onClick={() => onDelete(event)}
                                        disabled={saving || readOnlyGlobal}
                                    >
                                        Delete
                                    </button>
                                ) : null}
                            </div>
                            <div className="d-flex gap-2">
                                <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={saving}>
                                    Cancel
                                </button>
                                {!readOnlyGlobal ? (
                                    <button type="submit" className="btn btn-primary" disabled={saving || !form.title.trim()}>
                                        {saving ? 'Saving…' : 'Save'}
                                    </button>
                                ) : null}
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div className="modal-backdrop show" onClick={onClose} aria-hidden="true" />
        </>
    );
}
