# Cash Management feature branch — Product Owner CI exception (2026-11-14)

The Product Owner explicitly authorized pushing this feature branch and opening a
GitHub pull request despite the existing failing local Playwright journey gate.
This authorization was given in the StoX Cash Management release conversation
on 2026-11-14, after the specific question about pushing and creating a PR
despite the two documented pre-existing browser failures.

## Reason and limits
- Feature: cash-transaction edit/delete, decimal amounts and live balance preview;
  includes append-only historical balance and reservation safety regression fix.
- The required local browser gate remains red for E2E-49 mobile (byline font
  raster difference) and E2E-06 navigation timing. Both categories were
  independently reproduced on clean origin/master before this feature was merged.
  A timezone-stabilization fix for SCR-06 has passed both viewports.
- Historical native backend full suite was green; a more recent isolated patch
  backend full suite passed 2,609 tests / 18,935 assertions before three
  additional focused regression cases, which then passed targeted verification.
  Final integrated-branch verification must be reported separately.
- This exception authorizes **feature-branch push and draft PR creation only**,
  not greenwashing failed checks, weakening tests, merging into master, or
  deploying to production. Required GitHub status checks and production deployment
  protections still apply. No manual deployment bypass is authorized.

Record actual rerun results and linked GitHub CI checks in the PR before
requesting promotion.
