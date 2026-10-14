<!-- GENERATED FILE - DO NOT EDIT.
     Source: project/_README.md + project/_CONTRACT.md + shared/_README.md
     Regenerate: GUIDELINES/shared/tools/sync-docs.sh
     Contract version: v1.0.1 -->


# Large File Previews (v1.0.1)

Large File Previews is a Nextcloud app for instances that hold files too large for
Nextcloud's own preview providers - multi-gigabyte PDFs and very large images. It
renders the first page or a reduced image straight from the file where it lies with a
command-line renderer (`pdftoppm` for PDFs, libvips for images), so no file is loaded into
memory or sent over a socket, and every renderer runs under a time limit, an address-space
and file-size cap and a raised out-of-memory score. It is for administrators who run
Nextcloud on local storage and want sharp previews of archives such as scanned catalogues.

## Working on it

```bash
git clone --recurse-submodules git@gitlab.com:metaworx/open-source/nextcloud/large_file_previews.git
cd large_file_previews
composer install
```

The app is tested inside a `nextcloud_testing` instance, which mounts this checkout as
`apps/large_file_previews`; put that instance's directory in
`GUIDELINES/config.local.ini` under `[phpunit]` (see the contract below). The instance
needs `poppler-utils`, `libvips-tools` and `libtiff-tools` installed.

## The rules that apply here

The conventions below are not AI configuration:
they are how this project is written,
and an agent is simply another party required to follow them.

- The contract that follows is generated into `/AGENTS.md` as well,
  so people and agents are held to the same text.
- The shared conventions live in `GUIDELINES/shared` — commit policy,
  quality pass, per-language testing, linting and code style.
- `GUIDELINES/README.md` explains the gate protocol:
  what an agent asks before it changes anything,
  and what `EXEC` and its relatives authorise.

Both generated documents come from the fragments in `GUIDELINES`.
Editing a generated file is wasted work — change the fragment and re-run
`GUIDELINES/shared/tools/sync-docs.sh`.

---


# Large File Previews — Project Contract (v1.2.2)

What binds work in this project, for everyone working on it.
Inlined into `/AGENTS.md` for agents and into `GUIDELINES/README.md` for people,
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
> Machine-specific values belong in `GUIDELINES/config.local.ini`,
> which is not tracked.

| Fact                  | Value |
|-----------------------|-------|
| Project name          | Large File Previews, Nextcloud app id `large_file_previews`, PHP namespace `OCA\LargeFilePreviews\` |
| Language(s)           | declared as `project.languages` in `GUIDELINES/config.ini`, kept honest by `GUIDELINES/shared/tools/detect-languages.sh --check` |
| Source directories    | `lib/` (PSR-4 `OCA\LargeFilePreviews\`), `tests/` (PSR-4 `OCA\LargeFilePreviews\Tests\`), `appinfo/`, `templates/`; `src/`, the admin settings page in Vue 3 and TypeScript, with its Vitest specs beside each file, built by Vite into `js/` and `css/`, which are not tracked |
| Shipped-code paths    | declared as `project.shipped` in `GUIDELINES/config.ini` and listed below; kept honest by `GUIDELINES/shared/tools/changelog.sh check` (rule: `GUIDELINES/shared/COMMIT.md` §5) |
| Version manifest      | `appinfo/info.xml`: the `manifest` of `CHANGELOG.md` in `GUIDELINES/config.ini`, so `changelog.sh cut` sets its `<version>` and `check` holds it to the newest section |
| Test gate command     | `GUIDELINES/shared/lang/php/tools/phpunit` (the shipped wrapper; `composer test` inside the container or CI), with `MWX_PHPUNIT_DDEV_DIR` set to the harness instance's directory (§3) and `MWX_PHPUNIT_MOUNT=/var/www/html/apps/large_file_previews`, whose values this machine keeps in `GUIDELINES/config.local.ini` under `[phpunit]`; `--testsuite unit` or `integration` for one of them. The frontend: `npm test` (Vitest, mounting the real `@nextcloud/vue` components) and `npm run build` |
| Lint command          | `composer cs:check` / `composer cs:fix` (ECS with `mwx/coding-standard`, over `lib/`, `tests/`, `appinfo/` and `templates/`); `composer psalm` (Psalm in `vendor-bin/psalm`, over `lib/` at level 4 with no baseline); the manifest `xmllint --noout --schema info.xsd appinfo/info.xml`, with `info.xsd` fetched from `https://raw.githubusercontent.com/nextcloud/appstore/master/nextcloudappstore/api/v1/release/info.xsd`. Rector is the upgrade tool, not lint: `composer rector:check` / `composer rector` with Nextcloud's rules for the oldest supported version, in the harness container. The frontend: `npm run lint` (ESLint), `npm run typecheck` (`vue-tsc`, which checks `.vue` files as well as `.ts`) and `npm run stylelint` |

A commit touching any path listed below must carry a `CHANGELOG.md` bullet.
The list is generated from `project.shipped`,
so it cannot drift from what the check enforces - edit the config,
not this block:

<!-- BEGIN GENERATED SHIPPED-PATHS - do not edit; run "GUIDELINES/shared/tools/changelog.sh update-contract" -->
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
- `GUIDELINES/shared/QUALITY.md` — the quality pass over every changed file.
- `GUIDELINES/shared/lang/<language>/TESTING.md` and `LINTING.md` — language
  baselines for each language in `project.languages`;
  this file's facts table overrides their example commands.
  `GUIDELINES/shared/templates/examples/TESTING.nextcloud-app.md` and
  `CI.nextcloud-app.md` are worked examples for a Nextcloud app.
- `GUIDELINES/shared/COMMIT.md` — commit workflow, gating, message policy,
  and the `CHANGELOG.md` rules.
- `GUIDELINES/shared/ENVIRONMENTS.md` — host/agent command conventions.
- `CONTRIBUTING.md` — repository-specific contributor conventions.

## 3. Project-Specific Conventions

### 3.1 Nextcloud version matrix

The app supports Nextcloud 33 to 35 and PHP 8.2 and later. It is analysed against
`nextcloud/ocp` for the oldest supported release, and Rector runs that release's set.
Where server source trees are linked into the IDE for more than one release, it reports
`Multiple definitions exist for class '...'` in bulk; that is expected
(`GUIDELINES/shared/QUALITY.md` §6): filter the message, never exclude a tree.

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
No translations ship yet: adding them is tooling, not a change to the code.

### 3.5 CHANGELOG.md is mandatory here

The project keeps a Keep-a-Changelog `CHANGELOG.md` and cuts releases with `[RELEASE]`
commits that set `appinfo/info.xml`'s version. Any commit touching shipped code adds or
amends a bullet under `## [Unreleased]` in the same commit (`GUIDELINES/shared/COMMIT.md` §5).

### 3.6 The harness that tests this app is a separate repository

`nextcloud_testing` spins up the Nextcloud versions above and mounts this app into them,
in an instance of its own where another app uses the same version,
such as `34-lfp`.
Its instances need `poppler-utils`, `libvips-tools` and `libtiff-tools`
for the integration tests, in the instance's `.ddev/config.extra.yaml`;
the image tests are skipped where libvips is not there.
A change to how instances are built belongs there, not here.

## 4. Document Governance

- This document follows the shared governance rules in `GUIDELINES/shared/GOVERNANCE.md`.

---


# Working with the agent (v1.7.0)

This is the human half of the contract.
The agent's rules live in `/AGENTS.md`;
this explains what the agent will do to you, what the words it sends you mean,
and what it needs back.

## 1. The short version

The agent does not change your repository and then tell you.
For anything non-trivial it **stops and asks first**,
in a fixed format called a *gate message*, and waits.
You reply with one word — usually `EXEC` — and it proceeds.

Two moments always stop:
before a non-trivial code change (you approve the plan) and before every
`git commit` (you approve the message).
Everything else is ordinary work.

## 2. Gate messages

A gate message is the agent's request for permission.
It always carries the same fields, so you can skim it:

- **Checkpoint** — that this is a stop, not a status update
- **Overall Task** — what you asked for, as the agent understood it
- **Last Action** — what it just did,
  sometimes as bullets where that was several things
- **Pending action** — exactly what it wants to do next
- **CHANGELOG bullet** — the line the change will add to the changelog
- **Proposed commit message** — the exact message,
  wherever a signal would commit
- **Files to be committed** — what would go into that commit
- **Important notes** — anything you need in order to decide and would not otherwise see:
  a bug fixed on the way, something to check before you answer,
  a step that went differently than planned
- **Confirmation needed** — which signal it is waiting for,
  and what each one does

After sending one the agent must be silent until you answer.
If you see it send a gate and then keep working,
that is a violation of its own contract, and worth saying so.

Read the **Pending action** first.
If it does not match what you wanted,
say so in plain words rather than sending a signal — the agent revises and re-gates.

## 3. Execution signals

One word, at the start or anywhere in your reply:

| Signal      | Means |
|-------------|-------|
| `EXEC`      | Do the gated action. Only that. |
| `EXEC+`     | Do it, then carry on with the next planned step. |
| `ROLLBACK`  | Undo the last edit. Required — the agent may not undo unasked. |
| `ERR`       | That went wrong; stop and diagnose before trying again. |
| `PLAN`      | Produce or revise the plan; do not execute yet. |
| `ASK`       | Answer my question; do not treat it as an instruction to act. |

The gate always tells you what `EXEC` will do and where `EXEC+` will go next.
If it doesn't, that is a defect in the gate, not something for you to infer.

`EXEC++` and `EXEC+++` are retired — say it in words instead.

You can also just answer in prose.
"Yes, but rename the second one first" is a perfectly good reply;
the signals exist for speed, not ceremony.

## 4. Action Plans

For anything beyond a trivial edit the agent writes an **Action Plan** before
touching code: what it found, what it intends to do step by step,
and the commit message it expects to propose.
You see a summary in the gate;
the full plan is written to a file (see §5) so it survives the conversation.

Plans are versioned — `AP Bild v1.2` — and each revision is a new file,
never an overwrite.
If you reject a plan, the next one is v1.3 and the reasoning that led there is
still on disk.

## 5. UAMF — where the agent's writing goes

A **User-Accessible Message File** is anything the agent wrote for you to read
rather than for the machine to run: action plans, analyses, migration notes.
They land in `GUIDELINES/messages/` with a timestamp in the name,
and they are **never overwritten** — a revision is always a new file.

This matters when a session ends badly.
The conversation may be gone; the reasoning is still there.

## 6. Recovering from a bad turn

Send `ERR`.
The agent stops and replies with a compact recovery block:
what it believes happened, what state things are in, and what it proposes.
Send `ERR` twice in a row and it escalates — it stops proposing and asks you to direct it.

`ROLLBACK` is separate and stronger: it authorises undoing the last edit.
The agent cannot do this on its own initiative, by design,
so if something needs reverting you have to say so.

## 7. What the agent must never do without you

- Commit — every commit is gated, with the full message shown first
- Undo your work — `ROLLBACK` only
- Overwrite a UAMF — revisions are new files
- Delete and recreate a file to make a large edit — it edits in place,
  so your IDE's local history survives
- Push, or anything else that leaves your machine, unless you asked

If any of these happens without your say-so, the agent has broken its contract.
Tell it; the rule it violated is in `/AGENTS.md` and it can name the section.

## 8. If this directory looks empty

Everything under `GUIDELINES/shared` lives in a git submodule,
which a plain clone leaves unpopulated.
The document you are reading is committed in the project,
so it survives that — but every link into `GUIDELINES/shared` will be dead until:

```bash
git submodule update --init GUIDELINES/shared
```

Cloning with `--recurse-submodules` does it up front.
In an overlay - a `GUIDELINES` that is a repository of its own,
in a project that does not carry the guidelines -
this document and the submodule are the overlay's,
and the command is run there:
`git -C GUIDELINES submodule update --init`.
If the same checkout is used from both Windows and WSL,
this saves a recurring annoyance:

```bash
git -C GUIDELINES/shared config core.fileMode false
```

## 9. Document Governance

- This document follows the shared governance rules in `GUIDELINES/shared/GOVERNANCE.md`.
