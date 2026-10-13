<!-- GENERATED FILE - DO NOT EDIT.
     Source: shared/_AGENTS.md + project/_CONTRACT.md
     Regenerate: GUIDELINES/shared/tools/sync-docs.sh
     Contract version: v3.19.0 -->


# AI Agent Guidelines (v3.19.0)

Core behavioral rules for AI agents working on this codebase.  
All agents MUST comply.
This document is intentionally concise;
refer to linked documents for extended guidance.

## Contents

<!-- BEGIN GENERATED CONTENTS - do not edit; run "GUIDELINES/shared/tools/sync-docs.sh" -->
- AI Agent Guidelines (v3.19.0)
  1. Critical Behavioral Rules (STRICT)
    - 1.1 Gate Message Mechanism
    - 1.2 Action Plan (AP) Requirement
    - 1.3 Commit Confirmation Gate
    - 1.4 `undo_edit` Authorization (`ROLLBACK`)
    - 1.5 Safe File Edits
  2. Instruction Precedence (STRICT)
    - 2.1 Rule Map & Canonical Owners (STRICT)
  3. Task Interpretation & Keywords
    - 3.1 First Response Contract
  4. User-Accessible Message Files (UAMF)
  5. Action Plan (AP)
    - 5.1 Format & Versioning
    - 5.2 Required Sections
    - 5.3 Persistence (UAMF)
    - 5.4 Notes
  6. Commit Policy (STRICT)
  7. Additional References
  8. Document Governance
- Large File Previews — Project Contract (v1.0.0)
  1. Project Facts
  2. Primary References
  3. Project-Specific Conventions
    - 3.1 Nextcloud version matrix
    - 3.2 Public API only, and an inventory of what is not
    - 3.3 Running external programs
    - 3.4 Every user-facing text is translatable
    - 3.5 CHANGELOG.md is mandatory here
    - 3.6 The harness that tests this app is a separate repository
  4. Document Governance
<!-- END GENERATED CONTENTS -->

## 1. Critical Behavioral Rules (STRICT)

### 1.1 Gate Message Mechanism

- After sending a gate message, the agent MUST **stop** — no further actions,
  shell commands,
  or status updates are permitted until the user responds with a new `<issue_update>`.
- What a gate message is, and the Pause Latch and No-Input rules,
  are `GUIDELINES/shared/GATE_WORKFLOW.md` §3.
- The gate message MUST follow the **Universal Gate Template**
  (`GUIDELINES/shared/GATE_WORKFLOW.md` §4), which owns its fields.
- The gate MUST state, in the user's terms,
  **what each signal it offers will do**.
  A signal the user cannot predict the effect of is not consent.
  Which signals a gate offers, `EXEC+` and variants of the gate's own included,
  is `GUIDELINES/shared/GATE_WORKFLOW.md` §6.
- The signals are listed as text, at the end of the gate message itself.
  A runtime that can end a turn only through a tool call, as Roo's can,
  carries a short label there and nothing more:
  the gate is the text, and the tool is not where the choice is explained.
- A gate message is **brief**: what the user needs in order to decide.
  How the work went is not retold; `GUIDELINES/shared/GATE_WORKFLOW.md` §4.
- A gate message is the **only** valid way to request user confirmation.
  Echoing "waiting for input" via shell commands is a **violation** of this rule.
- A visual workflow diagram is available in `GUIDELINES/shared/GATE_WORKFLOW.md`.

### 1.2 Action Plan (AP) Requirement

- Every non‑trivial `[CODE]` task requires an Action Plan.
- Full AP format and versioning rules are defined in [§5](#5-action-plan-ap).
- Each AP revision MUST be persisted as a
  [UAMF](#4-user-accessible-message-files-uamf) **before** any project writes.
- Unless the execution is directly authorized by an `EXEC`‑family keyword in the
  same user message,
  the AP MUST be presented as a **gate message** (see [§1.1](#11-gate-message-mechanism)).

### 1.3 Commit Confirmation Gate

- Before `git commit`, the agent MUST present a commit gate.
  [`GUIDELINES/shared/COMMIT.md`](GUIDELINES/shared/COMMIT.md) §7.1 states what it
  must contain, and §7.2 the commands that follow it.
- A run under `EXEC++` or `PLAN++` commits under that signal instead,
  within the scope it was given (COMMIT.md §7.1).

### 1.4 `undo_edit` Authorization (`ROLLBACK`)

- A rollback action (calling `undo_edit`) requires explicit user authorization.
  `GUIDELINES/shared/GATE_WORKFLOW.md` §7.3 states what authorizes it and what the
  gate must say where authorization is absent.

### 1.5 Safe File Edits

- **Do not delete and recreate files** when making large changes.
  Instead, write the new content to a temporary file and atomically replace the
  original (`mv` on Linux/macOS, `Move-Item` on Windows).
  This preserves local IDE history.
- Temporary files SHOULD be placed in `GUIDELINES/temp/`,
  which is scratch: it may be deleted wholesale at any time.
  User-visible artifacts belong in `GUIDELINES/messages/`,
  which is durable and never purged.
  Why the two are siblings is in `GUIDELINES/README.md`.
- Always prefer history‑preserving edit tools (e.g., `apply_patch`,
  in‑place edits) over raw shell writes.

## 2. Instruction Precedence (STRICT)

1. Runtime/system rules (conflicts noted explicitly).
2. Direct user instruction (current session).
3. This document (the shared agent contract).
4. Local conventions

**IMPORTANT:**

- Writing *new* [UAMF](#4-user-accessible-message-files-uamf) messages does NOT
  constitute a source-file edit.
- Same for writing to .aiassistant/temp to create temporary files during
  evaluation (e.g. a temporary test file)
- BOTH are explicitly ALLOWED also in PLANNING-ONLY mode.

### 2.1 Rule Map & Canonical Owners (STRICT)

| Need                                                                                      | Canonical location              |
|-------------------------------------------------------------------------------------------|---------------------------------|
| Gate lifecycle, execution signals, pause behavior, mixed-signal gate handling, `ERR` recovery, the Universal Gate Template | `GUIDELINES/shared/GATE_WORKFLOW.md` |
| Commit gate, commit message format and shape, changelog obligation, commit tooling | `GUIDELINES/shared/COMMIT.md`        |
| Test depth and commands                                                                   | `GUIDELINES/shared/lang/<language>/TESTING.md`       |
| Quality pass over changed source files, of any language                                   | `GUIDELINES/shared/QUALITY.md` |
| Lint/style commands and policy                                                            | `GUIDELINES/shared/lang/<language>/LINTING.md`       |
| Document governance rules and canonical history ownership                                 | `GUIDELINES/shared/GOVERNANCE.md`     |
| Agent-host command/tool-name conventions (Windows-hosted vs WSL-based, per-agent specifics) | `GUIDELINES/shared/ENVIRONMENTS.md`  |
| CI conventions: what the pipeline runs, in what order, and why                             | `GUIDELINES/project/CI.md` |
| AP requirements (`MUST`)                                                                  | this document §1.2 and §5      |

If overlap exists, follow the canonical owner document for that rule family.

## 3. Task Interpretation & Keywords

- **`ASK`** – Standalone: Send `<answer>` in [chat] mode.
  Combined with other keywords:
  include answer in new gate message (if task-relevant) or with the result of
  the gated action.
- **`PLAN`** – Investigate, and present the preliminary findings as a TL;DR gate
  (`GUIDELINES/shared/GATE_WORKFLOW.md` §7.1) before any plan file is written.
  Where the user's message already settles the design, write the AP at once,
  as `PLAN+` does.
  - **`PLAN+`** – Write the AP revision without a TL;DR gate, persist it,
    and send the plan gate.
  - **`PLAN++`** – `PLAN+`, then carry on: register the plan and execute it,
    as far as the scope the agent states for it before it starts -
    a retirement, a release or a push included where stated (§1.1).
- **`EVAL`** – Evaluate the proposals and questions in the message:
  weigh each, recommend with the reason,
  and say what is settled and what is still open.
  It writes no plan revision and resumes no work - mid-run included -
  whatever state the work is in:
  only an `EXEC` or `PLAN` signal does.
  That is what makes a message carrying it safe to send.
  A long evaluation MAY go to a UAMF in `GUIDELINES/messages/`.
- **`EXEC`** – Execute gated action(s).
  The signals and their scope are `GUIDELINES/shared/GATE_WORKFLOW.md` §6.
- **`ROLLBACK`** – Authorize an `undo_edit` action (see
  [§1.4](#14-undo_edit-authorization-rollback)).
- **`ERR`** – Apply recovery protocol (detailed in `GUIDELINES/shared/GATE_WORKFLOW.md`).
- **`UAMF`** – Instructs agent to write a
  [UAMF](#4-user-accessible-message-files-uamf) message file.

Latest `<issue_update>` overrides earlier `<issue_description>`.

### 3.1 First Response Contract

- Non-trivial `[CODE]` task without inline `EXEC`: produce AP, persist AP UAMF,
  send gate - after a TL;DR gate where `PLAN` asks for one (§3).
- A reply to a plan gate that carries no keyword is an `EVAL`:
  its proposals and questions are weighed, and the plan is not revised
  until a `PLAN` signal says so.
- If the same user message includes clear execution authorization (`EXEC` family):
  execute only the authorized scope.
- Before `git commit`: always send a commit gate with full proposed commit message.

## 4. User-Accessible Message Files (UAMF)

A UAMF is a stored artefact, written for the user to read,
that follows strict rules:

- Its name begins with a timestamp, `YYYY-MM-DD_HH-NN_`,
  then describes its subject.
- It is **never overwritten**.
  A revision is a new file carrying a new version in its name;
  the previous one stays where it is.
- Within the agent turn that writes it, the agent MAY still edit it:
  to fill in a copy of the previous revision it started from,
  or to correct a mistake it notices after writing.
  That ends once work based on it has started,
  or it has been presented to the user.

Where a file is written depends on what it is and how long it needs to live:

| Directory | Tracked | For |
|-----------|---------|-----|
| `GUIDELINES/messages/` | no  | the default: analyses, findings and records written for the user |
| `GUIDELINES/wip/`      | yes | the Action Plan driving the change in progress, and any analysis that change depends on |
| `GUIDELINES/temp/`     | no  | scratch that is not a UAMF at all |

In an overlay - a guidelines root that is a repository of its own,
in a project that does not carry the guidelines -
tracked means tracked by the overlay,
and a plan is committed there (`GUIDELINES/shared/COMMIT.md` §7.3).

Citation rules follow from that, and they are absolute:

- **`messages/` is never cited.**
  Not from a document, not from a commit message, not from another UAMF.
  It is untracked: it exists in one working copy and nobody else can follow the reference.
- **`wip/` may be cited from another `wip/` document** — an Action Plan naming
  the analysis it rests on — **and from a commit message**,
  which is immutable and dated,
  so it names something that existed then and that git can still produce.
- **Nothing that outlives the change may cite either.**
  If a conclusion is worth citing from a contract,
  a shared document or a README, it belongs *in* that document.

## 5. Action Plan (AP)

### 5.1 Format & Versioning

- Title: `AP {topic} v{Major}.{Minor}: {2-5 word description}`
    - `{topic}` is a 1-3 word PascalCase slug describing the AP's subject (e.g. `Bild`,
      `Mock`, `DecisionEngine`, `ECSFixers`).
    - It is **not** a workflow signal — `PLAN`, `EXEC`, `ASK`, `ROLLBACK`,
      and `ERR` are user-facing keywords from §3, not AP title components.
- Examples: `AP Bild v1.0: Extract decision functions`,
  `AP FilterFix v1.0: Fix type validation`.
- Increment the version on every revision.
  A NOTE (§5.4) is not a revision, and leaves the version as it is.
- Retain cumulative `Change History` within the AP document (append‑only).

### 5.2 Required Sections

- **TL;DR**, first after the title:
  what the plan does and how, for a reader with half a minute.
  - One or two sentences;
    then bullets in telegram style - keywords, `→` from before to after,
    no sentence where a phrase does;
    then at most a few short paragraphs, or one table.
    Unlike a commit's `Key changes` (`GUIDELINES/shared/COMMIT.md` §3.2),
    these bullets are scanned, not read.
  - It SHOULD stay within about 150 words.
  - It MUST include `### Blocks`:
    every block of the Implementation Plan, numbered as there, one line each,
    and outside that budget.
  - It summarises and adds nothing.
    Where it and the body differ, the body holds.
  - Each revision rewrites it for the plan as it then stands.
- **Discussion** (if any)
- **Analysis**
- **Implementation Plan** (step‑by‑step;
  include a **Verification** checkpoint after each logical block)
- **Proposed commit message** (for changes since the session start or last commit)
- **Change History** (all previous version entries)

### 5.3 Persistence (UAMF)

- While a plan is open, every message the agent sends starts with a link to
  its newest revision, and to that revision's NOTEs - not to every file of it -
  so that nobody has to look for it.
- Write each AP revision as a [UAMF](#4-user-accessible-message-files-uamf) in
  `GUIDELINES/wip/` before modifying any project files,
  and commit it before any commit names a block of it.
  A plan that exists only in one working copy cannot be reviewed,
  and a commit that cites it would be citing nothing.
- Registering, noting and retiring an AP - which commit carries each,
  when a plan may retire, what stays reachable afterwards,
  and the MUST NOT retire on the agent's own judgement -
  is `GUIDELINES/shared/COMMIT.md` §6.

### 5.4 Notes

A NOTE records a small change or a decision beside its plan,
without a new revision.

- Its **name** is `YYYY-MM-DD_HH-NN_NOTE_<Name>_v<X.Y>_<slug>.md`,
  in `GUIDELINES/wip/` beside the plan,
  where `<Name>` and `<X.Y>` are those of the plan revision it amends.
- Its **title** is `NOTE <Name> v<X.Y>: <slug words>`.
- It is **for** a decision, a measured fact,
  or a change to a step while the plan's blocks stand.
  Where the blocks change, it SHOULD be a new revision instead.
- It **records** where the work went differently from the plan,
  where that matters; the completion gate names it
  (`GUIDELINES/shared/GATE_WORKFLOW.md` §7.7).
  A run's deviations MAY be collected in one NOTE, committed at its end,
  rather than one at each step.
- It is a **UAMF** like any other, never overwritten,
  so a correction is another NOTE.
- Its **record** is a `File-revision-v1` trailer in the commit that adds it,
  carrying the version of the plan revision it amends, which its title names.
- It **stays** until its plan retires,
  which takes every revision and every NOTE of the plan together.
  A later revision neither replaces it nor folds it in.

## 6. Commit Policy (STRICT)

The rules are [`GUIDELINES/shared/COMMIT.md`](GUIDELINES/shared/COMMIT.md),
and an agent committing here is held to all of them:
§2 one functional change with its tests, §3 the message and its shape,
§4 the rules a tag carries, §5 the `CHANGELOG.md` obligation, §7 the gate,
the signals and the commands.

## 7. Additional References

Paths below use two placeholders,
substituted when this document is inlined into a project's `AGENTS.md`:
`GUIDELINES` is the project's agent directory and `GUIDELINES/shared` is
the shared guidelines submodule.
Their values come from `GUIDELINES/config.ini`.

- **This document, below** – project facts: paths, versions,
  exact test and lint commands.
  They are inlined here from the project's `project/_CONTRACT.md`.
- `GUIDELINES/shared/COMMIT.md` – commit workflow, gating,
  message and changelog policy.
- `GUIDELINES/shared/GATE_WORKFLOW.md` – gate lifecycle and `ERR` recovery protocol.
- `GUIDELINES/shared/ENVIRONMENTS.md` – agent-host command and tool-name conventions.
- `GUIDELINES/shared/GOVERNANCE.md` – document versioning and history rules.
- `GUIDELINES/shared/QUALITY.md` – the quality pass to run over every changed source file.
- `GUIDELINES/shared/lang/<language>/TESTING.md`, `LINTING.md` and,
  where the language has one, `CLI.md` – language baselines.
- `GUIDELINES/shared/tools/README.md`,
  `GUIDELINES/shared/tools/RUNTIME_TOOLS.md` – helper scripts and runtime tools.
- `GUIDELINES/project/CI.md` – CI conventions, if the project has any.

## 8. Document Governance

- Version updated on every change (SemVer).
- History follows `GUIDELINES/shared/GOVERNANCE.md` §1.2: one line per version,
  in the commit that made it.
- Drift-check: when a specialized rule document changes ownership semantics,
  update §2.1 in the same change.

---


# Large File Previews — Project Contract (v1.0.0)

What binds work in this project, for everyone working on it.
Inlined into `/AGENTS.md` for agents and into `GUIDELINES/README.md` for people,
so that the two cannot drift apart.

A Nextcloud app, app id `large_file_previews`, that renders previews of files too large for
Nextcloud's own providers by running a command-line renderer on the file's local path:
`pdftoppm` (poppler) for PDF and Illustrator files first, libvips for large images later.

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
| Source directories    | `lib/` (PSR-4 `OCA\LargeFilePreviews\`), `tests/` (PSR-4 `OCA\LargeFilePreviews\Tests\`), `appinfo/`; `src/` and `templates/` only if a settings page is ever added |
| Shipped-code paths    | declared as `project.shipped` in `GUIDELINES/config.ini` and listed below; kept honest by `GUIDELINES/shared/tools/changelog.sh check` (rule: `GUIDELINES/shared/COMMIT.md` §5) |
| Version manifest      | `appinfo/info.xml`: the `manifest` of `CHANGELOG.md` in `GUIDELINES/config.ini`, so `changelog.sh cut` sets its `<version>` and `check` holds it to the newest section |
| Test gate command     | `GUIDELINES/shared/lang/php/tools/phpunit` (the shipped wrapper; `composer test` inside the container or CI), with `MWX_PHPUNIT_DDEV_DIR` set to the harness instance's directory (§3) and `MWX_PHPUNIT_MOUNT=/var/www/html/apps/large_file_previews`, whose values this machine keeps in `GUIDELINES/config.local.ini` under `[phpunit]`; `--testsuite unit` or `integration` for one of them |
| Lint command          | `composer cs:check` / `composer cs:fix` (ECS with `mwx/coding-standard`, over `lib/`, `tests/` and `appinfo/`); `composer psalm` (Psalm in `vendor-bin/psalm`, over `lib/` at level 4 with no baseline); the manifest `xmllint --noout --schema info.xsd appinfo/info.xml`, with `info.xsd` fetched from `https://raw.githubusercontent.com/nextcloud/appstore/master/nextcloudappstore/api/v1/release/info.xsd`. Rector is the upgrade tool, not lint: `composer rector:check` / `composer rector` with Nextcloud's rules for the oldest supported version, in the harness container |

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
- The renderer's path is found with `OCP\IBinaryFinder`. It is never read from app config:
  a Nextcloud administrator can set app config over the provisioning API without any shell
  access, and a path set that way would be code execution as the web server's user.
- The limits that wrap it - `timeout`, `prlimit --as`, `choom -n` - are each switchable and
  each has a configurable value with a default (the app's `ConfigLexicon`).
- Success is judged by the exit status and the output file, not by stderr being empty.
- Only a local, unencrypted file is rendered; anything else is declined, so the file is never
  copied to a temporary file first.

### 3.4 Every user-facing text is translatable

A text a person reads - a setup check's message, a settings page should one come - goes
through `IL10N` in PHP and `t()`/`n()` in the frontend. Logs and command output stay English.

### 3.5 CHANGELOG.md is mandatory here

The project keeps a Keep-a-Changelog `CHANGELOG.md` and cuts releases with `[RELEASE]`
commits that set `appinfo/info.xml`'s version. Any commit touching shipped code adds or
amends a bullet under `## [Unreleased]` in the same commit (`GUIDELINES/shared/COMMIT.md` §5).

### 3.6 The harness that tests this app is a separate repository

`nextcloud_testing` spins up the Nextcloud versions above and mounts this app into them.
Its instances need `poppler-utils` (and, once images are handled, `libvips-tools`) for the
integration tests; a change to how instances are built belongs there, not here.

## 4. Document Governance

- This document follows the shared governance rules in `GUIDELINES/shared/GOVERNANCE.md`.
