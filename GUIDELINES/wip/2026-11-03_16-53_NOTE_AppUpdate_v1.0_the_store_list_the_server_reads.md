# NOTE AppUpdate v1.0: the store list the server reads

Where block 1 went differently from the plan,
measured against the server's source during its review on 2026-11-03.

1. **D4 named the wrong endpoint.**
   The server's `AppFetcher` reads the store's whole list,
   `<appstoreurl>/apps.json`, and filters it itself:
   the Nextcloud minimum and the PHP bounds, the Nextcloud maximum ignored,
   nightlies and pre-releases by channel or by the flag, one release per app.
   The platform view, `platform/<x.y.z>/apps.json`, applies the maximum,
   so a report read from it could say "nothing to take" where the installer
   would update.
   `StoreRelease` reads the same list the server reads and filters as the server
   filters; D4's reason,
   "the one endpoint that says what the server will offer", holds for this one.
   The plan's Analysis is read with this correction.
2. **The installer's answer is held to the chosen version.**
   On a beta, daily or git channel the fetcher admits pre-releases,
   and on daily or git nightlies, without the flag,
   so `--stable` could report one version and the installer take another.
   Where the store was readable and `isUpdateAvailable()` names a version other
   than the chosen one, the command fails (exit 2) naming both,
   before anything is taken, under `--check` too.
3. **Compatibility as the server decides it**, not as the plan sketched it:
   a range is parsed as `VersionParser` parses it,
   a bound compared on its own number of parts as `CompareVersion` does,
   a range with no minimum incompatible, a missing `isNightly` a nightly.
   A range the server cannot parse empties the server's whole list;
   here that one release is incompatible, the conservative reading.
4. **The store is not asked** where `appstoreenabled` is false,
   or `has_internet_connection` is false with the default store,
   as `Fetcher::get()` does not; the report says why.
5. **`--check` clears the fetcher's cache too**,
   so the installer's answer is current;
   on the stable channel a `--nightly --check` leaves the Apps page showing
   unstable releases of every app for up to the cache's TTL.
   The README says so.
6. **The root's `mwx/nextcloud-testing` constraint** widened from `^0.1` to `>=0.1 <1.0`:
   the path repository holds 0.3.0 only, and nothing resolved under the old one.
7. **The mirror and its deploy key** were the user's commands, not the agent's:
   the sandbox refuses to create a public project.
