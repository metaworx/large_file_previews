# AP DefaultsOff v1.0: nothing processed until enabled

## TL;DR

After installation the app processes nothing:
every MIME type ships off, and the status says so in amber.
The settings page and `occ` list, per type,
the preview providers Nextcloud tries, in its order,
a provider tried before this app's in amber, its class as a tooltip.

- Shipped defaults: PDF and Illustrator on → every type off;
  images keep their 50 MiB minimum, for when they are switched on
- Status: green "everything available" only → plus amber
  "Nothing is processed: every MIME type is disabled."
  where nothing at all is enabled; an extension enabled alone counts
- Providers: not shown → per type, in the order Nextcloud tries them,
  from `OCP\IPreview::getProviders()`; one before this app's in amber;
  the class name as the name's tooltip; in `occ lfp:config:show` too
- Tests, `check-install.sh`, the Cypress specs and the screenshots follow

### Blocks

1. Defaults off: the shipped rules, their tests, README, `check-install.sh`,
   the Cypress specs, CHANGELOG.
2. Amber status: `StatusSection` warns where nothing is processed.
3. Provider chain, server side: a service over `IPreview::getProviders()`,
   the settings API, `lfp:config:show`.
4. Provider chain, on the page: a *Providers* column in the MIME types table
   and in *What is processed*, amber before this app, the class as a tooltip.
5. Screenshots retaken, README, push and CI.

## Discussion

Settled with the user on 2026-10-15:

- **Nothing changes on installation.**
  An administrator enables each MIME type or extension the app is to take.
- **The status says so**, in amber, where nothing is processed.
- **The providers behind each type are listed**, in the order Nextcloud
  tries them; one that would be tried before this app's is amber;
  each name carries its class as a tooltip.
- **Run:** `PLAN++`, blocks 1 to 5, each committed and pushed to GitLab,
  up to the completion gate.
  No jackal update, no tag; helioscloud is the user's.

Recommended, and taken unless the user says otherwise:

- **No setup check** for nothing processed:
  the admin overview reports health, and an app switched on for nothing
  is not a fault.
- **Registration unchanged:** the provider stays registered for every
  supported type and declines a disabled one cheaply.
  Registering only the enabled types would fix the regex at boot,
  so a change on the page would apply from the next request only.

## Analysis

- **The shipped rules** are `ConfigLexicon::defaultMimeTypes()`:
  `DOCUMENT_TYPES` (PDF, Illustrator) enabled, `IMAGE_TYPES` disabled with
  the image minimum. A MIME rule stores only its own fields over that default
  (`storedMimeRules()`), so an installation that never set PDF switches it off
  with this change: jackal, at its next update, and the harness instances.
- **What reads the default:** `ConfigurationService::defaultMimeTypes()`
  and `shipped()`, the resolution, the settings API's `default`,
  the page's *Reset*, and tests that assume PDF is on: unit and integration,
  the Cypress `pdf`-has-no-effect test, and `check-install.sh`'s preview,
  which no other provider renders on a bare server.
- **The status** shows `state.ready` as a success or error card.
  "Nothing is processed" is a text already, the empty row of *What is processed*;
  `state.active` holds a row with `processing: 'enabled'` exactly where
  something is processed.
- **The providers:** `IPreview::getProviders()` (OCP, since 8.1) returns
  `regex => list<Closure>`. `PreviewManager` sorts the regexes by length,
  longest first, and keeps one regex's closures in registration order;
  the generator walks them so, skipping a closure that returns `false`
  and a provider whose `isAvailable()` declines the file.
  A type's chain is every closure under a regex matching it, in that order,
  each called once to name its provider:
  the class's short name, this app's provider as the app's name,
  the full class for the tooltip.
  Whether a provider takes a particular file is known per file only,
  so the list says what is registered, not what would answer.
- **The order is internal to the server**;
  the contract (§3.2) pins it with an integration test, which this reuses.

## Implementation Plan

1. **Defaults off.**
   - `ConfigLexicon::defaultMimeTypes()`: every type `enabled: false`,
     images with their minimum; its docblock.
   - Unit and integration tests that assume PDF is on: set it where they need it.
   - `tests/e2e/settings.cy.js`: the no-effect test enables PDF first.
   - `tests/e2e/store/check-install.sh`: PDF enabled before the preview check.
   - README *Configuration*; CHANGELOG.

   **Verification:** PHPUnit both suites in `34-lfp`, Vitest, ECS, Psalm;
   Cypress against `34-lfp`; `check-install.sh` there.
2. **Amber status.**
   - `StatusSection.vue`: a warning card,
     "Nothing is processed: every MIME type is disabled.",
     where no active row is enabled.
   - Vitest; a Cypress check on a fresh state.

   **Verification:** Vitest, lint, typecheck; Cypress.
3. **Provider chain, server side.**
   - `lib/Service/ProviderChain.php`: per MIME type, the providers in order,
     each `{ name, class, ours, before }`.
   - `SettingsController`: `providers` on each MIME row and active row.
   - `lfp:config:show`: the chain per type, in text and JSON.
   - Unit tests over a mocked `IPreview`;
     an integration test on the real server: for PDF, this app first.

   **Verification:** PHPUnit, ECS, Psalm.
4. **Provider chain, on the page.**
   - A *Providers* column in the MIME types table and in *What is processed*:
     the names in order, one before this app's in amber,
     the class as each name's tooltip.
   - `types.ts`, Vitest, German for the new texts.

   **Verification:** Vitest, lint, typecheck, `l10n.sh check`; Cypress.
5. **Screenshots and the rest.**
   - The screenshot spec enables what its shots show;
     `docs/Screenshots/` retaken.
   - README: the providers and the status.
   - Push; the mirror's CI.

   **Verification:** the images show what their names say; CI green.

## Proposed commit messages

1. `[TASK] Nothing is processed until enabled: every MIME type ships off`
2. `[TASK] Settings: the status warns in amber where nothing is processed`
3. `[TASK] The preview providers behind each type, in the order Nextcloud tries them`
4. `[TASK] Settings: the providers per type, amber where one comes before this app`
5. `[TASK] Screenshots retaken: types off by default, and the providers`

Each with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Change History

| Version | Date       | Summary |
|---------|------------|---------|
| v1.0    | 2026-10-15 | First version. |
