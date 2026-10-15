import React, { useEffect, useMemo, useState } from 'react';
import api, { getApiErrorMessage } from '../../api';
import ComboChart from './ComboChart';
import { alignComboChartData } from './comboChartData';
import { comboPresetAvailability, DEFAULT_COMBO_PRESET_ID } from './comboChartPresets';

const HISTORY_REQUESTS = [
    ['pe', 'pe', { range: '5y', frequency: 'daily' }],
    ['pb', 'pb', { range: '5y', frequency: 'daily' }],
    ['ps', 'ps', { range: '5y', frequency: 'daily' }],
    ['eps', 'eps', { range: '5y' }],
    ['revenue', 'revenue', { range: '5y' }],
    ['net_profit', 'net_income', { range: '5y' }],
];
const PRESET_HISTORY_KEYS = {
    'price-pe': 'pe', 'price-pb': 'pb', 'price-ps': 'ps',
    'price-eps': 'eps', 'price-revenue': 'revenue', 'price-net-profit': 'net_profit',
};

export default function StockDetailsComboChart({ stockId, prices = [] }) {
    const [series, setSeries] = useState({});
    const [defaultPresetId, setDefaultPresetId] = useState(null);
    const [preferenceLoaded, setPreferenceLoaded] = useState(false);
    const [pendingHistory, setPendingHistory] = useState({});
    const [savingDefault, setSavingDefault] = useState(false);
    const [error, setError] = useState('');
    const [historyError, setHistoryError] = useState('');
    const [preferenceError, setPreferenceError] = useState('');
    const [historyRetry, setHistoryRetry] = useState(0);
    const [preferenceRetry, setPreferenceRetry] = useState(0);

    useEffect(() => {
        if (!stockId) {
            setSeries({});
            return undefined;
        }
        let cancelled = false;
        const pending = Object.fromEntries(HISTORY_REQUESTS.map(([key]) => [key, true]));
        setPendingHistory(pending);
        setSeries(Object.fromEntries(HISTORY_REQUESTS.map(([key]) => [key, { points: [], loading: true }])));
        setHistoryError('');
        HISTORY_REQUESTS.forEach(([key, metric, params]) => {
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/${metric}/history`, { params, skipErrorToast: true })
                .then((response) => {
                    if (cancelled) return;
                    const history = response?.data?.data ?? response?.data ?? null;
                    setSeries((current) => ({ ...current, [key]: history ?? { points: [] } }));
                })
                .catch(() => {
                    if (cancelled) return;
                    setSeries((current) => ({ ...current, [key]: { points: [], unavailableReason: `historical ${key === 'net_profit' ? 'net profit' : key.toUpperCase()} request failed` } }));
                    setHistoryError('Some combo chart history is temporarily unavailable. Unavailable presets remain disabled with a reason.');
                })
                .finally(() => {
                    if (!cancelled) setPendingHistory((current) => ({ ...current, [key]: false }));
                });
        });
        return () => { cancelled = true; };
    }, [stockId, historyRetry]);

    useEffect(() => {
        let cancelled = false;
        setPreferenceLoaded(false);
        setPreferenceError('');
        api.get('/v1/stock-details/combo-chart-preference', { skipErrorToast: true })
            .then((res) => { if (!cancelled) setDefaultPresetId(res?.data?.data?.default_preset_id ?? null); })
            .catch(() => { if (!cancelled) setPreferenceError('Saved combo chart preference could not be loaded.'); })
            .finally(() => { if (!cancelled) setPreferenceLoaded(true); });
        return () => { cancelled = true; };
    }, [preferenceRetry]);

    const availability = useMemo(() => comboPresetAvailability({ prices, series }), [prices, series]);
    const rows = useMemo(() => alignComboChartData({ prices, fundamentals: series }), [prices, series]);
    const loading = Object.values(pendingHistory).some(Boolean);
    const preferredHistoryKey = PRESET_HISTORY_KEYS[defaultPresetId];
    const defaultResolutionPending = Boolean(preferredHistoryKey && pendingHistory[preferredHistoryKey]);
    const saveDefault = async (presetId) => {
        setSavingDefault(true);
        setError('');
        try {
            const res = await api.put('/v1/stock-details/combo-chart-preference', { default_preset_id: presetId }, { skipErrorToast: true });
            setDefaultPresetId(res?.data?.data?.default_preset_id ?? presetId);
            setPreferenceError('');
        } catch (e) {
            setError(getApiErrorMessage(e, 'Could not save the default chart.'));
        } finally {
            setSavingDefault(false);
        }
    };

    return <div className="d-grid gap-2">
        {historyError && <div className="alert alert-warning py-2 small mb-0" role="status">{historyError} <button type="button" className="btn btn-sm btn-outline-secondary" disabled={loading} onClick={() => setHistoryRetry(value => value + 1)}>Retry combo chart history</button></div>}
        {preferenceError && <div className="alert alert-warning py-2 small mb-0" role="status">{preferenceError} <button type="button" className="btn btn-sm btn-outline-secondary" disabled={!preferenceLoaded} onClick={() => setPreferenceRetry(value => value + 1)}>Retry combo chart preference</button></div>}
        {error ? <div className="alert alert-warning py-2 small mb-0" role="status">{error}</div> : null}
        {!preferenceLoaded ? <div className="card"><div className="card-body text-muted small py-3">Loading saved combo chart preference…</div></div> : (
            <ComboChart
                key={stockId}
                rows={rows}
                availability={availability}
                defaultPresetId={preferenceError ? null : defaultPresetId ?? DEFAULT_COMBO_PRESET_ID}
                defaultResolutionPending={defaultResolutionPending}
                onSetDefault={saveDefault}
                savingDefault={savingDefault}
            />
        )}
    </div>;
}
