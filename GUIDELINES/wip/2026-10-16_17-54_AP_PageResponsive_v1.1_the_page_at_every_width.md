# AP PageResponsive v1.1: The page at every width

## TL;DR

Blocks 1 to 4 made the page fit every width.
This revision adds the polish the user asked for after seeing it,
says where a shipped size comes from,
and lets one minimum apply to every type.

- table cells: `middle` → `top`; a value and its source each unbroken,
  so that the source moves under the number
- `OccCommand`: grey text → a shaded box, its copy button at its end
- source `mime` → `shipped` where a type's size is the one it ships with:
  resolver, `occ`, page ("50 (as shipped)")
- Defaults: a switch, "The minimum applies to images too" (`min_size_all_types`, off),
  and Nextcloud's `preview_max_filesize_image` shown with its command
- Defaults: Reset beside minimum and maximum while they are stored
- every other number field: emptied, it is unset, and shows its default as placeholder

### Blocks

1. Tables: wrap, then stack below 40rem. **Done.**
2. Fields and the add-extension form shrink to the line. **Done.**
3. Cypress: no horizontal overflow at 400 px. **Done.**
4. Screenshots retaken, taking over AP RuleLayout v1.0's Block 2. **Done.**
5. Tables top-aligned; value and source each unbroken.
6. `OccCommand` as a shaded box with its copy button at its end.
7. The `shipped` source.
8. The switch, and Nextcloud's image limit beside it.
9. Reset on the defaults; every other number field cleared to unset.
10. Translations, README, CHANGELOG, screenshots.

## Discussion

The user, on the completion gate of v1.0, with screenshots:

- the tables should align at the top;
- the `occ` row should look as in the approved sketch;
- "(MIME type)" should not break in itself, but go on the second line,
  the number on the first;
- an image type's empty minimum showed "50 (inherited)" and its source "MIME type"
  where nothing is stored for the type and the default is 0.

On the last, the user accepted a `shipped` source,
and asked for a switch on the defaults that applies the default to every type,
"It is not logical that it applies to PDF/AI but NOT images",
with a Reset on the defaults to their shipped value,
and Nextcloud's `preview_max_filesize_image` shown near the defaults with its command.
A Reset is for the defaults only:
every other field goes back to its default by removing its value.

## Analysis

- **Where the 50 comes from.**
  `ConfigurationService::defaultMimeTypes()` gives every image type a shipped
  minimum of `preview_max_filesize_image` (50 MB unless set),
  where Nextcloud's own image providers stop.
  `TypePolicy::resolve()` reads the merged rule (`mimeRules()`, the shipped one with
  the stored laid over), so a shipped size is reported as `mime`.
- **The shipped level.**
  `Source` gains `SHIPPED = 'shipped'`, between `default` and `mime`.
  `resolve()` walks shipped, then stored, then the extension:
  the shipped rule from `defaultMimeTypes()`, the stored from `storedMimeRules()`,
  both kept by `load()` for the request rather than recomputed per file.
  `Resolution::toArray()`, `occ lfp:config:get` and `show` carry the new value;
  the page names it "as shipped".
- **The switch.**
  A boolean scalar `min_size_all_types`, false by default, in `ConfigLexicon::SCALARS`,
  so that `occ`, the settings endpoint and `fingerprint()` know it at once.
  On, `defaultMimeTypes()` gives image types no minimum of their own,
  and they follow the default minimum as PDF and Illustrator do.
  With the default minimum at 0 that means this app renders every image of an
  enabled type, ahead of Nextcloud's own providers; the switch's ⓘ says so.
  A stored rule is validated against the shipped one,
  so one that becomes invalid when the switch moves is reported, as any other.
- **Nextcloud's image limit.**
  `nextcloudPreview()` gains `preview_max_filesize_image`, read only;
  the page shows it under the switch,
  with `occ config:system:set preview_max_filesize_image --value=<n> --type=integer`.
- **Reset and clearing.**
  The endpoint already unsets a scalar sent as `null`.
  The defaults' two fields show their value and a Reset while stored.
  Every other number field shows what is stored, empty where nothing is,
  its default as placeholder ("30 (default)"), and saves an emptied field as `null`;
  its command is then `occ lfp:config:unset <key>`.
- **The `occ` box.**
  A shaded, rounded box as wide as its command,
  its copy button inside at its end;
  in a rule, the full width of the rule.

## Implementation Plan

### Block 5: Tables top-aligned

1. `settings-admin.css`: cells `vertical-align: top`.
2. `ActiveTable.vue`: a size and its source each in a span that does not break.

**Verification:** lint, typecheck, stylelint, Vitest, build; looked at.

### Block 6: The `occ` box

1. `OccCommand.vue`: the box, the button at its end; full width where its
   parent asks (`.lfp-rule__occ`).

**Verification:** as Block 5; Cypress `settings.cy.js`.

### Block 7: The shipped source

1. `Source::SHIPPED`; `TypePolicy::resolve()` with the shipped level;
   `ConfigurationService` keeps the shipped and stored rules per request.
2. `occ` `config:get` and `show`: the source as it comes.
3. Frontend: `Sourced` takes `shipped`; "as shipped" in *What is processed*;
   a MIME type's placeholder "(as shipped)" where its shipped rule sets the size.
4. PHPUnit: the resolver, the commands, the controller;
   Vitest; the Cypress check after a reset.

**Verification:** PHPUnit both suites, ECS, Psalm; the frontend's; `settings.cy.js`.

### Block 8: The switch

1. `ConfigLexicon`: `min_size_all_types`; `defaultMimeTypes()` without an image
   minimum where it is on.
2. `nextcloudPreview()`: `preview_max_filesize_image`.
3. `TypesSection.vue`: the switch under the defaults, its ⓘ and command,
   Nextcloud's image limit beneath it with its command.
4. PHPUnit and Vitest.

**Verification:** as Block 7.

### Block 9: Reset and clearing

1. `NumberField.vue`: an optional Reset, shown where asked.
2. `TypesSection.vue`: Reset on the defaults while stored.
3. The other number fields (`TypesSection`, `LimitsSection`, `AdvancedSection`):
   clearable, the stored value or empty, the default as placeholder;
   `occ.ts`: `unset` for a setting not stored.
4. Vitest; Cypress where a field is cleared.

**Verification:** as Block 5; `settings.cy.js`.

### Block 10: Texts and pictures

1. `scripts/l10n.sh update`, German for the new texts, `build`, `check`.
2. `README.md`, *Configuration*: the switch, the shipped source.
3. `CHANGELOG.md`: the bullets amended.
4. Screenshots retaken.

**Verification:** `l10n.sh check`, `changelog.sh check`; the images looked at.

## Proposed commit message

One commit per block, the translations, README and CHANGELOG lines going with the
block whose texts they are, and Block 10 the screenshots:

- `[TASK] Settings: tables aligned to the top, a value and its source each unbroken`
- `[TASK] Settings: each occ command in a box, its copy button at its end`
- `[TASK] A size a type ships with is reported as shipped, not as the MIME type's`
- `[TASK] One minimum for every type, images too, where switched on`
- `[TASK] Settings: the defaults reset, every other number cleared to its default`
- `[TASK] Screenshots retaken: the occ boxes, the shipped sizes, the switch`

## Change History

- v1.0: the plan, from the TL;DR the user answered with PLAN++.
- v1.1: Blocks 5 to 10, from the user's review of the page after Block 4.
