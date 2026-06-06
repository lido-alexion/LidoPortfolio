/**
 * Returns a debounced function that delays invoking fn until after wait ms.
 */
export function debounce(fn, wait = 300) {
    let timeoutId = null;

    const debounced = (...args) => {
        if (timeoutId) {
            clearTimeout(timeoutId);
        }
        timeoutId = setTimeout(() => {
            timeoutId = null;
            fn(...args);
        }, wait);
    };

    debounced.cancel = () => {
        if (timeoutId) {
            clearTimeout(timeoutId);
            timeoutId = null;
        }
    };

    return debounced;
}

export default debounce;
