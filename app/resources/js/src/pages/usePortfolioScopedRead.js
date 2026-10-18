import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { getApiErrorMessage } from '../api';
import { getActivePortfolioId } from '../portfolio/activePortfolioStorage';
import { showToast } from '../toast';

/** Page-local read state for portfolio reports; intentionally not a shared API hook. */
export default function usePortfolioScopedRead({ identity, profileId, enabled, request, validate, errorFallback }) {
    const [lastConfirmed, setLastConfirmed] = useState(null);
    const [readState, setReadState] = useState(null);
    const mountedRef = useRef(false);
    const generationRef = useRef(0);
    const epochRef = useRef(0);
    const queryRef = useRef(null);
    const inFlightRef = useRef(null);

    useLayoutEffect(() => {
        const previous = queryRef.current;
        if (!previous || previous.identity !== identity || previous.profileId !== String(profileId ?? '') || previous.enabled !== enabled) {
            epochRef.current += 1;
        }
        queryRef.current = {
            identity,
            profileId: String(profileId ?? ''),
            enabled,
            request,
            validate,
            errorFallback,
            epoch: epochRef.current,
        };
    }, [identity, profileId, enabled, request, validate, errorFallback]);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
        };
    }, []);

    const reload = useCallback(() => {
        const query = queryRef.current;
        if (!query?.enabled || !query.profileId) {
            if (query) setReadState({ identity: query.identity, status: 'idle', error: null });
            return Promise.resolve(null);
        }
        if (getActivePortfolioId() !== query.profileId) {
            setReadState({ identity: query.identity, status: 'waiting', error: null });
            return Promise.resolve(null);
        }

        const active = inFlightRef.current;
        if (active?.identity === query.identity && active.epoch === query.epoch) {
            return active.promise;
        }

        const generation = ++generationRef.current;
        const isCurrent = () => mountedRef.current
            && generationRef.current === generation
            && queryRef.current?.identity === query.identity
            && queryRef.current?.epoch === query.epoch
            && getActivePortfolioId() === query.profileId;
        setReadState({ identity: query.identity, status: 'loading', error: null });

        const promise = Promise.resolve()
            .then(() => query.request())
            .then((result) => {
                const value = query.validate(result);
                if (isCurrent()) {
                    setLastConfirmed({ identity: query.identity, value });
                    setReadState({ identity: query.identity, status: 'confirmed', error: null });
                }
                return value;
            })
            .catch((error) => {
                if (isCurrent()) {
                    setReadState({ identity: query.identity, status: 'unavailable', error });
                    showToast(getApiErrorMessage(error, query.errorFallback), 'danger');
                }
                return null;
            })
            .finally(() => {
                if (inFlightRef.current?.generation === generation) {
                    inFlightRef.current = null;
                }
            });

        inFlightRef.current = { identity: query.identity, epoch: query.epoch, generation, promise };
        return promise;
    }, []);

    useEffect(() => {
        void reload();
    }, [identity, enabled, reload]);

    const scopeMatches = Boolean(profileId)
        && getActivePortfolioId() === String(profileId);
    const current = Boolean(enabled && profileId) && scopeMatches;
    const data = current && lastConfirmed?.identity === identity ? lastConfirmed.value : null;
    const state = readState?.identity === identity ? readState : null;
    const stateMatches = state !== null;
    const waiting = !profileId || !scopeMatches || state?.status === 'waiting';
    const loading = Boolean(current && (stateMatches ? state.status === 'loading' : data == null));
    const error = state?.status === 'unavailable' ? state.error : null;
    const stale = data !== null && (!stateMatches || loading || error !== null);

    return {
        data,
        confirmed: data !== null,
        loading,
        error,
        stale,
        waiting,
        reload,
    };
}
