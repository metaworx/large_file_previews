# Browser tests

Cypress specs, run against a live Nextcloud:
the harness instance of this app while developing, a fresh server in CI.

`store/` holds the install check's scripts, which are not Cypress:
`appstore.py` serves a fake app store,
`check-install.sh` checks an install by `occ`.

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

Cypress is pinned to an exact version in `package.json`,
so that its binary, about 500 MB, is the one already in `~/.cache/Cypress`
rather than a newer one downloaded.

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
