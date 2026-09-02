---
name: commit-push-master
description: >-
  Commit staged work on master and push directly to origin/master with fixed
  git steps. Use when the user says commit, push, commit and push, or save to
  git. Never create feature branches or pull requests.
---

# Commit and push to master

## Hard rules

- Commit **only** on `master`.
- Push **only** to `origin/master`.
- **Never** create a feature branch.
- **Never** create or open a PR (`gh pr create` forbidden).
- **Never** invent alternate workflows.

## Workflow

Copy and track:

```
Progress:
- [ ] 1. Inspect
- [ ] 2. Stage
- [ ] 3. Commit + push via script
- [ ] 4. Verify
```

### 1. Inspect

From repo root, run in parallel:

```powershell
git status
git diff
git diff --cached
git log -5 --oneline
git branch -vv
```

### 2. Stage

- `git checkout master` if not already on `master`.
- Stage product files with `git add`.
- Exclude secrets (`.env`, credentials), debug dumps (`tmp-*.json`, `storage/debug-*`), and one-off scratch scripts unless the user asked to include them.
- If there is nothing to commit, stop and say so. Do not empty-commit.

### 3. Commit + push via script

Draft a short commit message (1–2 sentences, why over what). Then run **exactly**:

```powershell
powershell -ExecutionPolicy Bypass -File .cursor/skills/commit-push-master/scripts/commit-push-master.ps1 -Message @"
<commit message here>
"@
```

Do **not** run ad-hoc `git commit` / `git push` / branch / PR commands instead of this script.

### 4. Verify

Confirm script output shows success and:

```powershell
git status
git branch -vv
```

Upstream must be `origin/master`. Report the commit hash and that `master` was pushed.

## Forbidden

- `git checkout -b …`
- `git push -u origin <feature>`
- `gh pr create`
- Pushing any branch other than `master`
