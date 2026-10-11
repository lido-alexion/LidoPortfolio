// Match the read-only Investor page rather than the duplicate navigation title.
export function readOnlyReviewHeading(page) {
    return page.locator('.lido-main .container-fluid').getByRole('heading', { name: 'Review', exact: true });
}
