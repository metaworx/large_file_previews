# Contributing to Large File Previews

This document holds the repository's own contributor conventions.
What binds every change - commits, changelog, plans, gates -
is in [`GUIDELINES/README.md`](GUIDELINES/README.md) for people
and [`AGENTS.md`](AGENTS.md) for agents, generated from the same sources;
it is not repeated here.

## Commits and the changelog

- The rules are [`GUIDELINES/shared/COMMIT.md`](GUIDELINES/shared/COMMIT.md):
  a tagged summary line (`[TASK]`, `[FIX]`, `[UPDATE]`, ...),
  one functional change with its tests,
  and the message checked by `GUIDELINES/shared/tools/commit-msg.sh` before committing.
- A commit touching shipped code (`lib/`, `appinfo/`, `templates/`, `src/`, ...)
  adds or amends a bullet under `## [Unreleased]` in [`CHANGELOG.md`](CHANGELOG.md),
  in the same commit.
  `GUIDELINES/shared/tools/changelog.sh check --staged` says whether one is owed.

## Setup

```bash
composer install
```

It installs the tools under `vendor-bin/` as well:
ECS, Psalm, PHPUnit and Rector.

## Lint

```bash
composer lint
```

```bash
composer cs:check
```

```bash
composer psalm
```

`composer cs:fix` applies the coding standard (`mwx/coding-standard`:
Allman braces, aligned declarations).
The manifest is checked against the app store's schema:

```bash
curl -fsSLo build/info.xsd https://raw.githubusercontent.com/nextcloud/appstore/master/nextcloudappstore/api/v1/release/info.xsd && xmllint --noout --schema build/info.xsd appinfo/info.xml
```

## Tests

```bash
composer test:unit
```

The integration suite needs a booted Nextcloud with `poppler-utils`,
and runs in a test instance through `GUIDELINES/shared/lang/php/tools/phpunit`
(`--testsuite integration`);
`GUIDELINES/config.local.ini` names the instance under `[phpunit]`.

## Rules the code keeps

- **Public API only.**
  The app uses `OCP\` and never extends a class from `OC\`.
- **Programs run through `proc_open()` with an argument array**,
  never a shell string, and their paths are never read from app config.
- **The preview provider never throws.**
  Every failure returns `null`, so the next provider is tried.
- **Every text a person reads is translatable**:
  `IL10N` in PHP, `t()`/`n()` in the frontend.
  Logs and command output stay English.
