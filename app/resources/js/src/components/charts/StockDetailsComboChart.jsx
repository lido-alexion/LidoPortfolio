import React, { useEffect, useMemo, useState } from 'react';
import api from '../../api';
import ComboChart from './ComboChart';
import { alignComboChartData } from './comboChartData';
import { comboPresetAvailability, DEFAULT_COMBO_PRESET_ID } from './comboChartPresets';

export default function StockDetailsComboChart({ stockId, prices = [] }) {
    const [series, setSeries] = useState({});
    const [defaultPresetId, setDefaultPresetId] = useState(null);
    const [preferenceLoaded, setPreferenceLoaded] = useState(false);
    const [loading, setLoading] = useState(false);
    const [savingDefault, setSavingDefault] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!stockId) {
            setSeries({});
            return undefined;
        }
        let cancelled = false;
        setLoading(true);
        setError('');
        Promise.allSettled([
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/pe/history`, { params: { range: '5y', frequency: 'daily' }, skipErrorToast: true }),
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/pb/history`, { params: { range: '5y', frequency: 'daily' }, skipErrorToast: true }),
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/ps/history`, { params: { range: '5y', frequency: 'daily' }, skipErrorToast: true }),
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/eps/history`, { params: { range: '5y' }, skipErrorToast: true }),
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/revenue/history`, { params: { range: '5y' }, skipErrorToast: true }),
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/net_income/history`, { params: { range: '5y' }, skipErrorToast: true }),
        ]).then((results) => {
            if (cancelled) return;
            const keys = ['pe', 'pb', 'ps', 'eps', 'revenue', 'net_profit'];
            const next = Object.fromEntries(results.map((result, index) => [keys[index], result.status === 'fulfilled'
                ? (result.value?.data?.data ?? result.value?.data ?? null)
                : { points: [], unavailableReason: `historical ${keys[index] === 'net_profit' ? 'net profit' : keys[index].toUpperCase()} request failed` }]));
            setSeries(next);
            if (results.some((result) => result.status === 'rejected')) setError('Some combo chart history is temporarily unavailable. Unavailable presets remain disabled with a reason.');
        }).finally(() => { if (!cancelled) setLoading(false); });
        return () => { cancelled = true; };
    }, [stockId]);

    useEffect(() => {
        let cancelled = false;
        api.get('/v1/stock-details/combo-chart-preference', { skipErrorToast: true })
            .then((res) => { if (!cancelled) setDefaultPresetId(res?.data?.data?.default_preset_id ?? null); })
            .catch(() => { if (!cancelled) setDefaultPresetId(null); })
            .finally(() => { if (!cancelled) setPreferenceLoaded(true); });
        return () => { cancelled = true; };
    }, []);

    const availability = useMemo(() => comboPresetAvailability({ prices, series }), [prices, series]);
    const rows = useMemo(() => alignComboChartData({ prices, fundamentals: series }), [prices, series]);
    const saveDefault = async (presetId) => {
        setSavingDefault(true);
        setError('');
        try {
            const res = await api.put('/v1/stock-details/combo-chart-preference', { default_preset_id: presetId }, { skipErrorToast: true });
            setDefaultPresetId(res?.data?.data?.default_preset_id ?? presetId);
        } catch (e) {
            setError(e?.response?.data?.error?.message || 'Could not save the default chart.');
        } finally {
            setSavingDefault(false);
        }
    };

    return <div className="d-grid gap-2">
        {error ? <div className="alert alert-warning py-2 small mb-0" role="status">{error}</div> : null}
        {loading || !preferenceLoaded ? <div className="card"><div className="card-body text-muted small py-3">Loading combo chart history…</div></div> : (
            <ComboChart
                key={stockId}
                rows={rows}
                availability={availability}
                defaultPresetId={defaultPresetId ?? DEFAULT_COMBO_PRESET_ID}
                onSetDefault={saveDefault}
                savingDefault={savingDefault}
            />
        )}
    </div>;
}
