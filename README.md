# Large File Previews

A Nextcloud app that renders previews of files
too large for Nextcloud's own preview providers.
It hands the file, where it lies on local storage, to a command-line renderer,
so the file is never loaded into PHP or sent to another service,
and copied only to give a TIFF in one large strip a second chance.

> **Status:**
> in development, not yet released.
> PDF and Illustrator previews are rendered, and images,
> each type once switched on.

## What it does

Nothing, until it is told to:
every type below is off when the app is installed,
and an administrator switches on the ones it is to take (see *Configuration*).

- **PDF and Illustrator** (`.ai`, Illustrator 9 and later),
  through `pdftoppm` from poppler:
  the first page, at Nextcloud's maximum preview size.
  Only the page that is shown is read,
  so a file of several gigabytes renders in under a second.
  A page that is one scan is rendered at the scan's own resolution where that is smaller,
  not enlarged: a 300 dpi A4 scan gives a preview 3508 pixels tall, not 4096.
- **Large images** through `vipsthumbnail` from libvips:
  TIFF, JPEG, PNG, WebP, GIF, HEIC and HEIF,
  and AVIF where the server's libvips reads it;
  JPEG 2000, JPEG XL, BMP and PNM too,
  but libvips counts their decoders as untrusted,
  so only once system config allows those (see *Configuration*).
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

## Screenshots

The settings, under *Administration settings → Additional settings*,
open with the app's status and what it needs:

![The settings page][shot-settings]

What is processed: PDF, Illustrator, TIFF from 50 MiB,
HEIC through its extension, and `.tif` excluded:

![What is processed][shot-processed]

The MIME types, each with the preview providers Nextcloud tries for it,
this app's first, and its `occ` command:

![The MIME types][shot-mime]

The extensions: `.heic` enabled from 20 MiB, `.tif` excluded:

![The extensions][shot-extensions]

The limits each render runs under:

![The limits][shot-limits]

The previews: their type, a JPEG's background, and the quality,
the largest stored as rendered:

![The previews][shot-previews]

Backup and restore, a file read and its changes shown before anything is written:

![Backup and restore][shot-backup]

`tests/e2e/screenshots.cy.js` takes them; `tests/e2e/README.md` says how.

<!-- BEGIN GENERATED RELEASE-PIN - do not edit; run "changelog.sh cut" -->
[shot-settings]: https://gitlab.com/metaworx/open-source/nextcloud/large_file_previews/-/raw/v0.2.1/docs/Screenshots/Settings.png
[shot-processed]: https://gitlab.com/metaworx/open-source/nextcloud/large_file_previews/-/raw/v0.2.1/docs/Screenshots/What-Is-Processed.png
[shot-mime]: https://gitlab.com/metaworx/open-source/nextcloud/large_file_previews/-/raw/v0.2.1/docs/Screenshots/MIME-Types.png
[shot-extensions]: https://gitlab.com/metaworx/open-source/nextcloud/large_file_previews/-/raw/v0.2.1/docs/Screenshots/Extensions.png
[shot-limits]: https://gitlab.com/metaworx/open-source/nextcloud/large_file_previews/-/raw/v0.2.1/docs/Screenshots/Limits.png
[shot-previews]: https://gitlab.com/metaworx/open-source/nextcloud/large_file_previews/-/raw/v0.2.1/docs/Screenshots/Previews.png
[shot-backup]: https://gitlab.com/metaworx/open-source/nextcloud/large_file_previews/-/raw/v0.2.1/docs/Screenshots/Backup-And-Restore.png
<!-- END GENERATED RELEASE-PIN -->

## Requirements

- Nextcloud 33 to 35, PHP 8.2 or later,
  with the `gd` extension, and `proc_open`, `proc_get_status` and `proc_close`
  not in `disable_functions`;
  `/dev/fd`, which `/proc` brings, to hand a file on;
  a writable temporary directory.
  `sysvsem` is optional: Nextcloud's own limit on concurrent previews needs it.
- `pdftoppm`; optionally `pdfinfo` and `pdfimages`, to tell a scanned page.
- For images, while an image type is switched on:
  `vipsthumbnail`, from libvips 8.13 on;
  optionally `vipsheader`, to keep an image at its own size,
  and `vips`, to tell the types libvips reads and write a PDF's preview as WebP;
  optionally `tiffcp`, with `vipsheader`, for a TIFF in one large strip.
- For the limits around each render, each needed while it is switched on:
  `timeout`, `prlimit` and `choom`;
  `bwrap`, for the sandbox, with user namespaces.

| Programs | Debian, Ubuntu | Fedora | RHEL and rebuilds | Alpine | Arch |
|---|---|---|---|---|---|
| `pdftoppm`, `pdfinfo`, `pdfimages` | poppler-utils | poppler-utils | poppler-utils | poppler-utils | poppler |
| `vipsthumbnail`, `vipsheader`, `vips` | libvips-tools | vips-tools | none, nor in EPEL | vips-tools, community | libvips |
| `tiffcp` | libtiff-tools | libtiff-tools | libtiff-tools, CRB | tiff-tools | libtiff |
| `timeout` | coreutils | coreutils | coreutils | coreutils | coreutils |
| `prlimit`, `choom` | util-linux | util-linux | util-linux | util-linux-misc | util-linux |
| `bwrap` | bubblewrap | bubblewrap | bubblewrap | bubblewrap | bubblewrap |

`occ lfp:check` and the settings page name the command that installs what is missing,
for the distribution the server runs, read from `/etc/os-release`.

## When previews fail

A failed render is a warning in the log,
and the preview is left to the next provider.
So that a failing renderer is noticed,
the administration overview has a check of its own:
an error while something the app needs is missing,
and a warning naming the last render that failed, for a day after it did.

A file that failed is not tried again on every page that shows it:
it is left to the next provider for `failure_retry_minutes`,
60 by default, where 0 tries it every time,
and kept in Nextcloud's memory cache by the file's id and version.
A change to the file, or to a setting a render uses, tries it again at once,
and so does `occ lfp:check --refresh`.
A failure that is the server's, a program missing for instance, is not kept.

```bash
occ lfp:check
```

lists everything the app needs - the PHP functions that start a program,
the extensions, and each program with its path and version -
and exits 1 while something needed is missing.
Where each program is, its version, and which decoders libvips has
are found once and kept in Nextcloud's memory cache for a day;
they are found again on install, after each upgrade,
with `occ lfp:check --refresh`, for instance after installing a program,
and with *Find programs again* on the settings page.
Without a memory cache nothing is kept, and each request finds them again.
A libvips that cannot list its decoders is asked again a minute later,
not for every image.

## Configuration

Which files the app takes is set per MIME type and per extension:
whether it takes them, and from which size up to which size.
Anything it declines goes to Nextcloud's next preview provider.
Each field is resolved on its own, the most specific level that sets it winning:
the extension, then what is stored for the MIME type,
then what the MIME type ships with, then the app's default,
`min_size_mb` (0) and `max_size_mb` (-1, no limit), in MiB as every size here.
The page and `config:get` name the level each value comes from:
`ext`, `mime`, `shipped` or `default`.
The settings page calls a file the app takes *processed*.
Out of the box every type is off,
so installing the app changes nothing:
an administrator switches on each MIME type or extension it is to take,
and until one is, the settings page says so in amber.
Switched on, PDF and Illustrator files are taken at any size,
and an image from `preview_max_filesize_image` up,
50 MiB unless set, where Nextcloud's own image providers stop.
With `min_size_all_types` on,
images follow `min_size_mb` as every other type does:
at 0, the app renders every image of a type switched on,
ahead of Nextcloud's own providers.
A MIME type keeps only the fields set for it,
so the rest follow its shipped default, the image minimum included,
and a field cleared returns to it.

Everything below is set in *Administration settings → Additional settings*,
which also shows whether previews can be rendered and the last render that failed,
or with `occ`; both check a value the same way.
Beneath each setting the page shows the `occ` command that sets it,
copied with one click.
A number field shows what is stored,
and its default as its placeholder where nothing is:
emptied and saved, it follows the default again.
The defaults themselves show their value, with a Reset while one is stored.
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
each type's preview providers, and what is set but not used;
`config:get` says what one MIME type, extension or file resolves to,
at a size where one is given, and why;
`config:unset` returns a setting or a rule to its default.
Each command is `large-file-previews:<name>`, with `lfp:<name>` as its alias.

The providers of a type are the ones Nextcloud tries for it, in its order:
the regex they are registered under, longest first.
This app's is normally first for every type it handles;
one that comes before it is shown in amber on the page,
and marked `!` by `config:show`,
since it is asked first and may take a file the app would have taken.
Each name on the page carries the provider's class as its tooltip.
What is listed is what is registered:
whether a provider takes a particular file is known per file only.

Each render runs under three limits, each switchable, all on by default,
and, where it is switched on, in a sandbox.
Sizes are in MiB, as the caps count them.

| Limit | Switch | Values, default and range |
|---|---|---|
| A timeout, shared by every program one render runs | `timeout_enabled` | `timeout_seconds` 30, 1 to 600; `timeout_kill_after_seconds` 5, 1 to 60, before SIGKILL |
| Caps on its address space and on the largest file it writes | `prlimit_enabled` | `prlimit_as_mb` 512 and `vips_prlimit_as_mb` 1024, for libvips, which reads whole strips and runs threads, each 256 to 65536; `prlimit_fsize_mb` 1024, 64 to 65536 |
| An out-of-memory score that has the kernel kill it before a web server worker | `choom_enabled` | `choom_adjust` 1000, 0 to 1000 |
| A bubblewrap sandbox, off by default | `bwrap_enabled` | none |

A limit switched on whose program is missing fails the render rather than run without it:
a warning in the log, the last failure, and an error in the setup check,
and the preview is left to the next provider.
A render that runs into the address-space cap is a failure,
recorded as out of memory, and the preview is left to the next provider.

A TIFF is read a strip at a time,
so one stored in a single large strip can need far more memory than its preview:
a 151 MB TIFF in one LZW strip failed under 256 MiB.
One that runs out of memory gets a second chance
where `tiffcp` and `vipsheader` are installed:
its first image is copied, uncompressed, in strips of 64 rows,
and libvips renders the copy.
On a 15000 x 15000 TIFF in one LZW strip that failed under 512 MiB,
the copy took 2.5 s and rendered in 1 s within 98 MiB.
The copy is a file in the scratch directory, unlinked as soon as it is opened,
so it is gone once rendered, whatever ends the render.
One copy is made at a time,
none larger than `tiff_restrip_max_mb` (4096, 256 to 1048576),
and, while `prlimit_enabled` is on,
`tiffcp` may write no more than the copy's size and a small margin.
The scratch directory is Nextcloud's temporary directory unless set,
and must be an absolute, writable directory on disk
with the copy's size and 256 MiB more free;
where it is on tmpfs or ramfs, in memory, or has too little room,
the second chance is not tried, and the failure says why.
Setting it, and `occ lfp:config:show` and the settings page,
check only that it is an absolute, writable directory;
where it is, and its free space, are checked when a copy is needed.

```bash
occ lfp:config:set scratch_directory /var/cache/large_file_previews
```

libvips' untrusted decoders are blocked:
whatever `vips -l` marks untrusted,
with libvips 8.15 JPEG 2000, JPEG XL, PNM, and ImageMagick, which BMP needs.
They are allowed in system config only, never from the settings page,
since an administrator can write app config without shell access:

```bash
occ lfp:config:set vips_allow_untrusted true
```

libvips blocks them from 8.13 on;
an older one cannot, and picks its decoder by the content,
so while they are blocked it renders no image at all,
nor does a libvips whose `vipsthumbnail --version` says nothing.
A type whose decoder is blocked, or missing from the server's libvips,
is left to the next provider, and the settings page says why;
`occ lfp:check --refresh` asks libvips again.
`vips_concurrency` is the threads `vipsthumbnail` may use for one render,
1 by default, 1 to 64.

Nextcloud stores a preview in the type it is given, which `preview_format` sets:
`png`, `jpeg`, `webp`, or `nextcloud`, the default,
which follows Nextcloud's own `preview_format` as Imaginary does:
WebP where it says `webp`, JPEG otherwise.
A PDF previewed as WebP is converted by libvips, and is a JPEG without it.
A JPEG keeps no transparency,
so a transparent image is laid on `preview_background`, white or black.
Every smaller size is made from the largest, in its type,
so where a client cannot show WebP, the type is JPEG.

The largest preview is stored as rendered:
the app hands it to Nextcloud as a stream, which Nextcloud keeps as it is,
at `preview_quality`, 95 by default, 10 to 100; a PNG is lossless.
A JPEG's Huffman tables are optimized, which loses nothing:
a drawn page of 4096 x 2048 pixels is 50 KB at 95,
where Nextcloud's own is 62 KB at 80.
`preview_stream`, on by default, switches that;
off, and for a render larger than Nextcloud's preview size, which is scaled,
Nextcloud decodes the render and encodes it again at its own quality.
That is its app config `preview` `jpeg_quality` or `webp_quality`,
80 unless set,
for every size it makes from the largest, and for the largest not streamed.
The settings page sets both, 10 to 100, an emptied field back to 80,
and shows Nextcloud's own `preview_format`, read only,
each with the command that sets it.
They apply to every preview Nextcloud stores from then on, not only this app's;
previews stored already keep the quality they have.

A page counts as scanned where one image covers it, each way,
to at least `scan_coverage_percent` (90 by default, 50 to 100);
`scan_native_enabled` switches the scan sizing off,
and every page then fills the preview size.

## Backup and restore

What is stored can be written to a file and read back,
before an update or a reinstall, or onto another server:

```bash
occ lfp:config:export /root/lfp-config.json
```

```bash
occ lfp:config:import /root/lfp-config.json --dry-run
```

The file is JSON: the settings, MIME rules and extensions stored,
the system values set - programs' paths, the untrusted decoders,
the scratch directory -
and Nextcloud's own preview quality where it is stored,
under `nextcloud > appconfig > preview`.
What follows its default is left out,
so that a restore follows the defaults of the version it runs on.
Under `exported` the file says what wrote it - the app's version, the date,
and each program's path, version and how it was found - for diagnosis;
an import reports the version and the date, and applies none of it.
The import reads standard input where no file is given:

```bash
occ lfp:config:import < /root/lfp-config.json
```

An import checks the whole file before it writes anything,
and writes all of it or nothing.
It changes what the file names;
`--replace` returns everything else to its default,
so that the server holds exactly what the file does.
`--dry-run` lists each change, from what is stored to what would be.
What the app does not know - a setting, a MIME type, a field of a rule -
is left out and named, and the rest imported, with exit status 3.
A file it cannot read, or a value it knows that is not valid, is refused,
with exit status 4, and nothing is written.

Nextcloud's own values are not written by an import:
their `occ` commands are printed instead, alone on standard output,
and everything else on standard error,
so that they can be run with it where `occ` is a command the shell knows:

```bash
occ lfp:config:import < /root/lfp-config.json | bash
```

The settings page does the same under *Backup and restore*:
it downloads the file, and reads one uploaded,
showing the changes and what is left out before Apply writes them.
It writes the app's settings and rules only;
the system values and Nextcloud's own a file holds
are shown as the `occ` commands that set them.

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

A file is read where it lies:
the app opens it and hands it to the renderer as a descriptor,
never by its name, and never through a shell.
A program's path kept in the memory cache is checked again before it runs.
A file's name, or what a program prints, reaches the log as text:
a control character becomes a space, and bytes that are no UTF-8 are replaced.
libvips' untrusted decoders, not hardened against hostile files,
stay blocked unless system config allows them.
The only copy ever made is the re-striped TIFF, in the scratch directory,
an unlinked file, gone once rendered.

A renderer runs as the web server's user, and the limits contain a runaway,
not an exploit: a renderer an exploit took over could read every user's files,
`config.php` with its database password, and reach the network.
`bwrap_enabled` runs each render in a bubblewrap sandbox instead,
innermost, so that the limits hold for it too:
no network, its own processes,
and of the file system only `/usr`, `/etc` and the top-level `/bin`,
`/sbin` and `/lib`s, read-only,
fontconfig's cache, a private `/tmp` and the render's own folder.
Nextcloud's config and data directory stay hidden even where they lie inside those,
as a distribution's package may keep `config.php` under `/etc`.
The file still arrives as a descriptor, never copied.
A program whose path in system config lies elsewhere has its folder there too;
libraries it loads from outside those folders do not.
bubblewrap needs user namespaces,
which a container such as Nextcloud All-in-One usually refuses:
the status says whether it can start a sandbox,
and switched on where it cannot, nothing is rendered.

### The sandbox on Ubuntu 24.04

Ubuntu 24.04 and later restrict unprivileged user namespaces through AppArmor:
bwrap may create them, but not set up the network inside,
and the status reports `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`.
A profile that lets bwrap alone use them lifts that,
in `/etc/apparmor.d/bwrap`:

```text
abi <abi/4.0>,
include <tunables/global>

profile bwrap /usr/bin/bwrap flags=(unconfined) {
  userns,
  include if exists <local/bwrap>
}
```

```bash
sudo apparmor_parser -r /etc/apparmor.d/bwrap
```

The other way is to lift the restriction for every program,
`kernel.apparmor_restrict_unprivileged_userns = 0` in a file under `/etc/sysctl.d/`.
Then `occ lfp:check --refresh` probes again.

## Measured

On a production server, Nextcloud 33.0.5 and PHP 8.4,
as the web server's user, files read from local storage.

### PDF

poppler 24.02, a PDF of 1.7 GB and 1817 A4 pages:

| What | Result |
|---|---|
| `pdftoppm`, page 1 at 4096 pixels | 0.47 s, 61 MiB resident at most |
| Nextcloud's preview of it, through this app | 2.1 s, `occ` start-up included |
| Smallest address-space cap the render passes | 96 MiB; at 64 MiB it runs out of memory |
| The same file through Imaginary | killed at 2.5 GB and again at 6 GB of memory: it takes the whole file into memory |
| The same file through Collabora | at most about 1123 pixels |

The 512 MiB default is five times what that file needs:
room for heavier pages, and still an early stop for a runaway.
A server can set its own.

### Images

libvips 8.15.1, one thread, untrusted decoders blocked,
a preview of 4096 pixels; sizes and caps in MiB:

| File | Time | Resident at most | Address-space cap it passes |
|---|---|---|---|
| TIFF, 761, 22040 x 12059, uncompressed | 2.95 s | 147 | 512; 256 with two arenas |
| TIFF, 644, 15000 x 15000, in strips | 3.7 s | 101 | 512 |
| TIFF, about 873, 15000 x 15000, a tiled pyramid | 0.05 s | 24 | 512 |
| TIFF, 348, 15000 x 15000, one LZW strip | 3.9 s | 452 | 768 |
| WebP, about 44, 10000 x 10000 | 7.0 s | 202 | 768; 640 with two arenas |
| HEIC, about 40, 8000 x 6000 | 9.0 s | 338 | 768; 640 with two arenas |

The cap counts more than the memory a render uses:
mapped libraries, thread stacks,
and glibc's malloc arenas, 64 MiB reserved for each thread.
So libvips runs with two arenas, and its cap is 1024 MiB.
Under the cap a render fails at once,
or crashes, as libheif does under 640 MiB.
The one-strip TIFF in the table, under a cap of 512 MiB,
fails in 0.11 s, is re-striped by `tiffcp` in 2.5 s,
and its copy renders in 1.0 s within 98 MiB.

## License

AGPL-3.0-or-later; see [LICENSE](LICENSE).
