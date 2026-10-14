# Changelog

All notable changes to the **Large File Previews** app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions below `1.0.0` are pre-release: fixes/security patches increment the patch digit, and
any addition or change (including breaking changes) increments the minor digit, until
the first stable release.

## [Unreleased]

### Added

- `appinfo/info.xml`: the app, `large_file_previews`, for Nextcloud 33
  to 35 and PHP 8.2 and later.

- `README.md`, *What it does*: previews of PDF and Illustrator files by
  `pdftoppm`, a scan at its own resolution, from local, unencrypted
  storage only; others go to Nextcloud's.

- `README.md`, *When previews fail*: a setup check, with the last failed
  render, and `occ lfp:check`, listing what the app needs.

- `README.md`, *Configuration*: which files are taken, per MIME type and
  extension, by size; and the timeout, memory cap and OOM score of each.

- *Administration settings → Additional settings*: the status, which
  files are taken, and the limits, validated as `occ` validates them.

- `occ lfp:config:show`, `get`, `set` and `unset`: the configuration,
  what a type or file resolves to, and validated changes.

- `large_file_previews.binaries` in `config.php`: a program's path,
  overriding the one Nextcloud finds.
