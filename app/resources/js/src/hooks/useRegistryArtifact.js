import { useEffect, useRef, useState } from 'react';
import api, { getApiErrorMessage } from '../api';

// Read recovery for the legacy registry compatibility views. This does not
// create, enable, publish, deploy or qualify any artifact.
export default function useRegistryArtifact(kind, id, { skipSharedVersions = false } = {}) {
    const [env, setEnv] = useState(null);
    const [versions, setVersions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [readError, setReadError] = useState('');
    const [versionsError, setVersionsError] = useState('');
    const [versionsLoading, setVersionsLoading] = useState(false);
    const requestRef = useRef(0);
    const path = `/v1/${kind}-registry/${encodeURIComponent(id)}`;
    const loadVersions = async (request = requestRef.current) => {
        setVersionsLoading(true);
        setVersionsError('');
        try {
            const response = await api.get(`${path}/versions`, { skipErrorToast: true });
            if (request === requestRef.current) setVersions(response.data?.data || []);
        } catch {
            if (request === requestRef.current) setVersionsError('Registry version history could not be loaded.');
        } finally {
            if (request === requestRef.current) setVersionsLoading(false);
        }
    };
    const load = async () => {
        const request = ++requestRef.current;
        setLoading(true);
        setReadError('');
        setVersionsError('');
        try {
            const response = await api.get(path, { skipErrorToast: true });
            if (request !== requestRef.current) return;
            const data = response.data?.data || null;
            setEnv(data);
            if (data && !(skipSharedVersions && data.metadata?.ownership === 'shared')) await loadVersions(request);
            else setVersions([]);
        } catch (error) {
            if (request === requestRef.current) setReadError(getApiErrorMessage(error, `Failed to load ${kind}`));
        } finally {
            if (request === requestRef.current) setLoading(false);
        }
    };
    useEffect(() => {
        setEnv(null);
        setVersions([]);
        load();
        return () => { requestRef.current++; };
    }, [kind, id, skipSharedVersions]);
    return { env, versions, loading, readError, versionsError, versionsLoading, load, loadVersions };
}
