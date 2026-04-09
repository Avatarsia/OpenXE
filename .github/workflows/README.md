# Production Build Workflow

Nightly GitHub Actions workflow that rebuilds the `production` branch
from the current `upstream/master` plus all active feature branches
listed in `.github/integration-branches.txt`.

## When it runs

- **Automatically** every night at 04:00 UTC (06:00 CEST / 05:00 CET)
- **On demand** via *Actions tab -> Build Production -> Run workflow*

## What it does

The workflow runs three passes, **all passes are attempted even on failure**,
so a single failing run reports *all* issues instead of stopping at the first
one. This lets you fix multiple conflicts in one go.

### Pass 1 - isolated merge tests

Each integration branch is merged against `upstream/master` alone on a
throwaway branch. This detects conflicts between the branch and the upstream
base (i.e. upstream has moved in a way that no longer matches the branch).

### Pass 2 - full integration build

Only runs if Pass 1 is clean. All integration branches are merged in manifest
order onto a throwaway `production-candidate` branch. This detects
cross-feature conflicts (e.g. branch A and branch B both edit the same file
in incompatible ways, but each is fine against upstream).

### Pass 3 - lint and tests

Only runs if Pass 2 is clean.

- `php -l` on every `.php` file under `classes/Modules/`
- `php tests/lexware_payload_dump.php` if present

### Push

Only runs if all three passes are clean. `production-candidate` is
force-pushed onto `origin/production` via `--force-with-lease`.

### Failure handling

On any failure:

1. A GitHub issue is created with a consolidated list of all problems
   across all passes (conflicts, lint errors, test failures)
2. GitHub sends its default failure notification email to the repo owner
3. The `production` branch is **not** updated - the last known-good
   version stays live

## Editing the manifest

Add or remove branches in `.github/integration-branches.txt`. Lines
starting with `#` and blank lines are ignored. Order matters for Pass 2 -
put foundational branches first.

**The `feature/ci-workflow` branch must stay in the manifest**, otherwise
the workflow files would be lost on the next rebuild (production is built
from upstream, which does not contain `.github/workflows/*`).

To edit the manifest:

1. Check out `feature/ci-workflow`
2. Edit `.github/integration-branches.txt`
3. Commit and push
4. The change takes effect on the next nightly run (or manual trigger)

## Manual trigger

1. Go to the *Actions* tab
2. Pick *Build Production* in the left sidebar
3. Click *Run workflow* (top right)
4. Select branch `production` and click *Run workflow*

## Troubleshooting

### "Manifest contains no branches"

The file exists but has no uncommented non-blank lines. Check
`.github/integration-branches.txt` on `production`.

### "Branch origin/X does not exist on remote"

The manifest lists a branch that has been deleted on the remote. Either
restore the branch or remove it from the manifest on `feature/ci-workflow`.

### Workflow keeps failing on a feature branch

Rebase that feature branch on the current `upstream/master` manually:

```bash
git fetch upstream
git checkout feature/xyz
git rebase upstream/master
# resolve conflicts
git push --force-with-lease
```

Then re-run the workflow.

### "Production build failed" issue without push

Expected: the workflow created the issue *because* it did not push. The
`production` branch still holds the previous successful build. Fix the
listed issues, then re-run.
