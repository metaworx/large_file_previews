# AP LargeFilePreviews v1.0: step one, PDF and AI

## TL;DR

The app's first version: a preview provider that runs `pdftoppm` on the local path of
large PDF and Illustrator files, with configuration available from occ and an admin
settings page, CI on a GitHub mirror, and the app-store certificate requested.

- Provider: an anchored MIME regex longer than any other → tried first; `isAvailable()` declines cheaply → the next provider takes over
- Renderer: `timeout` → `prlimit --as` → `choom -n` → `pdftoppm`, through `proc_open` with an argument array; every limit can be switched off; fail closed
- Config: one `ConfigurationService` for occ and the page; per type the most specific value wins (extension → MIME type → app default)
- Images: in the regex but off by default; vips is step two
- No translations yet; texts still go through `t()`/`IL10N`

### Blocks

1. Scaffold
2. Remotes and CI
3. Certificate request
4. Configuration and its commands
5. Command runner
6. Renderer and provider
7. Setup check and last failure
8. Admin settings page
9. Integration tests (harness gets poppler)
10. Packaging and publish pipeline
11. Manual test on jackal

## Discussion

Settled with the user on 2026-10-13:

- **Scope:** PDF and AI only. Image types are in the regex and configuration, all off.
  Videos are out of scope: core's `Movie` provider already reads the local path. There is
  no Docker option, and provider order is not configurable.
- **Resolution:** field by field, the most specific value set wins.
  Image types default to `min_size_mb` 50 (matching `preview_max_filesize_image`), and PDF/AI to 0.
- **A limit that is switched on but whose tool is missing:** fail closed. The file is
  declined and the setup check says why.
- **No counters** in step one: setup check and last failure only.
- **The harness** (`nextcloud_testing`) gets `poppler-utils`, as a gated change in that repository.
- **ConfigurationService:** the only code that reads and writes the settings, so that
  occ and the settings page validate alike. This also brings `config:set` / `config:unset`.
- **Settings page in step one,** with `@nextcloud/vue` components wherever one fits.
- **Hosting as for FCIAS:** GitLab `origin` is the code host; a push mirror carries everything to
  GitHub `metaworx/large_file_previews`, where CI runs. No `.gitlab/` directory.
- **Translations:** none at the start. No l10n tooling, `translationfiles/` or German,
  but every user-facing text goes through `t()`/`IL10N` (contract §3.4), so adding
  translations later is just tooling.
- **FCIAS** (`~/projects/nc_file_checksum_search`) is the pattern to copy from, but is
  **never named** in code, comments or shipped documentation.

## Analysis

### Nextcloud internals (NC 33.0.8, 34.0.3, 35.0.1)

- **Interface:** implement `OCP\Preview\IProviderV2` directly (`getMimeType()`,
  `isAvailable(FileInfo)`, `getThumbnail(File, int, int): ?IImage`). Register with
  `IRegistrationContext::registerPreviewProvider()`. `OC\Preview\ProviderV2` is private.
- **Gating:** bootstrap providers ignore `enabledPreviewProviders`. Only `enable_previews=false`
  and a mount's `previews` option block them. Nothing checks size for us.
- **Ordering:** `PreviewManager::getProviders()` sorts buckets by regex string length,
  longest first. The longest competitor is core Imaginary at 92 characters. Ours:

  ```
  /^(?:application\/(?:pdf|illustrator)|image\/(?:tiff|jpeg|png|webp|gif|heic|heif|avif|jp2|jxl|bmp|x-portable-(?:bitmap|graymap|pixmap)))$/
  ```

  138 characters; SVG and raw formats are left out. This is internal behaviour, pinned by an
  integration test and listed in the contract's inventory (§3.2).
- **A throw ends the chain with a 500,** so the provider never throws: every failure returns `null`.
- **`isAvailable()` runs for every image preview** on the instance. It does no I/O:
  it checks the MIME type, extension and `FileInfo::getSize()` against config read once per request.
- **The provider is asked only for the max preview** (`preview_max_x/y`, default 4096). A file
  that already has one never reaches a provider again, so tests use files with no previews.
- **Local files only:** render when `! $file->isEncrypted() && $file->getStorage()->isLocal()`
  and `getLocalFile()` returns a path. Anything else is declined, never copied.
- **NC 35:** `IPreview::registerProvider` is gone from the public interface. The bootstrap
  registration and `getProviders()` are unchanged.

### Renderer

```
timeout --kill-after=<K>s <T>s  prlimit --as=<BYTES> --  choom -n <ADJ> --  pdftoppm -f 1 -l 1 -scale-to <N> -png -singlefile <in> <outstem>
```

- `N = max(maxX, maxY)`, then `scaleDownToFit(maxX, maxY)`.
- Success means exit status 0 and a valid PNG. stderr carries harmless `Syntax Warning:` lines.
- `timeout` exits 124 on timeout and 137 when it had to kill. `choom` may only raise the score (0..1000).
- Binaries are found with `IBinaryFinder`; a binary path is never a config key.
  stdout → `/dev/null`; the first few KB of stderr are kept as the reason.
  Output goes in an `ITempManager` folder, removed in a `finally`.
- Measured on jackal: a 1.7 GB, 1817-page PDF rendered page 1 at 4094 × 2893 in 0.47 s, with 61 MB peak RSS.

### Configuration keys (`ConfigLexicon`, `Strictness::WARNING`, `FLAG_INTERNAL`)

| Key | Type | Default | Range |
|---|---|---|---|
| `min_size_mb` | int | 0 | ≥ 0 |
| `max_size_mb` | int | -1 (none) | ≥ -1 |
| `mimetypes` | array | PDF, AI on; images off, min 50 | per type: `enabled`, `min_size_mb`, `max_size_mb` |
| `extensions` | array | `{}` | same fields, lower-case keys |
| `timeout_enabled` / `timeout_seconds` / `timeout_kill_after_seconds` | bool/int/int | true / 30 / 5 | 1..600 / 1..60 |
| `prlimit_enabled` / `prlimit_as_mb` | bool/int | true / 2048 | 256..65536 |
| `choom_enabled` / `choom_adjust` | bool/int | true / 1000 | 0..1000 |
| `last_failure` | array | `{}` | written only on failure; not settable |

- **`ConfigurationService`:** typed getters; partial setters that **refuse** an invalid value
  with a reason (an English message for occ, an `IL10N` one for the page).
  - It refuses: min > max (other than -1), negative values other than max -1, unknown
    fields, and a MIME type the regex does not match.
  - On read, it clamps and skips invalid entries, since a raw `occ config:app:set` bypasses it.
- **`TypePolicy::resolve(?mime, ?ext): Resolution`** is the one resolver, used by the provider,
  the commands and the page. `Resolution` is readonly: values, the source of each, and
  `verdict(size)` with its reason.

### Commands (`large-file-previews:*`, alias `lfp:*`, `--output=plain|json|json_pretty`)

- `config:show`: stored values, tools (paths, `proc_open`, the prefix that would run), and resolved rows.
- `config:get [--mime] [--ext] [--size] [--file <user path>]`: one resolution with its sources and verdict.
  `--file` also reports whether the file is local and unencrypted.
- `config:set [--mime|--ext <t>] [--enabled=yes|no] [--min] [--max]`, and `config:set <key> <value>` for scalars.
- `config:unset [--mime|--ext <t>] [<key>]`.

### Settings page (patterned on FCIAS's admin page)

- Backend:
  - `Settings\Admin` in core's `additional` section, so no section class is needed;
  - `templates/settings-admin.php` mounts the app;
  - a `SettingsController` with `#[ApiRoute]`, admin-only (no `#[NoAdminRequired]`, pinned by a
    reflection test), with `GET` and a partial `PUT` that calls `ConfigurationService`;
    CSRF is kept, and a refusal answers 400 with its `IL10N` text.
- Frontend: Vue 3 `<script setup lang="ts">`, built with Vite (`@nextcloud/vite-config`), using
  `NcSettingsSection`, `NcCheckboxRadioSwitch`, `NcInputField`/`NcTextField`, `NcButton`,
  `NcNoteCard` and `NcSelect`.
  - Sections: size defaults; MIME types (a table); extensions (add and remove); limits; and a
    read-only tools status.
  - Specs in Vitest use the real components, not stubs.
- Tooling: ESLint, Stylelint and `vue-tsc`, all in CI's lint job.

### Certificate

- Key and CSR in `~/.nextcloud/certificates/large_file_previews.{key,csr}`:
  `openssl req -nodes -newkey rsa:4096 -keyout … -out … -subj "/CN=large_file_previews"`.
  The key never leaves the machine.
- Request: branch `large_file_previews` on the `metaworx/nextcloud-app-certificate-requests`
  fork (synced with upstream `master`), file `large_file_previews/large_file_previews.csr`,
  then a PR to `nextcloud/app-certificate-requests` with the link to the public GitHub repo.
- After the `.crt` arrives, the app is registered in the store (`POST /api/v1/apps`, signed app id).
  That is block 10.

## Implementation Plan

1. **Scaffold.**
   - `composer.json`, with `vendor-bin/{ecs,psalm,phpunit,rector}`, `ecs.php`, `psalm.xml` (level 4,
     no baseline, no suppressions), `rector.php` (NC 33 set), `tests/phpunit.xml` and `tests/bootstrap.php`.
   - `appinfo/info.xml` (NC 33–35, PHP ≥ 8.2, databases listed, English only) and `Application` with an empty `boot()`.
   - `CHANGELOG.md`, `README.md`, `CONTRIBUTING.md`, `LICENSE` (AGPL-3.0-or-later) and `.gitignore`.
   - Run `detect-languages.sh --fix`, and set the `[phpunit]` values in `config.local.ini`.

   *Verification:* `composer cs:check`, `psalm` and `xmllint` are green; the app enables in harness instance 34.
2. **Remotes and CI.**
   - Gated: `gh repo create metaworx/large_file_previews --public`.
   - The user: creates the GitLab project and the push mirror to GitHub.
   - `.github/workflows/test.yml`: lint, and PHPUnit on NC 33/34 × PHP 8.2–8.4 and NC 35 × PHP 8.3–8.4,
     with `poppler-utils` installed. The composite actions it uses come with it.
   - `GUIDELINES/project/CI.md` from `CI.nextcloud-app.md`, and the hosting section in `CONTRIBUTING.md`.
   - Gated: the first push.

   *Verification:* the first run on GitHub is green.
3. **Certificate request.**
   - Key and CSR as above.
   - Gated: push the fork branch and open the upstream PR.

   *Verification:* the PR is open, and `openssl req -verify` passes on the CSR.
4. **Configuration and its commands:**
   - `ConfigLexicon`, `ConfigurationService`, `TypePolicy`, `Resolution` and the regex constant;
   - the four commands, registered in `info.xml`;
   - unit tests: lexicon keys pinned, precedence per field, size bounds (at min, at max,
     `-1`), refusals, invalid stored maps, the regex; `CommandTester` for each command.

   *Verification:* `config:get --ext tif --size 60mb` gives the expected sources and verdict.
5. **Command runner.**
   - `CommandRunner` builds the prefixed argv for each combination of limits, runs `proc_open`,
     and maps the outcome to `RenderOutcome`.
   - Tested with stub binaries in `tests/fixtures/bin/`: one writes a PNG, one exits non-zero,
     one sleeps past the timeout, one prints a warning and succeeds.

   *Verification:* the unit suite.
6. **Renderer and provider.**
   - `IRenderer`, `PopplerRenderer` and `LargeFileProvider`; the provider is registered in `Application`.
   - Decline paths at `debug`, failures at `warning`.

   *Verification:* unit tests, plus a manual `occ preview:generate` in the harness.
7. **Setup check and last failure.**
   - `RendererCheck`:
     - error when `pdftoppm` is missing, `proc_open` is disabled, or an enabled limit's tool is missing;
     - warning for a failure in the last 24 h.
   - The `last_failure` record is written by the provider.

   *Verification:* unit tests; the check shows on the harness admin overview.
8. **Admin settings page,** as in the Analysis.

   *Verification:* Vitest, `vue-tsc`, ESLint, Stylelint, the controller's unit tests, and the
   page saving and refusing in the harness.
9. **Integration tests.**
   - Gated, in `nextcloud_testing`: `poppler-utils` in the instances.
   - Here:
     - the ordering pin for `application/pdf`, `application/illustrator` and `image/tiff`;
     - a real render of small generated PDF and AI fixtures;
     - the decline paths and the setup check.

   *Verification:* the integration suite on 33, 34 and 35.
10. **Packaging and publish pipeline,** once the certificate has arrived:
    - `package.sh` and `.nc.publish.ignore`;
    - `publish.yml` and `install-check.yml`;
    - `docs/app-store-publishing.md`;
    - the `signing` and `appstore` environments with their secrets (the user);
    - registering the app id in the store (gated).

    *Verification:* an `-rc` tag runs the pipeline as far as the store.
11. **Manual test on jackal** (gated deployment), on the `nextcloud` instance first.
    Measure what `prlimit_as_mb` needs, and record it in a NOTE.

Step two (large images through `vipsthumbnail`) is a later plan, after block 11.

## Proposed commit messages

Registration: `[UPDATE] APs: +LargeFilePreviews v1.0`, with its `File-revision-v1` trailer.
Then one or more `[TASK]` commits per block, each with its tests and its `CHANGELOG.md`
bullet, written at the block's gate. CI and docs-only commits carry no bullet.

## Change History

- v1.0 (2026-10-13): first version.
