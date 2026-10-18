# CI Conventions — Large File Previews (v1.8.0)

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
| GitHub Actions | `.github/workflows/test.yml`, which calls `publish.yml` on a tag, which calls `install-check.yml`; the composite actions under `.github/actions/` |

The composite actions, `publish.yml`, `install-check.yml`, `package.sh`,
the translation scripts and the fake store are **vendored copies** of the
guidelines' `platform/nextcloud/` files, taken by `sync-vendor.sh` as
`GUIDELINES/config.ini` names them under `[vendor]` and recorded in
`GUIDELINES/vendor.sources`; `tools/check` fails on a copy edited here.
An edit goes to the guidelines and comes back with the next pin.
What is this app's own is `.github/app.env`, which `install-check.yml` reads,
and `tests/e2e/store/checks.sh`, which `check-install.sh` sources;
`test.yml` is the app's, and calls the vendored actions.

GitHub Actions is the only pipeline.
GitLab (`origin`) is the code host and the issue tracker,
and its push mirror carries every commit and tag
to `github.com/metaworx/large_file_previews`, where the workflow runs.
There is no `.gitlab-ci.yml`: GitLab's minutes are billed,
and GitHub runs the matrix for free.

## 2. What Runs, and When

| Trigger | Jobs |
|---------|------|
| push to any branch, pull request | upstream releases (reports only), lint, PHPUnit, Cypress |
| tag `vX.Y.Z` or `vX.Y.Z-suffix` | the same, then publish (§6) |
| by hand, `test.yml` on master | the same, then publish the manifest's release, where its tag's tests failed (§6) |
| by hand, `install-check.yml` | the install check of a release that is out |

- **Lint** runs the frontend's ESLint, `vue-tsc`, Stylelint, Vitest and build,
  then the PHP syntax check, ECS, Psalm,
  the manifest against the app store's schema, the guideline checks of §4,
  and `scripts/l10n.sh check`:
  the template matches the texts, every language is complete,
  and `l10n/` is what the `.po` files build.
  Vitest needs no server, so it runs here rather than in a job of its own.
- **PHPUnit** runs both suites, on Nextcloud 33 and 34 with PHP 8.2 to 8.4,
  and on Nextcloud 35 with PHP 8.3 and 8.4;
  Nextcloud 35 refuses PHP 8.2.
- **Cypress** runs the browser tests of `tests/e2e/` on Nextcloud 33 to 35,
  against the server PHP's own web server serves,
  with the frontend built into the app,
  the server set up for them (English, no first-run wizard, jobs on cron),
  and the screenshots of a failed run kept as an artifact.
- A `[RELEASE]` commit pushed with its tag runs once, on the tag:
  the branch's run of the same commit is skipped,
  so master runs no CI on a `[RELEASE]` of its own.
- A version tag's run publishes once lint, PHPUnit and Cypress pass (§6).

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

The lint job runs all of them, as one command,
with the commit messages over what the event brought:

```bash
GUIDELINES/shared/tools/check --commit-range "$FROM..$TO"
```

- **A push** hands it `github.event.before..github.sha`, its new commits.
- **A pull request** hands it its base and its head,
  not the merge commit GitHub makes of them, whose message is GitHub's.
- **A tag, a new branch or a dispatch** has no previous commit:
  `before` is forty zeros, or empty,
  and the commit-message check says so and reads nothing.
  Every other check still runs.

A warning, such as a line the line-break check would rewrap, does not fail the job;
a finding does.
`GUIDELINES/shared/tools/check --list` names each check and its fix.

## 5. Secrets and Variables

The release jobs need these on the GitHub mirror,
each in the environment of the job that uses it:

| Name | Kind | Environment | Used by |
|------|------|-------------|---------|
| `APPSTORE_KEY` | secret | `signing` | `sign`: signs the tarball |
| `APPSTORE_CERT` | variable or secret | `signing`, `appstore`, and the repository for `precheck` | verifying the signature; listing the app in the fake store before its first release |
| `APPSTORE_TOKEN` | secret | `appstore` | `store`: posts the release |
| `RELEASE_NOTIFY` | variable, optional | repository | `listed`: whom the comment on the release commit mentions; the tag's pusher otherwise |

The `signing` and `appstore` environments allow version tags,
and master for the one case of §6 where a release is published from it;
`appstore` waits for a maintainer's approval besides.
The upstream job reads `github.token`, which GitHub provides.

## 6. Releases

A version tag whose lint, PHPUnit and Cypress jobs pass runs `publish.yml`:

1. **build**
   runs `package.sh`, which builds the tarballs, with no secret;
   `release-meta` refuses a tag that does not name the manifest's version,
   or whose commit is not on master,
   and makes a 0.x version nightly.
2. **sign**
   signs with `APPSTORE_KEY`,
   and makes the GitHub release, its notes the CHANGELOG section.
3. **precheck**
   runs `install-check.yml`,
   which installs the signed release on Nextcloud 33 to 35 the two ways
   an administrator can, by `occ app:install`
   and through the Apps page (`tests/e2e/store/install-from-apps-page.cy.js`),
   from a fake store that lists it (`tests/e2e/store/appstore.py fake`);
   `tests/e2e/store/check-install.sh` checks the install
   and runs this app's checks from `tests/e2e/store/checks.sh`,
   a PDF's preview rendered through it among them,
   and the browser tests `.github/app.env` names run against the installed copy,
   on a runner with the packages it lists.
   Before the first release the fake store lists the app
   from its manifest and certificate.
   A nightly installed through the Apps page puts the server on the `daily` channel,
   the one on which the Apps page lists nightlies.
4. **store**
   posts the release to the App Store with `APPSTORE_TOKEN`,
   after verifying its signature.
5. **listed**
   watches the store, and every host it redirects to,
   until they list the upload (`appstore.py watch`).

A tag is how a release is published, and the only way where its code changes.
**Where a tag's tests failed and only the tests needed fixing**,
master fixes them, and `gh workflow run test.yml --ref master`
tests master and then publishes the release the manifest names.
`release-meta` takes its tag from the manifest's version,
and refuses unless that tag exists below master's head
and master differs from it only where neither the package nor its build reads:
tests, docs, CI, the guidelines and the lint and test configurations.
What is built from master is then what the tag holds.

## 7. Runner Constraints

- **`poppler-utils`, `libvips-tools` and `libtiff-tools`
  are installed in the PHPUnit and Cypress jobs**:
  the integration tests run `pdftoppm`,
  libvips' `vipsthumbnail`, `vipsheader` and `vips`,
  and `tiffcp` for the one-strip TIFF fallback;
  the image tests are skipped where libvips is not there.
  On the settings page a type whose renderer is missing cannot be changed,
  and the browser tests change some.
  `timeout`, `prlimit` and `choom` come with the runner's coreutils and util-linux.
- **`gettext` is installed in the lint job**, for `scripts/l10n.sh check`,
  which runs PHP for Nextcloud's translation tool and gettext for the rest.
- **`nextcloud/setup-server-action` is pinned to a commit**, not a release:
  its newest release, v0.5.0, still pins actions on Node 20.
  The upstream job fails, without failing the run, once a newer release is out,
  and the pin then goes back to a release tag.
- **MariaDB is a service of the job**,
  since a composite action cannot declare one,
  declared once in `test.yml` and shared by an anchor;
  the server action is told `database: mysql`.
- **Node 24 and `npm ci`** come from `.github/actions/node-deps`,
  with npm's cache keyed on the lock file,
  and without Cypress's binary (`CYPRESS_INSTALL_BINARY=0`), about 240 MB:
  `.github/actions/cypress-run` restores it from GitHub's cache,
  keyed on the pinned version,
  and downloads it from Cypress's server only where the cache has none yet,
  once per version rather than once per job.
  A download cut off midway failed a run before the cache.
- **Cypress is pinned to an exact version** in `package.json`,
  so that a developer's cached binary stays the one used.
- **The lint job checks out the submodule and the whole history**:
  the guideline checks are the submodule's tools,
  and `check-versions.sh` reads each document's revision record
  from the commit that made it, which a shallow clone does not have.

## 8. Document Governance

- This document follows the shared governance rules in `GUIDELINES/shared/GOVERNANCE.md`.
