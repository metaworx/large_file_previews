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
  to 35 and PHP 8.2 and later, with screenshots of its settings.

- `img/app.svg` and `img/app-dark.svg`: the app's icon, a picture with
  an arrow enlarging it.

- `README.md`, *What it does*: PDF and Illustrator by `pdftoppm`, images
  by `vipsthumbnail`, each type off until switched on; *What it does
  not do*: storage that is not local, or encrypted.

- `README.md`, *Requirements*: PHP's `gd` and process functions,
  `/dev/fd`, libvips 8.13, and which programs are optional.

- `README.md`, *When previews fail*: a setup check, with the last failed
  render, and `occ lfp:check`, listing what the app needs, and the
  package for it per distribution; what it finds kept for a day.

- `README.md`, *When previews fail*: a file that failed is left to the
  next provider for `failure_retry_minutes`.

- `README.md`, *Configuration*: files taken by type, extension and
  size, images from `preview_max_filesize_image`; scans; each render's
  limits; libvips' untrusted decoders, blocked by default, and threads.

- `README.md`, *Configuration*: untrusted decoders allowed in system
  config only (`vips_allow_untrusted`); before libvips 8.13, no images.

- `README.md`, *Configuration*: previews stored as PNG, JPEG or WebP,
  by default as Nextcloud's `preview_format` says; `preview_background`;
  Nextcloud's own format and quality shown on the page, read only.

- `README.md`, *Configuration*: a TIFF in one large strip out of memory,
  re-striped by `tiffcp` into an unlinked copy in `scratch_directory`,
  one at a time, up to `tiff_restrip_max_mb`; `prlimit_fsize_mb`.

- `README.md`, *Paths, and what the app trusts*: files handed on as
  descriptors, a kept path checked before it runs, names and messages
  logged as text.

- *Administration settings → Additional settings*: version, status (amber
  if nothing is processed), types with providers, previews, limits,
  failures, libvips, programs; down to a phone's width; `occ`, ⓘ help.

- `occ lfp:config:show`, `get`, `set` and `unset`: the configuration,
  each type's providers, what a type or file resolves to and from which
  level, shipped included; validated changes over the shipped default.

- `occ lfp:config:set binaries.<program>`: a program's path in system
  config, overriding the one Nextcloud finds.

- `l10n/`: German, as `de` (du) and `de_DE` (Sie): the settings page,
  the setup check and the refusals; `appinfo/info.xml`: the app's
  summary and description in German.
