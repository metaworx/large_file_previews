# NOTE AppUpdate v1.0: the runs on the harness

Block 3's runs, 2026-11-03, and where they went differently from the plan.

## Where

Not on `34-lfp`: the app there is this checkout, bind-mounted,
and the installer replaces the app's directory,
which would have replaced the checkout.
A second Nextcloud 34 (`34-upd`, 34.0.5, PHP 8.2, the harness test app mounted,
`appstoreenabled` set to true) took the app from `package.sh`'s tarball,
extracted into `custom_apps/`, the one writable apps directory, and enabled.
On `34-lfp`, `occ upgrade` cleared the pending app upgrade first,
and `occ lfp:update --check` there answers exit 2 with the reason:
the harness disables the app store.

## The runs (`occ lfp:update …` on `34-upd`)

1. **Installed 0.3.0, `--check`:**
   installed `0.3.0 (nightly)`, the kind read from the store,
   which lists 0.3.0 as a nightly only; newest stable and unstable `none`;
   channel `nightly (the installed kind)`;
   outcome `nothing to take: no compatible release above 0.3.0`; exit 1.
2. **Installed 0.3.0, `--stable`:**
   channel `stable (the flag)`;
   `nothing to take: no compatible stable release above 0.3.0`; exit 1.
   `--check --output json` prints the same as one object, `"exitCode":1`.
3. **Installed 0.2.9** (the tarball's `info.xml` set to 0.2.9
   and `installed_version` to match,
   since a lower `info.xml` alone makes the server demand an upgrade and occ
   refuse the command), no flag: installed `0.2.9 (stable)`, not in the store;
   newest unstable `0.3.0 (nightly)`; channel `stable (the installed kind)`;
   `nothing to take`; exit 1.
4. **Installed 0.2.9, `--nightly --check`:**
   chosen `0.3.0 (nightly)`, server offers `0.3.0`,
   `would update to 0.3.0 (--check: nothing changed)`; exit 0.
5. **Installed 0.2.9, `--nightly`, with 0.1.0 of the package:**
   the server installed the store's 0.3.0,
   and the command then died on `Outcome` not found:
   the installer had replaced the app's directory,
   the store's copy carries no `lib/Vendor/`,
   and the class had not been loaded yet.
   Exit 1 after a successful update.
6. **The same with 0.1.1**,
   which preloads the package's classes ahead of the installer:
   `Outcome: updated to 0.3.0`, exit 0; `app:list` says 0.3.0,
   `needsDbUpgrade: false`, the directory holds the store's copy.

## What changed because of them

- `mwx/nextcloud-app` 0.1.1:
  `AppUpdater::preload()` and the list a test holds to `src/Update/`;
  the five Psalm findings the app's level 4 reported.
  The app takes 0.1.1.
- `scripts/vendor-scope.php` keeps the package's MIT licence beside the scoped
  classes (`lib/Vendor/mwx/Nextcloud/App/LICENSE`):
  Strauss copies it whatever the exclusions say.
- The plan's step 3.2, "an older nightly installed by hand",
  became a lowered version of the current build:
  an older release of the app has no `lfp:update` to run.
