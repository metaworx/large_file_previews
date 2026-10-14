# Large File Previews

A Nextcloud app that renders previews of files
too large for Nextcloud's own preview providers.
It runs a command-line renderer on the file's local path,
so the file is never loaded into PHP, copied, or sent to another service.

> **Status:**
> in development, not yet released.
> PDF and Illustrator previews are rendered.

## What it does

- **PDF and Illustrator** (`.ai`, Illustrator 9 and later),
  through `pdftoppm` from poppler:
  the first page, at Nextcloud's maximum preview size.
  Only the page that is shown is read,
  so a file of several gigabytes renders in under a second.
  A page that is one scan is rendered at the scan's own resolution where that is smaller,
  not enlarged: a 300 dpi A4 scan gives a preview 3508 pixels tall, not 4096.
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

## When previews fail

A failed render is a warning in the log,
and the preview is left to the next provider.
So that a failing renderer is noticed,
the administration overview has a check of its own:
an error while something the app needs is missing,
and a warning naming the last render that failed, for a day after it did.

```bash
occ lfp:check
```

lists everything the app needs - the PHP functions that start a program,
the extensions, and each program with its path and version -
and exits 1 while something needed is missing.

## Configuration

Which files the app takes is set per MIME type and per extension:
whether it takes them, and from which size up to which size.
Anything it declines goes to Nextcloud's next preview provider.
Each field is resolved on its own, the most specific level that sets it winning:
the extension, then the MIME type, then the app's default.
Out of the box, PDF and Illustrator files are taken at any size,
and every image type is off, from 50 MB up once switched on.

Everything below is set in *Administration settings → Additional settings*,
which also shows whether previews can be rendered and the last render that failed,
or with `occ`; both check a value the same way.

```bash
occ lfp:config:show
```

```bash
occ lfp:config:get --file /alice/files/scan.pdf
```

```bash
occ lfp:config:set --mime image/tiff --enabled=yes
```

```bash
occ lfp:config:set --ext tif --min=200
```

```bash
occ lfp:config:set timeout_seconds 60
```

`config:show` lists everything, stored and resolved,
and what is set but not used;
`config:get` says what one MIME type, extension, size or file resolves to,
and why;
`config:unset` returns a setting or a rule to its default.
Each command is `large-file-previews:<name>`, with `lfp:<name>` as its alias.

Each render runs under three limits, each switchable:
a timeout (`timeout_seconds`, 30 by default),
a cap on its address space (`prlimit_as_mb`, 2048),
and an out-of-memory score that has the kernel kill it before a web server worker
(`choom_adjust`, 1000).
A limit switched on whose program is missing declines the file rather than run without it.

A page counts as scanned where one image covers it, each way,
to at least `scan_coverage_percent` (90 by default);
`scan_native_enabled` switches the scan sizing off,
and every page then fills the preview size.

A program's path can be overridden, in `config.php` only:

```bash
occ config:system:set large_file_previews.binaries pdftoppm --value=/opt/poppler/bin/pdftoppm
```

App config is no place for a path:
an administrator can write it over the provisioning API without shell access,
and a path is code the web server runs.

## License

AGPL-3.0-or-later; see [LICENSE](LICENSE).
