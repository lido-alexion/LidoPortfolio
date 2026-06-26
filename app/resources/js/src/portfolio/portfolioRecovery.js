let recoverActivePortfolio = null;

export function registerPortfolioRecovery(fn) {
    recoverActivePortfolio = fn;
}

export async function recoverStaleActivePortfolio() {
    if (typeof recoverActivePortfolio !== 'function') {
        return false;
    }
    await recoverActivePortfolio();
    return true;
}

export function isPortfolioNotFoundError(error) {
    const status = error?.response?.status;
    if (status !== 404) {
        return false;
    }
    const message = (error?.response?.data?.message || '').trim();
    return message === 'Portfolio not found.';
}
