# AP ReleasePath v1.0: Pins, checks, and master

## TL;DR

After the first release: the README's screenshots pinned to the release,
CI running every guideline check,
and a release published from `master` where only its tests needed fixing,
with the tests run first.

- README: relative screenshot links → a `RELEASE-PIN` block at `v0.1.0`, which the cut moves
- lint job: three named checks → `GUIDELINES/shared/tools/check --commit-range <the push's range>`
- `test.yml`: `workflow_dispatch`; on `master` it runs the whole matrix, then publishes
- `release-meta` on `master`: tag `v<manifest version>`, existing, an ancestor of HEAD,
  and HEAD differing from it outside the shipped paths only; else refused
- `CI.md`: tags the default and required; `master` the exception after a tests-only fix;
  a `[RELEASE]` commit on `master` runs no CI, its tag does

### Blocks

1. README screenshots pinned.
2. Lint runs `check` whole.
3. Publishing from `master` after a fix to tests only.

## Discussion

The user, after v0.1.0 was published:

- the README's screenshot links should be pinned to the release, as `info.xml`'s are;
- the checks `CI.md` §4 deferred to the first release can join;
- on the environments allowing `master` beside version tags:
  "tags are the default and required, unless e.g. just the tests need fix.
  THEN a master may do the job. But master does NOT run CI on a [RELEASE]".

The `master` path is the recommended one, accepted with PLAN++:
a dispatched `test.yml` that tests and then publishes,
rather than `publish.yml` dispatched alone, which would test nothing.

## Analysis

- **README.** The five reference definitions `[shot-…]` point at `docs/Screenshots/`.
  The README ships in the package, where a relative link shows nothing.
  `changelog.sh cut` moves every `RELEASE-PIN` block in tracked markdown and XML.
  `tests/e2e/README.md` names the pins of `info.xml` and the README.
  The reflow check warns on two lines of *Configuration* written for the
  shipped levels and the cleared fields; they are rewrapped with it.
- **Checks.** `GUIDELINES/shared/tools/check` runs all ten and passes today,
  warnings only.
  Its commit-message check reads the range it is given:
  `github.event.before..github.sha` on a push,
  the base of a pull request on one,
  nothing on a dispatch; forty zeros, on a new branch, it reports and skips.
- **Publishing from `master`.**
  `release-meta` takes the tag from `GITHUB_REF_NAME`,
  so on `master` it names `master` and refuses.
  On a branch it will take `v<manifest version>` instead,
  require that tag on `origin` and an ancestor of HEAD,
  and refuse where `git diff <tag> HEAD` touches a shipped path
  (`project.shipped` in `GUIDELINES/config.ini`):
  what is built from HEAD is then what the tag holds.
  `test.yml`'s publish job runs on a tag push, as now,
  or on a dispatch on `master`, after lint, PHPUnit and Cypress.
  A `[RELEASE]` push to `master` keeps skipping its tests: the tag runs them.
  The `signing` and `appstore` environments allow `master` already.

## Implementation Plan

### Block 1: README screenshots pinned

1. `README.md`: the five definitions in a `RELEASE-PIN` block, GitLab raw URLs at `v0.1.0`;
   the two long lines rewrapped.
2. `tests/e2e/README.md`: a new image goes into both pin blocks.

**Verification:** `changelog.sh cut 0.2.0` as a dry run names the README's block;
`check` passes.

### Block 2: Lint runs every guideline check

1. `test.yml`: the guideline step runs `check` with the range of the event.
2. `CI.md` §4: what runs, and the range.

**Verification:** `check` locally; `actionlint` where installed; CI on the push.

### Block 3: Publishing from `master`

1. `test.yml`: `workflow_dispatch`; the publish job's condition.
2. `release-meta`: the tag from the manifest on a branch, and the three refusals.
3. `publish.yml`: its header says so.
4. `CI.md` §2, §5 and §6: tags the default, `master` the exception, the `[RELEASE]` skip,
   the environments.

**Verification:** the branch logic of `release-meta` run locally against the
checkout, for HEAD equal to the tag, HEAD past it in tests only, and past it
in a shipped path; CI on the push.

## Proposed commit message

One per block:

- `[TASK] README: the screenshots pinned to the release, as the manifest's are`
- `[UPDATE] CI: every guideline check, commit messages over the push's range`
- `[UPDATE] CI: a release published from master after a fix to its tests only`

## Change History

- v1.0: the plan, from the TL;DR after the release, with PLAN++.
