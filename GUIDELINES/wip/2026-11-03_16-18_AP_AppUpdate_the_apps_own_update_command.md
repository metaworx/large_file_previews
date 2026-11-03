# AP AppUpdate v1.0: The app's own update command

## TL;DR

`occ app:update --allow-unstable` cannot reach a nightly:
the server's fetcher caches the stable-filtered list and a `304` from the store
re-filters it.
Each app gets `<prefix>:update`, a thin command over one shipped package,
`mwx/nextcloud-app`, that reads the store first, clears the cache,
and lets the server's own installer download, verify and install.
No signature code of our own; the settings-page section is a later plan.

- `StoreRelease`:
  the store's `platform/<nc>/apps.json` through `IClientService`;
  the installed version's kind,
  the newest stable and the newest unstable release above it
- `AppUpdater`:
  `apps.json` in the `appstore` app-data folder and the fetcher's `lastFailure`
  hold deleted, then `OC\Installer::updateAppstoreApp($id, $allowUnstable)`,
  behind an interface of the package's
- `UpdateCommand`:
  `<prefix>:update [--check] [--stable|--nightly] [--output]`;
  no flag → the installed kind decides; exit 0 updated, 1 nothing to do,
  2 failed
- shipped scoped: Strauss → `lib/Vendor/`, tracked, under the app's namespace
- `OC\Installer`:
  the one internal class; `psalm.xml`, the contract's §3.2 inventory,
  an integration pin
- tests on `mwx/nextcloud-testing` 0.3.0; FCS and FH take it in their sessions

### Blocks

1. The package in mwx-lib: `packages/nextcloud-app`,
   the three classes and the installer seam, its suite, README, changelog,
   the package table; mirror and deploy key; 0.1.0 cut and on Packagist
2. LFP takes it: Strauss into `lib/Vendor/`, `lfp:update`, `appinfo/info.xml`,
   `psalm.xml`, the contract's inventory, the integration pin, README,
   CHANGELOG; CI's drift check
3. Proven on the harness: `occ lfp:update --check` against the store,
   then an update from an older nightly on NC 34; the output and exit codes

## Discussion

### Which release the command takes (the user, 2026-11-03)

- no flag: an installed nightly takes the newest release of any kind above it;
  an installed stable takes the newest stable above it;
- `--nightly`:
  the newest release of any kind above the installed;
- `--stable`:
  the newest stable above the installed.

"Above" is `version_compare`, as the installer's own check,
so nothing is ever downgraded,
and a nightly rebuilt under the installed version number is not an update.
The installed version is a nightly where its string carries a pre-release suffix,
or where the store lists that version as a nightly only; stable otherwise.
"Unstable" is what the server means by it: nightlies and pre-releases alike.

### Decisions (D1 to D4, the TL;DR gate of 2026-11-03)

- **D1-A.**
  The plan lives here, the first consumer's; mwx-lib's commits name it in words.
- **D2-A.**
  No maintenance mode: neither the Apps page nor `occ app:update` sets one,
  and the request finishes on classes already loaded.
- **D3-A.**
  Strauss, a Composer plugin writing PSR-4 into `lib/Vendor/`;
  its dist comes from GitHub, where Composer has stalled on this machine,
  so block 2 may fetch the phar by hand once.
- **D4-A.**
  The platform `apps.json`, once per run:
  the one endpoint that says what the server will offer.

### What stays out

The settings-page section (`UpdateSection` in `@metaworx/nextcloud-vue`,
an OCS endpoint with password confirmation) is the next plan.
The upstream report of the fetcher defect is written beside this plan,
not in it.
FCS's shell fallback stays until FCS adopts the command.

## Analysis

- **The server's path.**
  `OC\Installer::updateAppstoreApp($id, $allowUnstable)` asks
  `isUpdateAvailable($id, $allowUnstable)`,
  which reads `AppFetcher::get($allowUnstable)`, then `downloadApp()`:
  certificate against the root CA and CRL, the tarball's signature,
  the id in `info.xml`, no downgrade, extraction, `IAppManager::upgradeApp()`.
  `AppFetcher::fetch()` keeps one release per app, the highest compatible one,
  with nightlies and pre-releases only under `$allowUnstable` or the daily channel.
- **The cache.**
  `Fetcher::get()` returns the cached `apps.json` within its TTL whatever the
  flag, and after it sends the store the ETag; a `304` re-filters the cached,
  already filtered content,
  so a nightly never appears until the store's ETag changes.
  Deleting the file before the call is the whole workaround;
  a failed fetch also leaves a five-minute hold in
  `settings/appstore-fetcher-lastFailure`, deleted the same way.
- **The store read.**
  `<appstoreurl>/platform/<major.minor.patch>/apps.json`,
  the URL from system config with the server's default,
  the version from `OCP\Util::getVersion()`;
  each app's `releases[]` carries `version`, `isNightly`,
  `rawPlatformVersionSpec`, `rawPhpVersionSpec`.
  The command filters as the fetcher does, minus the channel:
  compatible with this server and PHP, then by kind.
  Some megabytes, once per run.
- **Internals and their seam.**
  `OC\Installer` is not in `nextcloud/ocp`.
  The package declares `Installer` as an interface of its own
  (`isUpdateAvailable()`, `updateAppstoreApp()`),
  and `ServerInstaller` adapts the server's,
  resolved by class name from the container, never constructed.
  The adapter is the one class the unit tests cannot cover;
  the app's integration pin asserts the server class and its two signatures.
  `AppFetcher` is not touched:
  deleting its file is done through `IAppDataFactory::get('appstore')`.
- **Scoping.**
  Strauss copies `vendor/mwx/nextcloud-app/src` to
  `lib/Vendor/mwx/Nextcloud/App/` under
  `OCA\LargeFilePreviews\Vendor\mwx\Nextcloud\App\`,
  which the server's PSR-4 for `lib/` loads without Composer's autoloader at
  runtime.
  Tracked, so a checkout runs without a build;
  CI runs Strauss and fails on a diff.
  Two apps on different versions cannot clash.
- **The command's shape.**
  `UpdateCommand` is abstract, Symfony's `Command`;
  the app's subclass gives the app id, the command name and alias.
  Options: `--check` (report, change nothing),
  `--stable` and `--nightly` (exclusive),
  `--output plain|json|json_pretty` as LFP's `Base` has.
  The report: installed version and kind, newest stable, newest unstable,
  which one the flags choose,
  whether the server would offer it (`appstoreenabled`,
  an apps directory writable).
  Exit codes: 0 updated or, with `--check`, something to take;
  1 nothing to take; 2 a failure.
- **Tests.**
  The package suite on PHPUnit 9.6 with `mwx/nextcloud-testing` 0.3.0:
  `StoreRelease` against a fake client answering a fixture `apps.json`;
  `AppUpdater` against the `Installer` interface mocked, a fake app-data folder,
  `MemoryAppConfig` for the hold;
  `UpdateCommand` through Symfony's `CommandTester` with the three flag cases
  and both kinds.
- **Effort.**
  About two days for the package, one for LFP, half a day on the harness.

## Implementation Plan

### Block 1: The package

1. `packages/nextcloud-app`:
   `composer.json` (`mwx/nextcloud-app`, `mwx\Nextcloud\App\`, `php ^8.2`,
   suggests `nextcloud/ocp`, `symfony/console`), `.gitignore`,
   `CHANGELOG.md` from the partial, `README.md`;
   the root's `autoload-dev` and `phpunit.xml.dist` suite;
   the package table rendered.
2. `Update\StoreRelease`, `Update\Release` (version, kind, ranges),
   `Update\Installer` (interface), `Update\ServerInstaller`,
   `Update\AppUpdater`, `Update\Report`, `Update\UpdateCommand`.
3. Tests as the Analysis lists; a fixture `apps.json` with stable,
   nightly and pre-release entries.
4. The mirror `php-library/nextcloud-app` (glab), the deploy key enabled on it;
   ECS, the suite, `tools/check`.
5. Commit, cut 0.1.0, push; the pipeline splits and tags; Packagist.

**Verification:**
the package suite green, ECS clean, `tools/check` all well;
the mirror's `v0.1.0`; Packagist lists 0.1.0.

### Block 2: LFP takes it

1. `composer.json`:
   `mwx/nextcloud-app ^0.1` and Strauss with its target `lib/Vendor/` and the
   prefix; `composer install`, `lib/Vendor/` tracked;
   `package.sh` unchanged (it ships `lib/`).
2. `lib/Command/Update.php` extending the scoped `UpdateCommand`;
   `appinfo/info.xml`.
3. `psalm.xml`:
   `OC\Installer` suppressed by name with its reason;
   the contract's §3.2 inventory; `tests/Integration/InstallerPinTest.php`.
4. README: the install and update section names `occ lfp:update`; CHANGELOG.
5. CI: a Strauss drift check beside the other lint steps.

**Verification:**
unit and integration suites, ECS, Psalm, `npm run build` untouched,
`tools/check`; CI green.

### Block 3: On the harness

1. `occ lfp:update --check` on the NC 34 instance: the report against the store.
2. An older nightly installed by hand, then `occ lfp:update`:
   the current one in place, exit 0; again: exit 1.
3. `--stable` with no stable above: exit 1 with the reason.

**Verification:**
the three runs' output recorded in a NOTE.

## Proposed commit messages

mwx-lib, naming this plan in words:

- `[TASK] mwx/nextcloud-app: the app's own update command`
- `[RELEASE] packages/nextcloud-app v0.1.0`

This repository:

- `[TASK] lfp:update: the app updates itself from the store, nightlies included`
  (`AP AppUpdate v1.0, Block 2`)
- `[UPDATE] APs: +NOTE AppUpdate v1.0` for block 3's runs,
  then `-AppUpdate` at retirement

## Change History

- v1.0: the plan, from the user's `PLAN++ as recommended` on the TL;DR gate of 2026-11-03,
  with the release-selection rules of the same message; D1-A to D4-A.
