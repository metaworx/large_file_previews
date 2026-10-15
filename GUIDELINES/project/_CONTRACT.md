> **Fragment** — inlined by `tools/sync-docs.sh`; not a standalone document.

# {{project_name}} — Project Contract (v1.2.3)

What binds work in this project, for everyone working on it.
Inlined into `/AGENTS.md` for agents and into `{{guidelines_root}}/README.md` for people,
so that the two cannot drift apart.

A Nextcloud app, app id `large_file_previews`,
that renders previews of files too large for Nextcloud's own providers
by handing the file, where it lies, to a command-line renderer:
`pdftoppm` (poppler) for PDF and Illustrator files, libvips for large images.

## 1. Project Facts

> No absolute paths here.
> An agent is already inside the checkout,
> so the repository root is `git rev-parse --show-toplevel`;
> a checkout can live anywhere and this file is committed.
> Machine-specific values belong in `{{guidelines_root}}/config.local.ini`,
> which is not tracked.

| Fact                  | Value |
|-----------------------|-------|
| Project name          | {{project_name}}, Nextcloud app id `large_file_previews`, PHP namespace `OCA\LargeFilePreviews\` |
| Language(s)           | declared as `project.languages` in `{{guidelines_root}}/config.ini`, kept honest by `{{shared_root}}/tools/detect-languages.sh --check` |
| Source directories    | `lib/` (PSR-4 `OCA\LargeFilePreviews\`), `tests/` (PSR-4 `OCA\LargeFilePreviews\Tests\`), `appinfo/`, `templates/`; `src/`, the admin settings page in Vue 3 and TypeScript, with its Vitest specs beside each file, built by Vite into `js/` and `css/`, which are not tracked |
| Shipped-code paths    | declared as `project.shipped` in `{{guidelines_root}}/config.ini` and listed below; kept honest by `{{shared_root}}/tools/changelog.sh check` (rule: `{{shared_root}}/COMMIT.md` §5) |
| Version manifest      | `appinfo/info.xml`: the `manifest` of `CHANGELOG.md` in `{{guidelines_root}}/config.ini`, so `changelog.sh cut` sets its `<version>` and `check` holds it to the newest section |
| Test gate command     | `{{shared_root}}/lang/php/tools/phpunit` (the shipped wrapper; `composer test` inside the container or CI), with `MWX_PHPUNIT_DDEV_DIR` set to the harness instance's directory (§3) and `MWX_PHPUNIT_MOUNT=/var/www/html/apps/large_file_previews`, whose values this machine keeps in `{{guidelines_root}}/config.local.ini` under `[phpunit]`; `--testsuite unit` or `integration` for one of them. The frontend: `npm test` (Vitest, mounting the real `@nextcloud/vue` components) and `npm run build` |
| Lint command          | `composer cs:check` / `composer cs:fix` (ECS with `mwx/coding-standard`, over `lib/`, `tests/`, `appinfo/` and `templates/`); `composer psalm` (Psalm in `vendor-bin/psalm`, over `lib/` at level 4 with no baseline); the manifest `xmllint --noout --schema info.xsd appinfo/info.xml`, with `info.xsd` fetched from `https://raw.githubusercontent.com/nextcloud/appstore/master/nextcloudappstore/api/v1/release/info.xsd`. Rector is the upgrade tool, not lint: `composer rector:check` / `composer rector` with Nextcloud's rules for the oldest supported version, in the harness container. The frontend: `npm run lint` (ESLint), `npm run typecheck` (`vue-tsc`, which checks `.vue` files as well as `.ts`) and `npm run stylelint` |

A commit touching any path listed below must carry a `CHANGELOG.md` bullet.
The list is generated from `project.shipped`,
so it cannot drift from what the check enforces - edit the config,
not this block:

<!-- BEGIN GENERATED SHIPPED-PATHS - do not edit; run "{{shared_root}}/tools/changelog.sh update-contract" -->
- `lib/`
- `src/`
- `css/`
- `js/`
- `l10n/`
- `templates/`
- `img/`
- `appinfo/`
- `:(exclude,glob)*`
- `:!tests/`
- `:!docs/`
- `:!scripts/`
- `:!translationfiles/`
- `:!vendor-bin/`
- `:!GUIDELINES/`
- `:!.github/`
- `:!.gitlab/`
- `:!.idea/`
<!-- END GENERATED SHIPPED-PATHS -->

## 2. Primary References

- `/AGENTS.md` — runtime behavior contract, gating flow, action-plan workflow.
- `{{shared_root}}/QUALITY.md` — the quality pass over every changed file.
- `{{shared_root}}/lang/<language>/TESTING.md` and `LINTING.md` — language
  baselines for each language in `project.languages`;
  this file's facts table overrides their example commands.
  `{{shared_root}}/templates/examples/TESTING.nextcloud-app.md` and
  `CI.nextcloud-app.md` are worked examples for a Nextcloud app.
- `{{shared_root}}/COMMIT.md` — commit workflow, gating, message policy,
  and the `CHANGELOG.md` rules.
- `{{shared_root}}/ENVIRONMENTS.md` — host/agent command conventions.
- `CONTRIBUTING.md` — repository-specific contributor conventions.

## 3. Project-Specific Conventions

### 3.1 Nextcloud version matrix

The app supports Nextcloud 33 to 35 and PHP 8.2 and later. It is analysed against
`nextcloud/ocp` for the oldest supported release, and Rector runs that release's set.
Where server source trees are linked into the IDE for more than one release, it reports
`Multiple definitions exist for class '...'` in bulk; that is expected
(`{{shared_root}}/QUALITY.md` §6): filter the message, never exclude a tree.

### 3.2 Public API only, and an inventory of what is not

The app uses `OCP\` only. It implements `OCP\Preview\IProviderV2` directly and never
extends `OC\Preview\ProviderV2`. Two behaviours the app depends on are internal to the
server, and each is pinned by an integration test and re-checked for every new Nextcloud
release:

- providers are tried in the order of their MIME regex's length, longest first
  (`PreviewManager::getProviders()`), so the app's regex must stay the longest of any
  provider on the instance for the types it registers;
- a provider that throws ends the chain for that file with a server error, so the provider
  never throws: every failure returns `null`, and the next provider is tried.

### 3.3 Running external programs

- A program is started with `proc_open()` and an argument **array**, never a shell string.
- A program's path is found with `OCP\IBinaryFinder`,
  or overridden in system config (`large_file_previews.binaries` in `config.php`),
  and nowhere else.
  It is never read from app config:
  a Nextcloud administrator can set app config over the provisioning API without any shell access,
  and a path set that way would be code execution as the web server's user.
  Whoever can write `config.php` can run code already.
  An override that is not an executable file leaves its program missing;
  it does not fall back to the finder's.
- The limits that wrap it - `timeout`, `prlimit --as --fsize`, `choom -n` -
  are each switchable and each has a configurable value with a default
  (the app's `ConfigLexicon`).
  A limit switched on whose program is missing fails the render:
  it fails closed.
- A user's file reaches a program as an open descriptor, `/dev/fd/<n>`,
  never by its name,
  so no name can be read as an option or forge what the program prints.
- Success is judged by the exit status and the output file, not by stderr being empty.
- Only a local, unencrypted file is rendered; anything else is declined,
  so the file is never copied to a temporary file first.
  The one copy the app makes is the re-striped TIFF of the one-strip fallback,
  an unlinked file in the scratch directory.

### 3.4 Every user-facing text is translatable

A text a person reads - a setup check's message, the settings page, a refusal it shows -
goes through `IL10N` in PHP and `t()` in the frontend (`src/l10n.ts`),
and ESLint's `vue/no-bare-strings-in-template` holds the templates to it.
Logs and command output stay English.
German ships, as `de` (du) and `de_DE` (Sie),
built into `l10n/` from `translationfiles/` by `scripts/l10n.sh`:
`update` merges a changed text into each language,
`build` writes `l10n/`,
and `check` fails while either is out of date or incomplete.
A language's terms and registers are in `translationfiles/<lang>/GLOSSARY.md`,
which a new text follows.
`appinfo/info.xml` carries its summary and description in German itself (`lang="de"`),
so the tool leaves both out of the template and `check` cannot see them:
a change to the English changes the German in the same commit.

### 3.5 CHANGELOG.md is mandatory here

The project keeps a Keep-a-Changelog `CHANGELOG.md` and cuts releases with `[RELEASE]`
commits that set `appinfo/info.xml`'s version. Any commit touching shipped code adds or
amends a bullet under `## [Unreleased]` in the same commit (`{{shared_root}}/COMMIT.md` §5).

### 3.6 The harness that tests this app is a separate repository

`nextcloud_testing` spins up the Nextcloud versions above and mounts this app into them,
in an instance of its own where another app uses the same version,
such as `34-lfp`.
Its instances need `poppler-utils`, `libvips-tools` and `libtiff-tools`
for the integration tests, in the instance's `.ddev/config.extra.yaml`;
the image tests are skipped where libvips is not there.
A change to how instances are built belongs there, not here.

## 4. Document Governance

- This document follows the shared governance rules in `{{shared_root}}/GOVERNANCE.md`.
