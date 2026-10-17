# AP ConfigTransfer v1.0: Configuration backup and restore

## TL;DR

A backup of what is stored, written by `occ` or downloaded from the page.
Restoring it validates everything first and writes all of it or nothing:
`occ` restores system config as well, the page app config only,
showing the `occ` commands for the rest.

- export: JSON, the app id, the version that wrote it, a format number;
  stored settings, MIME rules, extensions - only what is stored -
  and the three system values
- import: merge by default (what the file names), `--replace` to clear the rest;
  a dry run lists each change, before → after
- what we know is used, what we don't is skipped and named: exit 3;
  an unreadable file or an invalid value is refused, nothing written: exit 4
- `occ lfp:config:export [file]`, `occ lfp:config:import <file> [--dry-run] [--replace]`
- page, a section of its own at the end: Download; Upload → validated on the server →
  the changes, warnings and system commands listed → Apply

### Blocks

1. The transfer service: export, the changes an import would make, applying them.
2. `occ lfp:config:export` and `lfp:config:import`.
3. Endpoints: export, and import with or without applying.
4. Page: Backup and restore.
5. Translations, README, CHANGELOG; a Cypress round trip.

## Discussion

Settled with the user on 2026-10-17:

- **Why:** an update or a reinstall should not cost the configuration;
  jackal is to be its first real use, exported before an update and imported after.
  jackal is outside this plan.
- **The page uploads too:** validated, its app settings imported,
  and the `occ` commands shown that apply what goes into `config.php`.
- **Merge by default**, `--replace` (a checkbox on the page) to make the server
  hold exactly what the file holds.
- **A section of its own** at the end of the page, "Backup and restore".
- **Lenient, and loud:**
  "as long as we can read the format, we use all values we know
  and are loud about those we don't (without refusing)".
  Exit codes: 0 done, **3 done with warnings**, **4 refused**;
  1 (a general error) and 2 (a wrong call) reserved, as Symfony has them.

Open at the plan gate: a key the app knows whose value is invalid -
out of range, of the wrong type, a rule whose minimum exceeds its maximum.
This plan refuses it (4), since skipping it would restore something
other than the file says, without a sign beyond a warning.

## Analysis

- **What is stored.**
  App config: the settings of `ConfigLexicon::SCALARS`, `mimetypes` (each type's own
  fields, laid over what it ships with) and `extensions`.
  `ConfigurationService::isStored()`, `storedMimeRules()` and the extensions' map
  say what is set rather than defaulted.
  `last_failure` is a record, not configuration, and stays out.
- **System config.**
  `large_file_previews.binaries`, `.vips_allow_untrusted` and `.scratch_directory`,
  written by `occ lfp:config:set` only - a program's path only where it is an
  executable file - and never by the page (`_CONTRACT.md` §3.3).
  `preview_max_filesize_image` is Nextcloud's, and stays out.
- **Writing.** `ConfigurationService::apply()` takes scalars, MIME rules and
  extensions, validates every change, and writes all or nothing; `null` unsets.
  An import is one call of it, so a refusal leaves the server as it was.
  System values are set after it, by `occ` only, each validated as `config:set` does.
- **The format.**

  ```json
  {
    "app": "large_file_previews",
    "format": 1,
    "version": "0.1.0",
    "exported": "2026-10-17T02:18:00+00:00",
    "settings":   { "timeout_seconds": 45 },
    "mimetypes":  { "image/tiff": { "enabled": true, "min_size_mb": 800 } },
    "extensions": { "tif": { "enabled": false } },
    "system":     { "binaries": { "pdftoppm": "/opt/poppler/bin/pdftoppm" },
                    "vips_allow_untrusted": true,
                    "scratch_directory": "/var/cache/lfp" }
  }
  ```

  Each part is optional on import; `system` lists only what is set.
- **Reading a file.**
  - Refused (4): not JSON, not an object, `app` another app's,
    `format` missing or newer than this app reads; an invalid value of a known key.
  - Skipped and named (3): a key at any level this app does not know -
    a setting, a rule's field, a MIME type it does not handle, a system value,
    a part - and, from the page, every system value, with its command.
  - Used: everything else.
  - `version` is reported, not checked: an older app's file is read by the same format.
- **Replace.** Every setting, MIME rule and extension the file does not name is unset;
  by `occ`, every system value it does not name as well.
  The page shows the `occ` commands that would unset those.
- **Changes listed.** Before → after for each setting, rule and system value that
  changes, so that a dry run, and the page before Apply, say what will happen.
- **Endpoints.** `GET /settings/export`, the document;
  `POST /settings/import` with the document, `replace` and `apply`,
  answering the changes, the warnings, a refusal, the system commands,
  and with `apply` the new state. Admin only, CSRF checked, as the others.
- **Download on the page** builds the file in the browser from the export's answer,
  `large_file_previews-<host>-<date>.json`; the upload reads the file in the browser
  and posts its text.

## Implementation Plan

### Block 1: The transfer service

1. `lib/Service/ConfigTransfer.php`: `export(): array`;
   `read(string $json): Transfer` - the parts it can use, the warnings, or a refusal;
   `changes(Transfer, bool $replace): list<Change>`;
   `apply(Transfer, bool $replace, bool $system): void`
   through `ConfigurationService::apply()`, then the system values where `$system`.
2. `lib/Config/Transfer.php`, `TransferChange.php`: what was read, one change.
3. PHPUnit: export holds what is stored only; merge and replace; each refusal;
   each warning; all or nothing where a value is invalid; the system part.

**Verification:** PHPUnit, ECS, Psalm.

### Block 2: The commands

1. `ConfigExport`: the document to a file or stdout.
2. `ConfigImport`: `<file>` or `-`, `--dry-run`, `--replace`;
   the changes as a table, the warnings, exit 0, 3 or 4.
3. `appinfo/info.xml`: both registered.
4. PHPUnit: the exit codes, the dry run writing nothing.

**Verification:** as Block 1; both commands run on the harness instance.

### Block 3: The endpoints

1. `SettingsController`: `export()` and `import()`.
2. PHPUnit: admin only; import without `apply` writes nothing; with it, all or nothing;
   system values never written.

**Verification:** as Block 1.

### Block 4: The page

1. `BackupSection.vue`: Download; a file input; the changes, warnings, refusal and
   system commands; Replace; Apply.
2. `useSettings.ts`: export and import.
3. Vitest.

**Verification:** lint, typecheck, stylelint, Vitest, build; looked at.

### Block 5: Texts, documents, a round trip

1. Translations: `scripts/l10n.sh update`, German, `build`, `check`.
2. `README.md`: *Backup and restore*; `CHANGELOG.md`: the bullets.
3. Cypress: rules set, downloaded, cleared, uploaded, applied, read back by `occ`.

**Verification:** `l10n.sh check`, `changelog.sh check`;
every Cypress spec on the harness.

## Proposed commit message

One per block:

- `[TASK] Configuration transfer: what is stored, read back and applied all or nothing`
- `[TASK] occ lfp:config:export and import, warnings and refusals as exit codes`
- `[TASK] Settings API: the configuration exported, and imported with or without applying`
- `[TASK] Settings: backup and restore, the changes shown before they apply`
- `[TASK] Backup and restore: German, README, CHANGELOG, a browser round trip`

## Change History

- v1.0: the plan, from the TL;DR and the user's answers on 2026-10-17.
