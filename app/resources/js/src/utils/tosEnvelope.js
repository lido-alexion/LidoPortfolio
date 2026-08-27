/**
 * Unwrap Trading OS ApiEnvelope responses from axios.
 * `{ success, data, meta }` lives on `response.data`.
 */
export function tosData(response) {
    return response?.data?.data ?? null;
}

export function tosList(response) {
    const payload = response?.data?.data;
    return Array.isArray(payload) ? payload : [];
}

export function tosMeta(response) {
    const meta = response?.data?.meta;
    return meta && typeof meta === 'object' ? meta : {};
}
