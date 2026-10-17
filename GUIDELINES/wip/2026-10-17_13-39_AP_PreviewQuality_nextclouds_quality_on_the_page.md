# AP PreviewQuality v1.1: Nextcloud's quality on the page

## TL;DR

The quality a preview is stored at is Nextcloud's, `preview` `jpeg_quality` and
`webp_quality`. The page sets them (Blocks 1 and 2, done);
backup and restore now carries them, under `nextcloud > appconfig > preview`,
and shows the commands that set them rather than applying them;
`occ lfp:config:import` reads standard input without `-`,
and prints those commands alone on stdout, everything else on stderr.

- *Previews → Nextcloud's own*: JPEG and WebP quality as number fields. **Done.**
- format: part `nextcloud > appconfig > preview`, its stored keys; format 1 still,
  an older app leaving the part out with a warning
- import, occ and page: the qualities listed as changes, never applied;
  their commands shown
- `occ lfp:config:import`: `file` optional, standard input where it is piped in;
  commands → stdout, the rest → stderr, under `--dry-run` too
- the pipe runs `occ` as written: an `occ` function or alias where occ is called otherwise

### Blocks

1. The service and the endpoint write the two qualities. **Done.**
2. The page's two fields; translations, README, CHANGELOG; a browser test.
   **Done.**
3. `ConfigTransfer`: the `nextcloud` part, exported, read, listed as changes,
   never applied.
4. `ConfigImport`: standard input without `-`; commands to stdout, the rest to stderr.
5. Page: the qualities' commands; translations, README, CHANGELOG; tests.

## Discussion

The user asked whether the quality of a preview the app makes can be set,
for JPEG and for the other formats.
The app writes JPEG and WebP at 95 (`PreviewFormat::QUALITY`),
and Nextcloud re-encodes every preview it stores at its own quality,
the largest and every size made from it (`Image::data()`, Nextcloud 34);
PNG is lossless and has none.
Of three options - Nextcloud's two settings editable on the page,
a quality of the app's own for the intermediate image, or nothing new -
the user chose the first, and no PHP API for now.

After Block 2, on backup and restore:

- `occ lfp:config:import < ./t.json` is to do what `occ lfp:config:import - < ./t.json` does;
- Nextcloud's two qualities are to be exported, and on import shown with their
  `occ` commands, as the page shows the system values, and printed by occ too,
  so that `occ lfp:config:import - < ./t.json | bash` sets them;
- they go under `nextcloud > appconfig > preview`, after `preview` - an app id of
  Nextcloud's app config, as `core` is, not an app - so that core's app config,
  or Nextcloud's own `config.php`, could follow under `nextcloud > appconfig > core`
  or `nextcloud > system`;
- the commands are stdout's data, for the shell; everything else goes to stderr.

## Analysis

- **Where they live.** App config of `preview`, read by `OC\Image` with
  `getValueInt()` (80 unless set) and by Imaginary as strings.
  No lexicon declares them.
- **Writing.** `IAppConfig::setValueInt()` where the key is absent or stored as an
  integer or untyped (`occ config:app:set` without `--type`);
  a key stored as another type throws `AppConfigTypeConflictException`,
  which becomes a refusal with the `occ` command that replaces it.
  Cleared: `deleteKey()`, and Nextcloud's 80 applies.
- **Range.** 10 to 100, what `nextcloudPreview()` already holds a value read to.
- **The endpoint.** `PUT /settings` takes
  `{"nextcloud": {"jpeg_quality": 70, "webp_quality": null}}`;
  the values are checked before `ConfigurationService::apply()` writes the app's,
  and written after it, so a refusal of either writes neither.
- **What the page shows.** `nextcloudPreview()` gains whether each is stored,
  so that an unstored one is an empty field
  with "80 (default)" as its placeholder, as the app's own numbers are.
- **Previews already made** keep the quality they were stored at;
  the field's help says the setting applies to previews made from then on.
- **In the file.** `"nextcloud": {"appconfig": {"preview": {"jpeg_quality": 70}}}`,
  the stored keys only, as the app's own part holds what is stored only.
  Read: a key or app id this app does not carry is left out and named
  (a warning of its own kind, `nextcloud`, named by its path);
  a quality outside 10 to 100, or not a whole number, refuses the file.
- **As changes.** Part `nextcloud`, named `appconfig.preview.jpeg_quality`,
  before and after as the other parts have them, replace included.
  Neither import applies them: occ prints, and the page shows,
  `occ config:app:set preview jpeg_quality --value=70 --type=integer`,
  or `occ config:app:delete preview jpeg_quality`.
- **Standard input.** `file` optional; absent, standard input is read where it is
  not a terminal (`stream_isatty()`), and a terminal is a wrong call (exit 2).
  `-` stays.
- **Two streams.** The commands to stdout, one a line;
  what wrote the file, what was left out, the table, the summary and a refusal
  to stderr (`ConsoleOutputInterface::getErrorOutput()`).
  The exit status is as before: 0, 3, 4.

## Implementation Plan

### Block 1: Writing the two qualities - done

1. `ConfigurationService`: `setPreviewQuality(string $format, ?int $quality)`,
   checked within 10 to 100, a type conflict refused;
   `nextcloudPreview()` says which are stored.
2. `SettingsController::update()`: the `nextcloud` part,
   checked first, written after the rest.
3. PHPUnit: a value set, one cleared, one out of range, a type conflict,
   a refusal of either writing neither.

**Verification:** PHPUnit, ECS, Psalm.

### Block 2: The page - done

1. `PreviewsSection.vue`: the two qualities as number fields,
   cleared to Nextcloud's default, with their commands and their help.
2. `occ.ts`: the app-config command with `--type=integer`, and its delete.
3. `types.ts`, `testState.ts`; Vitest.
4. Translations; `README.md`, *Configuration*; `CHANGELOG.md`.
5. Cypress `settings.cy.js`: a quality set on the page, read back by `occ`, and cleared.

**Verification:** lint, typecheck, stylelint, Vitest, build, `l10n.sh check`;
`settings.cy.js` on the harness.

### Block 3: The qualities in the file

1. `ConfigTransfer`: `export()` writes the part; `read()` reads it into `Transfer`;
   `changes()` lists them; `split()` and `apply()` leave them alone;
   `command()` gives the `occ` command of such a change.
2. `TransferWarning::NEXTCLOUD`.
3. PHPUnit: exported where stored; read, a key or app left out, a value refused;
   listed, merged and replaced; never written.

**Verification:** PHPUnit, ECS, Psalm.

### Block 4: The command

1. `ConfigImport`: `file` optional, standard input where piped;
   the commands to stdout, the rest to stderr.
2. PHPUnit: standard input without `-`; a terminal a wrong call;
   stdout holding the commands only.

**Verification:** as Block 3; `occ lfp:config:import < file | cat` on the harness.

### Block 5: The page, texts and documents

1. `BackupSection.vue`: the qualities among the commands; the warning's text.
2. `types.ts`; Vitest.
3. Translations; `README.md`, *Backup and restore*; `CHANGELOG.md`.

**Verification:** lint, typecheck, stylelint, Vitest, build, `l10n.sh check`;
`backup.cy.js` on the harness.

## Proposed commit message

- `[TASK] Nextcloud's preview quality written by the settings API, within its range`
- `[TASK] Settings: Nextcloud's JPEG and WebP quality set on the page`
- `[TASK] Backup and restore: Nextcloud's preview qualities in the file, as commands`
- `[TASK] occ lfp:config:import: standard input without -, the commands alone on stdout`
- `[TASK] Settings: the qualities' commands on restore; README and CHANGELOG`

## Change History

- v1.0: the plan, from option 1 of the TL;DR, with PLAN++.
- v1.1: Blocks 3 to 5, the qualities in backup and restore and the import's standard input
  and streams, from the user's answers after Block 2.
