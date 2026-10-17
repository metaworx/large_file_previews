# Browser tests

Cypress specs, run against a live Nextcloud:
the harness instance of this app while developing, a fresh server in CI.

`store/` holds the install check's scripts, which are not Cypress:
`appstore.py` serves a fake app store,
`check-install.sh` checks an install by `occ`.

## Specs

Cypress runs them in alphabetical order.
Each sets up what it needs and clears what it set, before and after,
so any one of them runs alone.

| Spec | What it checks |
|------|----------------|
| `backup.cy.js` | Backup and restore round trip: rules set by `occ`, downloaded, cleared, the file uploaded, its changes shown and applied, and `occ` exporting what it did before; a file with a value out of range refused, nothing written. |
| `app-enable.cy.js` | The app enabled and disabled on the Apps page, as `occ` sees it, and the settings showing the version the server runs; left enabled. |
| `l10n-german.cy.js` | The settings page in `de` and in `de_DE`. Sets the administrator's language, and lifts `force_language` for its run; restores both. |
| `screenshots.cy.js` | Not a test: the screenshot set of `docs/Screenshots/`, as a spec. Skips itself unless `CYPRESS_capture=1`; see *Taking the screenshots*. |
| `settings.cy.js` | `7z` refused with the server's message; `heic`, whose type is off, added and listed in *What is processed*, then removed; `pdf` marked as having no effect; a stored minimum shown as stored after a reload, the shipped one as the placeholder, and `occ` reading the same; every type's providers on a row of their own once one chain is too long; nothing wider than the page at 400 px; no help icon over a *Save* button. |
| `store/install-from-apps-page.cy.js` | The install check's: the release installed through the Apps page from the store the server is pointed at, until `occ` reports it enabled at the version under test. Skips itself unless `CYPRESS_storeInstall` names that version. |

## Environment

- `CYPRESS_baseUrl` - the server's URL;
  `https://nextcloud-34-lfp.ddev.site`, the harness instance, where unset.
- `CYPRESS_occ` - the command that runs `occ`, the arguments following it;
  `php nextcloud/occ`, the CI layout, where unset.
  Against the harness:
  `$HOME/projects/nextcloud_testing/scripts/nc-test 34-lfp occ`,
  which quotes each argument and forwards stdin,
  where a plain `ddev exec` re-quotes them.
- `CYPRESS_NC_ADMIN_USER`, `CYPRESS_NC_ADMIN_PASSWORD` -
  the administrator, `admin` and `admin` where unset.

The configuration sets `allowCypressEnv: false`,
so a spec reads these with `cy.env()`, never `Cypress.env()`.
`support/e2e.js` wraps the two a spec needs:
`cy.occ('<arguments>')` and `cy.loginAsAdmin()`.

## Running

Start the instance first: `ddev exec` starts a stopped one itself,
but slower than `cy.exec()` waits.

```bash
CYPRESS_occ="$HOME/projects/nextcloud_testing/scripts/nc-test 34-lfp occ" \
npx cypress run
```

One spec: add `--spec tests/e2e/<spec>.cy.js`.

### Taking the screenshots

`screenshots.cy.js` sets the light theme and a few rules,
takes the set into `docs/Screenshots/`, and puts both back.
It runs only when asked:

```bash
CYPRESS_capture=1 \
CYPRESS_occ="$HOME/projects/nextcloud_testing/scripts/nc-test 34-lfp occ" \
npx cypress run --spec tests/e2e/screenshots.cy.js
```

`appinfo/info.xml` and the README name each image at the release's tag,
each in a block `changelog.sh cut` moves to the next release;
a new image is added to both blocks, and shown in the README.

Cypress is pinned to an exact version in `package.json`,
so that its binary, about 240 MB to download and 360 MB unpacked,
is the one already in `~/.cache/Cypress` rather than a newer one downloaded.
In CI it comes from GitHub's cache, keyed on that version.

## What the support file does

- **Fails on this app's exceptions only.**
  An uncaught exception whose message or stack names `large_file_previews` fails the test;
  one from Nextcloud's own apps is logged and ignored.
- **Deletes the tokens its logins made.**
  Every login mints an auth token whose name carries `Cypress/`;
  they are deleted by `occ` at the end of each spec,
  and at its start for what the previous spec's hooks left.
- **Prints a diagnostic when a test fails:**
  the URL, the settings page's text, the app's scripts on the page,
  and the console's errors, through `cy.task('diag')`,
  since `cy.log()` does not reach stdout in `cypress run`.
