# Changelog

All notable changes to the **Large File Previews** app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions below `1.0.0` are pre-release: fixes/security patches increment the patch digit, and
any addition or change (including breaking changes) increments the minor digit, until
the first stable release.

## [Unreleased]

### Added

- Each render in a bubblewrap sandbox (`bwrap_enabled`, off): no network,
  and of the files only the system's programs and the render's own
  folder; the status says whether it can start here.

### Changed

- The App Store's screenshots show *Previews* and *Backup and restore*
  too.

## [0.2.1] - 2026-10-19

### Fixed

- 0.2.0 never reached the App Store, its install checks stopped by the
  CI; 0.2.1 is the same app.

## [0.2.0] - 2026-10-18

### Added

- `occ lfp:config:export` and `import` (from stdin too), the page's
  *Backup and restore*: what is stored, all or nothing, the changes shown
  first; Nextcloud's quality as commands; unknown values named (exit 3).

- *Previews → Nextcloud's own settings*: Nextcloud's JPEG and WebP
  quality set on the page, for every preview it stores from then on.

- The largest preview stored as rendered, at `preview_quality` (95),
  not encoded again (`preview_stream`), both on the page; the *Previews*
  help tabulates the type and quality each size gets.

## [0.1.0] - 2026-10-17

### Added

- The app, `large_file_previews`, for Nextcloud 33 to 35 and PHP 8.2+:
  previews of large PDF and Illustrator files by `pdftoppm`, of large
  images by `vipsthumbnail`; each type off until switched on.

- Which files: per MIME type and extension, a minimum and a maximum,
  field by field, a shipped image minimum or one minimum for every type;
  README, *Configuration*.

- Rendering: PNG, JPEG or WebP previews, scanned pages at their own
  resolution, a one-strip TIFF re-striped; `timeout`, `prlimit` and
  `choom` around every render; README, *Configuration*.

- Failures: a setup check and `occ lfp:check`, naming what is missing
  and its package; the last failed render; a failed file left to the
  next provider for a while; README, *When previews fail*.

- Trust: files handed on as descriptors; a program's path and libvips'
  untrusted decoders set in system config only; README, *Paths, and
  what the app trusts*.

- *Administration settings → Additional settings*: every setting, with
  its `occ` command and ⓘ help, down to a phone's width.

- `occ lfp:config:show`, `get`, `set` and `unset`: the configuration,
  each type's providers, and what a type or file resolves to.

- German, as `de` (du) and `de_DE` (Sie), the manifest's summary and
  description included.
