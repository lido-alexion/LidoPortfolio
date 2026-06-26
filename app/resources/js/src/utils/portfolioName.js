export const PORTFOLIO_NAME_PATTERN = /^[A-Za-z0-9 _-]+$/;

export function validatePortfolioName(name) {
    const trimmed = typeof name === 'string' ? name.trim() : '';

    if (!trimmed) {
        return 'Name is required.';
    }

    if (trimmed.length > 120) {
        return 'Name must be 120 characters or fewer.';
    }

    if (!PORTFOLIO_NAME_PATTERN.test(trimmed)) {
        return 'Use only letters, numbers, spaces, hyphens, and underscores.';
    }

    return null;
}
