# AP ReleaseTooling v1.0: packaging and release chain

## TL;DR

The release and CI tooling of another app of ours, copied in,
with the app id and version read from `appinfo/info.xml` rather than written
into each file, and without its Cypress parts.

- Packaging: none → `package.sh` and `.nc.publish.ignore`
- Translations: none → `scripts/l10n.py` and `l10n.sh`
- Release: tests only → a version tag runs the tests, then build, sign,
  precheck, store and listed
- Install check: `occ app:install` from a fake store, `check-install.sh`,
  and an `occ` smoke test in place of the Apps page and Cypress
- No other app's name in code or comments

### Blocks

1. `package.sh` and `.nc.publish.ignore`: build and package locally.
2. `scripts/l10n.py` and `scripts/l10n.sh`: a `.pot` template made locally.
3. The `release-meta` action, `tests/e2e/store/appstore.py`, `publish.yml`,
   and `test.yml` started by a version tag with publish as its last job.
4. `install-check.yml`, the `app-store` action and `check-install.sh`,
   with an `occ` smoke test.

## Discussion

- **Run:**
  `PLAN++`: blocks 1 to 4, each committed and pushed to GitLab.
  No tag, no release, no change to the GitHub mirror's settings.
- **Copy first, share later.**
  The composite actions move to a shared repository in a plan of its own,
  once both apps run the same files.
- **Prerequisites that are the user's:**
  the App Store registration of `large_file_previews` with a certificate signed
  by Nextcloud; the mirror's `APPSTORE_KEY`,
  `APPSTORE_CERT` and `APPSTORE_TOKEN`,
  and its `signing` and `appstore` environments with their tag rules.
- **Downloads:**
  anything a script fetches, Nextcloud's translation tool included,
  is checked before it runs, since the connection is metered.

## Analysis

- The source app's chain: `test.yml` on a version tag calls `publish.yml`;
  `build` runs `package.sh` and holds no secret;
  `sign` holds the key and creates the GitHub release from `changelog.sh notes`;
  `precheck` runs `install-check.yml`,
  which installs from a fake store (`appstore.py fake`) by `occ` and from the
  Apps page with Cypress,
  checks the install (`check-install.sh`) and runs an e2e smoke test;
  `store` posts to the App Store with the token;
  `listed` watches the store and its mirrors (`appstore.py watch`).
- Most files differ from what this app needs in the app id only;
  `release-meta` is generic as it stands.
- This app has no Cypress,
  so the Apps-page install and the e2e smoke test have no counterpart;
  an `occ` smoke test takes their place: `lfp:check`,
  and a preview of a PDF made by `occ`.
- Tooling paths are outside `project.shipped`,
  so these commits owe no CHANGELOG bullet;
  `package.sh` and `.nc.publish.ignore` decide what ships,
  and say so in their messages.

## Implementation Plan

1. **Packaging.**
   `package.sh` from the source app,
   its app id and name from `appinfo/info.xml`,
   its keep-staging variable named for no app;
   `.nc.publish.ignore` for this app's tree.
   *Verification:* `bash package.sh` without a key builds
   `build/large_file_previews.tar.gz` and the versioned copy;
   the tarball lists only `appinfo/`, `lib/`, `js/`, `css/`, `img/`,
   `templates/`, `l10n/` where present, `composer.json`, `CHANGELOG.md`,
   `LICENSE`, `README.md` and Composer's production autoloader.
2. **Translations.**
   `scripts/l10n.py` and `l10n.sh`, the app id from the manifest.
   *Verification:* what it downloads checked first;
   a run writes `translationfiles/templates/large_file_previews.pot` holding the
   page's and the setup check's texts.
3. **Release chain.**
   `.github/actions/release-meta`,
   `tests/e2e/store/appstore.py` (app id as a parameter),
   `.github/workflows/publish.yml` with file names from `release-meta`'s
   outputs, and `test.yml` started by version tags,
   with publish as its last job, on a tag only.
   *Verification:* `actionlint` where installed, else a YAML parse;
   `python3 -I tests/e2e/store/appstore.py --help`.
4. **Install check.**
   `.github/workflows/install-check.yml`, `.github/actions/app-store`,
   `tests/e2e/store/check-install.sh`,
   the Apps-page and e2e steps replaced by an `occ` smoke test (`lfp:check`,
   and `occ preview:generate` of a test PDF where available).
   *Verification:* as block 3; the check script with `bash -n`.

## Proposed commit messages

Registration: `[UPDATE] APs: +ReleaseTooling v1.0`, with its record.
Then `[UPDATE]` for each block, as tooling;
each says it owes no CHANGELOG bullet,
its paths being outside `project.shipped`.

## Change History

- v1.0 (2026-10-15): first version.
