# AP SandboxAuto v1.0: On where it starts

## TL;DR

`bwrap_enabled`, a switch shipped off, becomes `sandbox`: `auto`, `on` or `off`, `auto` by default.
`auto` renders in the sandbox where the process rendering can start one, and as now where it cannot;
`on` fails closed as the switch did; `off` never.
Then v0.3.0 is cut and published.

- setting: `bwrap_enabled` on/off (off) → `sandbox` auto/on/off (auto)
- probe: one answer per machine → one per process user and PHP mode,
  so `occ` never answers for the web server
- status: `on` needs bwrap, as now; `auto` optional, saying sandboxed or why not
- page: a switch → three choices; old key obsolete; unreleased, so no migration

### Blocks

1. Runner: `sandbox`, `auto`'s fallback, the probe keyed by process.
2. Status: the row for each choice.
3. Page and documents: three choices in *Limits*, translations, README, CHANGELOG.
4. Tests: each choice, the per-process probe, the sandboxed renders with `on`; CI.

After the blocks: v0.3.0 cut and pushed with its tag, up to the App Store's approval.

## Discussion

Before cutting v0.3.0 the user asked whether the sandbox should not ship undefined,
a repair step switching it on where bubblewrap is available.
Evaluated: a repair step probes as `occ` - perhaps root, perhaps outside the web
server's systemd hardening or container - and switching on after a probe that
the web server would fail stops every preview, failing closed; it would also store
the value, as if an administrator had set it.
A default of `auto`, decided by each rendering process, reaches the same aim
without either. The user chose it (`PLAN D1-A`, then `PLAN++`), with the release after.

## Analysis

- **Unreleased:** `bwrap_enabled` shipped in no release, so it is renamed and
  reshaped without a migration; it joins `OBSOLETE`, which removes it from app config
  on upgrade where a test instance holds it.
- **The setting:** a word scalar, `sandbox`, choices `auto`, `on`, `off`, default `auto`,
  validated as `preview_format` is. `Limits::$sandbox` carries it;
  `timeoutOnly()` sets `off`.
- **The runner:** `on` - `Sandbox::wrap()`'s refusal ends the run, as now;
  `auto` - a refusal leaves the run unsandboxed; `off` - no sandbox.
- **The probe's key:** today path and change time. A web worker and `occ` may differ:
  root against www-data, a php-fpm unit's `RestrictNamespaces=`, separate containers.
  The key gains the effective user id and `PHP_SAPI`;
  `DetectionCache` may be shared between servers, which the path already allows for.
- **The status:** `bwrap`'s row is needed while `sandbox` is `on`.
  Under `auto` it is optional and says `sandboxed` where the probe passes,
  `renders without it: <reason>` where not. The probe the status runs is the process's
  own: the page's is the web server's, `occ lfp:check`'s the CLI's; the status says which.
- **On upgrade:** where bwrap works, renders become sandboxed with no action;
  what a renderer read outside `/usr` and `/etc`, as fonts in the web user's home,
  it no longer sees. The README says so.

## Implementation Plan

### Block 1: The runner

1. `ConfigLexicon`: `SANDBOX` (`auto`, `on`, `off`; `auto`); `bwrap_enabled` to `OBSOLETE`.
2. `Limits`: `sandbox` in place of `bwrapEnabled`; `ConfigurationService::limits()`.
3. `CommandRunner`: `on` fails closed, `auto` falls back, `off` skips.
4. `Sandbox::probeKey()`: the effective user id and `PHP_SAPI`; messages name `sandbox`.
5. PHPUnit: each choice in the runner; the key differing by user and mode.

**Verification:** unit suite, ECS, Psalm.

### Block 2: The status

1. `PrerequisitesService`: bwrap needed while `on`; under `auto` the row's detail says
   sandboxed or why not, and which process probed.
2. PHPUnit for each choice's row.

**Verification:** unit and integration suites; `occ lfp:check` on the harness for each choice.

### Block 3: The page and the documents

1. `LimitsSection.vue`: *Sandbox (bubblewrap)*, three choices with their occ command;
   `types.ts`, `testState.ts`.
2. Translations; README (*Requirements*, the limits, *Paths, and what the app trusts*,
   the upgrade's change); CHANGELOG.

**Verification:** Vitest, ESLint, vue-tsc, stylelint, `scripts/l10n.sh check`.

### Block 4: The tests

1. `SandboxRenderTest` with `on`, and one render with `auto`.
2. Cypress `settings.cy.js`: the three choices, the status's word.
3. Push; CI's PHPUnit and Cypress.

**Verification:** integration suite (the sandbox tests skip in the harness),
Cypress `settings.cy.js` on the harness, CI green with no skips.

## Proposed commit message

- `[TASK] Sandbox: auto, on or off, auto by default, decided by the process rendering`
- `[TASK] Status: the sandbox's row for each choice`
- `[TASK] Settings: the sandbox's three choices on the page; README and CHANGELOG`
- `[TASK] Tests: the sandbox's choices, sandboxed renders with on and auto`
- `[RELEASE] v0.3.0`

## Change History

- v1.0: the plan, from the user's `PLAN D1-A` and `PLAN++`.
