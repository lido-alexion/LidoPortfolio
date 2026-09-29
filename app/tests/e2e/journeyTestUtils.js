import { expect } from '@playwright/test';

export const JOURNEY_VIEWPORTS = Object.freeze({
    desktop: { width: 1440, height: 900 },
    mobile: { width: 390, height: 844 },
});

export function journeyId(testInfo, id) {
    testInfo.annotations.push({ type: 'journey', description: id });
    return id;
}

export async function seedDeterministicJourney(page, seed = 'v9-journey-seed') {
    await page.addInitScript((value) => {
        window.__STOX_TEST_SEED__ = value;
    }, seed);
}

export async function expectSafeNavigation(page, route) {
    await expect(page).toHaveURL(new RegExp(`${route.replaceAll('/', '\\/')}(?:[?#]|$)`));
}
