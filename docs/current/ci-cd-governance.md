# CI/CD governance

## Canonical verification

The repository-level command is:

```bash
./scripts/verify-ci.sh --all
```

It has backend, frontend and journey-only modes. Backend mode uses MySQL and validates migrations, Composer platform requirements, Python adapters, PHPUnit and OpenAPI. Frontend/journey modes install and verify the Playwright Chromium executable before tests.

## Deployment identity

The production workflow packages, deploys, and post-deploy validates the exact GitHub Actions commit SHA. Build metadata and `/api/build-info` must equal that SHA before a release is accepted. Deployment must not be performed as a workaround for a failed verification run.

## GitHub repository policy

Configure `master` with required pull requests, current required CI checks, and no force pushes. Require code-owner review for `.github/workflows/**`, `deploy/**`, `app/database/migrations/**`, `composer.lock`, and `package-lock.json`. Configure the `production` environment with required reviewers and restrict deployment secrets to the deployment job.

Cancelled CI runs caused by a newer push are superseded runs, not product failures. Failed runs require remediation at the original root cause; do not weaken tests or bypass a gate to make the dashboard green.
