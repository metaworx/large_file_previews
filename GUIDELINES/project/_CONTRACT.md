> **Fragment** — inlined by `tools/sync-docs.sh`; not a standalone document.

# {{project_name}} — Project Contract (v1.0.0)

<!-- TEMPLATE: installed as {{guidelines_root}}/project/_CONTRACT.md. Replace
     every {{TODO: …}}, delete rows that do not apply. Shared documents
     never name a project or carry a project path - they reference this file. -->

What binds work in this project, for everyone working on it.
Inlined into `/AGENTS.md` for agents and into `{{guidelines_root}}/README.md` for people,
so that the two cannot drift apart.

> `{{TODO: …}}` is a value you replace, braces and all.
> The other double-braced names, such as `{{project_name}}`,
> are filled in when the documents are generated: leave them as they are.

## 1. Project Facts

> No absolute paths here.
> An agent is already inside the checkout,
> so the repository root is `git rev-parse --show-toplevel`;
> a checkout can live anywhere and this file is committed.
> Machine-specific values belong in `{{guidelines_root}}/config.local.ini`,
> which is not tracked.

| Fact                  | Value |
|-----------------------|-------|
| Project name          | {{project_name}} |
| Language(s)           | declared as `project.languages` in `{{guidelines_root}}/config.ini`, kept honest by `{{shared_root}}/tools/detect-languages.sh --check` |
| Source directories    | {{TODO: e.g. src/, lib/}} |
| Shipped-code paths    | declared as `project.shipped` in `{{guidelines_root}}/config.ini` and listed below; kept honest by `{{shared_root}}/tools/changelog.sh check` (rule: `{{shared_root}}/COMMIT.md` §5) |
| Version manifest      | {{TODO: e.g. appinfo/info.xml, package.json, composer.json}} |
| Test gate command     | {{TODO: exact command}} |
| Lint command          | {{TODO: exact command}} |

A commit touching any path listed below must carry a `CHANGELOG.md` bullet.
The list is generated from `project.shipped`,
so it cannot drift from what the check enforces - edit the config,
not this block:

<!-- BEGIN GENERATED SHIPPED-PATHS - do not edit; run "{{shared_root}}/tools/changelog.sh update-contract" -->
_Not generated yet. Declare `project.shipped` in `{{guidelines_root}}/config.ini`,
then run `{{shared_root}}/tools/changelog.sh update-contract`._
<!-- END GENERATED SHIPPED-PATHS -->

## 2. Primary References

- `/AGENTS.md` — runtime behavior contract, gating flow, action-plan workflow.
- `{{shared_root}}/lang/<language>/TESTING.md` and `LINTING.md` — language
  baselines for each language in `project.languages`;
  this file's facts table overrides their example commands.
- `{{shared_root}}/COMMIT.md` — commit workflow, gating, message policy.
- `{{shared_root}}/ENVIRONMENTS.md` — host/agent command conventions.
- `CONTRIBUTING.md` — repository-specific contributor conventions.

## 3. Project-Specific Conventions

{{TODO: anything that deviates from or extends the shared documents;
include host quirks other agents would trip over}}

## 4. Document Governance

- This document follows the shared governance rules in `{{shared_root}}/GOVERNANCE.md`.
