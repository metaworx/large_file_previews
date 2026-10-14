# CI Conventions — Large File Previews (v1.2.0)

What CI does in this project, for whoever has to change it.
`/AGENTS.md` names this file as the canonical owner of CI conventions,
so a rule that lives only in a workflow YAML — a version pin,
an ordering constraint, a reason a step exists — belongs here as well, in words.

The pipeline is the source of truth for *what* runs;
this document is the source of truth for *why*,
and for the constraints that a future edit would otherwise break silently.

## Contents

<!-- BEGIN GENERATED CONTENTS - do not edit; run "tools/sync-dev.sh" -->
1. Where CI Is Defined
2. What Runs, and When
3. The Commands Are the Project's Commands
4. Guideline Gates
5. Secrets and Variables
6. Releases
7. Runner Constraints
8. Document Governance
<!-- END GENERATED CONTENTS -->

## 1. Where CI Is Defined

| Platform | Files |
|----------|-------|
| GitHub Actions | `.github/workflows/test.yml`, with the composite actions under `.github/actions/` |

GitHub Actions is the only pipeline.
GitLab (`origin`) is the code host and the issue tracker,
and its push mirror carries every commit and tag
to `github.com/metaworx/large_file_previews`, where the workflow runs.
There is no `.gitlab-ci.yml`: GitLab's minutes are billed,
and GitHub runs the matrix for free.

## 2. What Runs, and When

| Trigger | Jobs |
|---------|------|
| push to any branch, pull request | upstream releases (reports only), lint, PHPUnit |
| tag `vX.Y.Z` or `vX.Y.Z-suffix` | the same |

- **Lint** runs the frontend's ESLint, `vue-tsc`, Stylelint, Vitest and build,
  then the PHP syntax check, ECS, Psalm,
  the manifest against the app store's schema, and the guideline checks of §4.
  Vitest needs no server, so it runs here rather than in a job of its own.
- **PHPUnit** runs both suites, on Nextcloud 33 and 34 with PHP 8.2 to 8.4,
  and on Nextcloud 35 with PHP 8.3 and 8.4;
  Nextcloud 35 refuses PHP 8.2.
- A `[RELEASE]` commit pushed with its tag runs once, on the tag:
  the branch's run of the same commit is skipped.
- Nothing publishes yet.
  Packaging and the app store follow once the app's certificate is issued.

## 3. The Commands Are the Project's Commands

CI runs the commands of the contract's facts table, with these differences:

- **PHPUnit runs inside a server** that `nextcloud/setup-server-action` installs,
  with the app copied into its `apps/` directory,
  rather than through the harness wrapper.
  `E_DEPRECATED` is silenced there, for the server's own deprecations.
- **The tools under `vendor-bin/` are installed one by one**,
  as each job needs them, with `composer install --no-scripts`:
  the post-install hook would install all four.

## 4. Guideline Gates

The lint job runs three of them, by name:

```bash
GUIDELINES/shared/tools/sync-docs.sh --check
GUIDELINES/shared/tools/detect-languages.sh --check
GUIDELINES/shared/tools/check-versions.sh
```

Left out, for now:

- **`changelog.sh check`**, because it reports `appinfo/info.xml`'s version
  as a bump without a cut for as long as nothing has been released.
  It joins the job with the first release.
- **The line-break check** (`reflow.sh`),
  because `GUIDELINES/shared/tools/check` chooses its files and runs no check alone.
  It joins with the changelog check,
  when the first release lets the job run `check` whole.
- **Commit messages**, which need the push's range,
  as `GUIDELINES/shared/tools/check` explains;
  they are checked before each commit instead.

## 5. Secrets and Variables

None yet.
The upstream job reads `github.token`, which GitHub provides.

## 6. Releases

A version tag runs the same jobs as a branch, and publishes nothing yet.

## 7. Runner Constraints

- **`poppler-utils`, `libvips-tools` and `libtiff-tools`
  are installed in the PHPUnit job**:
  the integration tests run `pdftoppm`,
  libvips' `vipsthumbnail`, `vipsheader` and `vips`,
  and `tiffcp` for the one-strip TIFF fallback;
  the image tests are skipped where libvips is not there.
  `timeout`, `prlimit` and `choom` come with the runner's coreutils and util-linux.
- **`nextcloud/setup-server-action` is pinned to a commit**, not a release:
  its newest release, v0.5.0, still pins actions on Node 20.
  The upstream job fails, without failing the run, once a newer release is out,
  and the pin then goes back to a release tag.
- **MariaDB is a service of the job**,
  since a composite action cannot declare one;
  the server action is told `database: mysql`.
- **Node 24 and `npm ci`** come from `.github/actions/node-deps`,
  with npm's cache keyed on the lock file.
- **The lint job checks out the submodule and the whole history**:
  the guideline checks are the submodule's tools,
  and `check-versions.sh` reads each document's revision record
  from the commit that made it, which a shallow clone does not have.

## 8. Document Governance

- This document follows the shared governance rules in `GUIDELINES/shared/GOVERNANCE.md`.
