# Large File Previews

A Nextcloud app that renders previews of files
too large for Nextcloud's own preview providers.
It runs a command-line renderer on the file's local path,
so the file is never loaded into PHP, copied, or sent to another service.

> **Status:**
> in development.
> Nothing is rendered yet; this README grows with the app.

## What it does

- **PDF and Illustrator** (`.ai`, Illustrator 9 and later),
  through `pdftoppm` from poppler:
  the first page, at Nextcloud's maximum preview size.
  Only the page that is shown is read,
  so a file of several gigabytes renders in under a second.
- **Large images** through libvips are planned.
  Their types are known to the app from the start, and switched off.

## What it does not do

- **Only local, unencrypted storage is rendered.**
  A file on object storage, on external storage that is not local,
  or encrypted by server-side encryption is declined,
  and Nextcloud's other preview providers get their turn.
  Copying such a file to a temporary file first is exactly what this app exists to avoid.
- It does not change the order of other providers, and renders no video:
  Nextcloud's own video provider already reads the local path.

## Requirements

- Nextcloud 33 to 35, PHP 8.2 or later.
- `pdftoppm`, from `poppler-utils` on Debian and Ubuntu.
- For the limits around each render, each optional:
  `timeout` (coreutils), `prlimit` and `choom` (util-linux).

## License

AGPL-3.0-or-later; see [LICENSE](LICENSE).
