# Changelog

All notable changes to the **Large File Previews** app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions below `1.0.0` are pre-release: fixes/security patches increment the patch digit, and
any addition or change (including breaking changes) increments the minor digit, until
the first stable release.

## [Unreleased]

### Added

- `occ lfp:config:export` and `import` (from stdin too), the page's
  *Backup and restore*: what is stored, all or nothing, the changes shown
  first; Nextcloud's quality as commands; unknown values named (exit 3).

- *Previews → Nextcloud's own*: Nextcloud's JPEG and WebP quality set
  on the page, for every preview it stores from then on.

- The largest preview stored as rendered, not decoded and encoded again
  (`preview_stream`, on by default); the smaller sizes are Nextcloud's.

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
