# AP PreviewQuality v1.0: Nextcloud's quality on the page

## TL;DR

The quality a preview is stored at is Nextcloud's, `preview` `jpeg_quality` and
`webp_quality`, which the page shows read only.
They become fields there, set and cleared as the app's own numbers are,
saying that they apply to every preview the server makes from then on.

- *Previews → Nextcloud's own*: JPEG and WebP quality,
  text → number fields, 10 to 100, emptied back to Nextcloud's 80
- `PUT /settings`: a part `nextcloud` beside `scalars`,
  checked with the rest, written after it
- written as integers; a key stored as another type refused, with the command that sets it
- their commands: `occ config:app:set preview jpeg_quality --value=70 --type=integer`,
  `occ config:app:delete preview jpeg_quality` where nothing is stored
- not exported by backup and restore: they are Nextcloud's, not the app's

### Blocks

1. The service and the endpoint write the two qualities.
2. The page's two fields; translations, README, CHANGELOG; a browser test.

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
- **Backup and restore** leaves them out: they are Nextcloud's configuration,
  which `occ config:app:set` and Nextcloud's own tools carry.

## Implementation Plan

### Block 1: Writing the two qualities

1. `ConfigurationService`: `setPreviewQuality(string $format, ?int $quality)`,
   checked within 10 to 100, a type conflict refused;
   `nextcloudPreview()` says which are stored.
2. `SettingsController::update()`: the `nextcloud` part,
   checked first, written after the rest.
3. PHPUnit: a value set, one cleared, one out of range, a type conflict,
   a refusal of either writing neither.

**Verification:** PHPUnit, ECS, Psalm.

### Block 2: The page

1. `PreviewsSection.vue`: the two qualities as number fields,
   cleared to Nextcloud's default, with their commands and their help.
2. `occ.ts`: the app-config command with `--type=integer`, and its delete.
3. `types.ts`, `testState.ts`; Vitest.
4. Translations; `README.md`, *Configuration*; `CHANGELOG.md`.
5. Cypress `settings.cy.js`: a quality set on the page, read back by `occ`, and cleared.

**Verification:** lint, typecheck, stylelint, Vitest, build, `l10n.sh check`;
`settings.cy.js` on the harness.

## Proposed commit message

- `[TASK] Nextcloud's preview quality written by the settings API, within its range`
- `[TASK] Settings: Nextcloud's JPEG and WebP quality set on the page`

## Change History

- v1.0: the plan, from option 1 of the TL;DR, with PLAN++.
