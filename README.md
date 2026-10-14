# Large File Previews

A Nextcloud app that renders previews of files
too large for Nextcloud's own preview providers.
It runs a command-line renderer on the file's local path,
so the file is never loaded into PHP, copied, or sent to another service.

> **Status:**
> in development, not yet released.
> PDF and Illustrator previews are rendered, and images once switched on.

## What it does

- **PDF and Illustrator** (`.ai`, Illustrator 9 and later),
  through `pdftoppm` from poppler:
  the first page, at Nextcloud's maximum preview size.
  Only the page that is shown is read,
  so a file of several gigabytes renders in under a second.
  A page that is one scan is rendered at the scan's own resolution where that is smaller,
  not enlarged: a 300 dpi A4 scan gives a preview 3508 pixels tall, not 4096.
- **Large images** through `vipsthumbnail` from libvips:
  TIFF, JPEG, PNG, WebP, GIF, HEIC, HEIF and JPEG 2000,
  and AVIF, JPEG XL, BMP and PNM where the server's libvips reads them,
  each type switched off until it is switched on.
  The image is read in strips or from a pyramid's nearest level rather than whole,
  and never enlarged: one smaller than the preview keeps its own size.
  Nextcloud 34 has no image type for `.avif`, `.jxl`, `.ppm`, `.pgm` or `.pbm`:
  those reach the app only with a mimetype mapping of the server's own.

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
- `pdftoppm`; `pdfinfo` and `pdfimages` to tell a scanned page.
- For images, while an image type is switched on: `vipsthumbnail`;
  `vipsheader` and `vips`,
  to keep an image at its own size and tell its decoders;
  optionally `tiffcp`, for a TIFF in one large strip.
- For the limits around each render, each optional:
  `timeout`, `prlimit` and `choom`.

| Programs | Debian, Ubuntu | Fedora | RHEL and rebuilds | Alpine | Arch |
|---|---|---|---|---|---|
| `pdftoppm`, `pdfinfo`, `pdfimages` | poppler-utils | poppler-utils | poppler-utils | poppler-utils | poppler |
| `vipsthumbnail`, `vipsheader`, `vips` | libvips-tools | vips-tools | none, nor in EPEL | vips-tools, community | libvips |
| `tiffcp` | libtiff-tools | libtiff-tools | libtiff-tools, CRB | tiff-tools | libtiff |
| `timeout` | coreutils | coreutils | coreutils | coreutils | coreutils |
| `prlimit`, `choom` | util-linux | util-linux | util-linux | util-linux-misc | util-linux |

`occ lfp:check` and the settings page name the command that installs what is missing,
for the distribution the server runs, read from `/etc/os-release`.

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
Where each program is, and its version,
is found once and kept in Nextcloud's memory cache;
it is found again on install, after each upgrade,
and with `occ lfp:check --refresh`, for instance after installing a program.

## Configuration

Which files the app takes is set per MIME type and per extension:
whether it takes them, and from which size up to which size.
Anything it declines goes to Nextcloud's next preview provider.
Each field is resolved on its own, the most specific level that sets it winning:
the extension, then the MIME type, then the app's default.
Out of the box, PDF and Illustrator files are taken at any size,
and every image type is off;
switched on, an image is taken from `preview_max_filesize_image` up,
50 MB unless set, where Nextcloud's own image providers stop.

Everything below is set in *Administration settings → Additional settings*,
which also shows whether previews can be rendered and the last render that failed,
or with `occ`; both check a value the same way.
Beneath each setting the page shows the `occ` command that sets it,
copied with one click.
Under *Advanced* it shows each program's path and version,
and the scratch directory, which only `occ` sets.

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
a cap on its address space (`prlimit_as_mb`, 512;
`vips_prlimit_as_mb`, 1024, for libvips,
which reads whole strips and runs threads),
and an out-of-memory score that has the kernel kill it before a web server worker
(`choom_adjust`, 1000).
A limit switched on whose program is missing declines the file rather than run without it.
A render that runs into the address-space cap is a failure,
recorded as out of memory, and the preview is left to the next provider.

A TIFF is read a strip at a time,
so one stored in a single large strip can need far more memory than its preview:
a 151 MB TIFF in one LZW strip failed under 256 MB.
One that runs out of memory gets a second chance where `tiffcp` is installed:
its first image is copied, uncompressed, in strips of 64 rows,
and libvips renders the copy, which is removed afterwards.
On a 15000 x 15000 TIFF in one LZW strip that failed under 512 MB,
the copy took 2.5 s and rendered in 1 s within 98 MB.
The copy goes to the scratch directory,
Nextcloud's temporary directory unless set,
and needs the image's uncompressed size there, and 256 MB more, on disk:
a directory on tmpfs or ramfs is refused, since the copy would sit in memory.

```bash
occ lfp:config:set scratch_directory /var/cache/large_file_previews
```

libvips' untrusted decoders are blocked (`vips_block_untrusted`, on by default):
JPEG 2000, JPEG XL, PNM, and ImageMagick, which BMP needs.
A type whose decoder is blocked, or missing from the server's libvips,
is left to the next provider, and the settings page says why;
`occ lfp:check --refresh` asks libvips again.
`vips_concurrency` is the threads one render may use, 1 by default.

Nextcloud stores a preview in the type it is given, which `preview_format` sets:
`png`, `jpeg`, `webp`, or `nextcloud`, the default,
which follows Nextcloud's own `preview_format` as Imaginary does:
WebP where it says `webp`, JPEG otherwise.
A PDF previewed as WebP is converted by libvips, and is a JPEG without it.
A JPEG keeps no transparency,
so a transparent image is laid on `preview_background`, white or black.
The quality is Nextcloud's:
it re-encodes every preview at its app config `preview` `jpeg_quality` or `webp_quality`.

A page counts as scanned where one image covers it, each way,
to at least `scan_coverage_percent` (90 by default);
`scan_native_enabled` switches the scan sizing off,
and every page then fills the preview size.

## Paths, and what the app trusts

A program's path can be overridden, in system config only:

```bash
occ lfp:config:set binaries.pdftoppm /opt/poppler/bin/pdftoppm
```

```bash
occ lfp:config:unset binaries.pdftoppm
```

The path must be an executable file; it is kept in `config.php`,
under `large_file_previews.binaries`.
App config is no place for a path:
an administrator can write it over the provisioning API without shell access,
and a path is code the web server runs.
The scratch directory is kept in system config for the same reason,
under `large_file_previews.scratch_directory`.

A file of its own works too, kept by hand or by a deployment tool:
Nextcloud reads every `config/*.config.php`,
so `config/large_file_previews.config.php` may hold
`'large_file_previews.binaries' => ['pdftoppm' => '/opt/poppler/bin/pdftoppm']`.
Nextcloud copies what it reads there into `config.php` whenever it next writes its config,
so remove a path from both:
with `occ lfp:config:unset`, and then from the file.

A file is read where it lies and handed to the renderer by its path,
as an argument, never through a shell.
libvips' untrusted decoders, not hardened against hostile files,
stay blocked unless switched on.
The only copy ever made is the re-striped TIFF, in the scratch directory,
removed once rendered.

## Measured

On a production server, Nextcloud 33.0.5 and PHP 8.4,
as the web server's user, files read from local storage.

### PDF

poppler 24.02, a PDF of 1.7 GB and 1817 A4 pages:

| What | Result |
|---|---|
| `pdftoppm`, page 1 at 4096 pixels | 0.47 s, 61 MB resident at most |
| Nextcloud's preview of it, through this app | 2.1 s, `occ` start-up included |
| Smallest address-space cap the render passes | 96 MB; at 64 MB it runs out of memory |
| The same file through Imaginary | killed at 2.5 GB and again at 6 GB of memory: it takes the whole file into memory |
| The same file through Collabora | at most about 1123 pixels |

The 512 MB default is five times what that file needs:
room for heavier pages, and still an early stop for a runaway.
A server can set its own.

### Images

libvips 8.15.1, one thread, untrusted decoders blocked,
a preview of 4096 pixels:

| File | Time | Resident at most | Address-space cap it passes |
|---|---|---|---|
| TIFF, 798 MB, 22040 x 12059, uncompressed | 2.95 s | 147 MB | 512; 256 with two arenas |
| TIFF, 644 MB, 15000 x 15000, in strips | 3.7 s | 101 MB | 512 |
| TIFF, 873 MB, 15000 x 15000, a tiled pyramid | 0.05 s | 24 MB | 512 |
| TIFF, 349 MB, 15000 x 15000, one LZW strip | 3.9 s | 452 MB | 768 |
| WebP, 44 MB, 10000 x 10000 | 7.0 s | 202 MB | 768; 640 with two arenas |
| HEIC, 40 MB, 8000 x 6000 | 9.0 s | 338 MB | 768; 640 with two arenas |

The cap, in MB, counts more than the memory a render uses:
mapped libraries, thread stacks,
and glibc's malloc arenas, 64 MB reserved for each thread.
So libvips runs with two arenas, and its cap is 1024 MB.
Under the cap a render fails at once,
or crashes, as libheif does under 640 MB.
The one-strip TIFF in the table, under a cap of 512 MB,
fails in 0.11 s, is re-striped by `tiffcp` in 2.5 s,
and its copy renders in 1.0 s within 98 MB.

## License

AGPL-3.0-or-later; see [LICENSE](LICENSE).
