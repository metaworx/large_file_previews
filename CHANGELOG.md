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

- `img/app.svg` and `img/app-dark.svg`: the app's icon, a picture with
  an arrow enlarging it.

- `README.md`, *What it does*: previews of PDF and Illustrator files by
  `pdftoppm`, a scan at its own resolution, and of images by
  `vipsthumbnail`, from local, unencrypted storage only.

- `README.md`, *When previews fail*: a setup check, with the last failed
  render, and `occ lfp:check`, listing what the app needs; programs and
  versions found once and kept, `--refresh` to find them again.

- `README.md`, *Configuration*: files taken by type, extension and
  size, images from `preview_max_filesize_image`; scans; each render's
  limits; libvips' untrusted decoders, blocked by default, and threads.

- `README.md`, *Configuration*: previews stored as PNG, JPEG or WebP,
  by default as Nextcloud's `preview_format` says; `preview_background`.

- `README.md`, *Configuration*: a TIFF in one large strip out of memory,
  re-striped by `tiffcp` into a copy in `scratch_directory`, on disk.

- *Administration settings → Additional settings*: status, files
  processed, preview format, limits, libvips, programs with versions;
  each setting with its `occ` command, copied with one click.

- `occ lfp:config:show`, `get`, `set` and `unset`: the configuration,
  what a type or file resolves to, and validated changes.

- `occ lfp:config:set binaries.<program>`: a program's path in system
  config, overriding the one Nextcloud finds.
