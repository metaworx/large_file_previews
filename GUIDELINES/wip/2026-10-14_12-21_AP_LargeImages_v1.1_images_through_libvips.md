# AP LargeImages v1.1: images through libvips

## TL;DR

Step two of the app: large images rendered by `vipsthumbnail`, beside poppler,
under the same limits; previews in PNG, JPEG or WebP;
and a way through for a TIFF too large to decode in one piece.

- Renderer: `vipsheader` → size;
  `vipsthumbnail <file> --size N -o out.<format>`,
  N ≤ the image's own size → never enlarged
- Untrusted decoders (JPEG 2000, JPEG XL, ImageMagick → BMP) blocked → a switch,
  on by default
- Address-space cap per renderer → poppler 512 MB,
  libvips measured first (with `MALLOC_ARENA_MAX`)
- Preview format → as Nextcloud's `preview_format`, or PNG / JPEG / WebP;
  PDF to WebP through libvips
- One-strip TIFF over the cap → `tiffcp -r 64` to a scratch copy on disk,
  then libvips
- Paths and versions → detected once, cached;
  overrides in `config/large_file_previews.config.php`,
  written by `lfp:config:set`
- Settings page: Advanced part, each setting's `occ` command beneath it,
  copyable
- App icon; the harness moves to instance `34-lfp`

### Blocks

1. Measure on jackal (done)
2. Detection cache, versions, repair step, the app's config file
3. Runner: environment per run, cap per renderer, `MALLOC_ARENA_MAX` measured
4. `VipsRenderer`
5. Types, decoders and settings
6. Preview format
7. One-strip TIFF fallback through `tiffcp`
8. Editable MIME type → renderer map (feasibility first)
9. Settings page: Advanced, copyable commands, versions
10. App icon
11. Tests and CI
12. Harness on `34-lfp`
13. Documentation
14. jackal: deploy, enable TIFF, real files, NOTE

## Discussion

Settled with the user on 2026-10-14, in v1.0:

- **`VIPS_BLOCK_UNTRUSTED`** is a switch, on by default,
  in an *Advanced* part of the settings page;
  **`--vips-concurrency`** is a setting there too.
- **Binary paths** are detected once and cached, re-detected by a repair step;
  an override never goes to app config (contract 3.3),
  which the provisioning API writes.
- **The cache is Nextcloud's memory cache**, not app config,
  for the same reason.
- **The 798 MB TIFF** `Zentralbibliothek Zürich/Zuercher_Maja/010700645.tif` was measured,
  read only;
  **HEIC** test images were made after the user installed the x265 plugin on jackal.
- **An app icon**, for Nextcloud and for the GitLab and GitHub repositories.
- **The MIME type → renderer map** may become editable: block 8.
- From the agent's recommendations:
  the image default minimum follows `preview_max_filesize_image`;
  the harness gets `libvips-tools`.

Added in v1.1:

- **A preferred preview format.**
  Nextcloud 33 stores a provider's preview in the image's own type, PNG, JPEG,
  GIF or WebP (`OC\Image::dataMimeType()`);
  Imaginary follows the system setting `preview_format`.
  A setting `preview_format`: *as Nextcloud's* (the default), `png`,
  `jpeg` or `webp`,
  with `preview_quality` and `preview_background` (white or black) for what JPEG
  cannot keep transparent.
  This takes the ROADMAP's "JPEG previews" into this plan.
- **WebP for a PDF**:
  `pdftoppm` writes PNG and JPEG only; where WebP is asked and libvips is there,
  its PNG is converted by `vips copy`, under the same limits; without libvips,
  JPEG.
- **The app's config file.**
  Every setting that would otherwise go to `config.php` - today,
  the binary paths - goes to `config/large_file_previews.config.php`,
  which Nextcloud merges into its system config.
  `occ lfp:config:set --binary <program> <path>` writes it;
  the page shows that command, never writes the file.
- **The address-space cap is not resident memory**:
  a HEIC peaking at 338 MB fails under 512 because the cap counts mapped libraries,
  thread stacks and glibc's arenas (64 MB reserved per thread).
  `MALLOC_ARENA_MAX=2` is measured before libvips' default cap is chosen.
- **The one-strip TIFF fallback.**
  The app exists for files the other providers fail on for memory,
  so a slower way that succeeds is worth having:
  `tiffcp -c none -r 64` re-strips into an uncompressed copy,
  reading a row at a time, and libvips renders the copy.
  It runs when the largest strip, decoded,
  would take more than half the libvips cap (from `tiffinfo`),
  and as a retry after `OUT_OF_MEMORY` on a TIFF.
  The copy goes to a scratch directory on disk - never tmpfs,
  where it would sit in memory - after a free-space check;
  the directory is a path, so it is set in the app's config file,
  Nextcloud's temporary directory by default.
  `tiffcp` (`libtiff-tools`) is an optional prerequisite,
  not installed on jackal yet; it is measured before it ships.
- **Every setting on the page shows its `occ` command** beneath it,
  in a small grey line, with a copy icon.
- **Each program's version** shows on the page, from the same cache as its path.
- **The harness moves to a tagged instance `34-lfp`**;
  instance 34 goes back to file_checksum_search.
- **Not done**:
  converting every one-strip TIFF up front.
  Only what fails, or would, takes the slower way.

## Analysis

### jackal's libvips (8.15.1)

- Decoders: JPEG, PNG, WebP, GIF, TIFF, PNM, HEIF/HEIC/AVIF (libheif 1.17,
  libde265, aom), JPEG 2000, JPEG XL, SVG, PDF (poppler), OpenEXR, FITS,
  and ImageMagick as the fallback.
- **BMP has no decoder of libvips' own**:
  only ImageMagick reads it.
- Marked *untrusted*: JPEG 2000, JPEG XL, SVG, raw, ImageMagick,
  and libvips' own `.v` format.
  `VIPS_BLOCK_UNTRUSTED=1` refuses them all: measured,
  a JPEG 2000 file is refused with it and read without.

### Measured (2026-10-14, as `www-data`, `VIPS_BLOCK_UNTRUSTED=1`, one thread)

| File | Time | Peak resident | Under 512 MB | Under 2048 MB |
|---|---|---|---|---|
| real TIFF, 798 MB, 22040 x 12059, uncompressed | 2.95 s | 147 MB | passes | passes |
| TIFF striped, 644 MB, 15000² | 3.7 s | 101 MB | passes | passes |
| TIFF one strip, LZW, 349 MB, 15000² | 3.9 s | 452 MB | **fails** | passes |
| TIFF tiled pyramid, 873 MB, 15000² | 0.05 s | 24 MB | passes | passes |
| WebP, 44 MB, 10000² | 7.0 s | 202 MB | **fails** | passes |
| AVIF, 6 MB, 4000² | 5.7 s | 281 MB | **fails** | passes |
| HEIC, 40 MB, 8000 x 6000 | 9.0 s | 338 MB | **fails** (passes at 1024) | passes |

- **Four threads** gave no speed and more memory: one thread by default.
- **No temporary file was left**:
  libvips' spill to disc went to the `TMPDIR` given.

### Two pitfalls found

- **`[page=0]` breaks JPEG and PNG**:
  their decoders have no `page` option.
  Page 0 is the default; it is not used.
- **`--size NxN>` (shrink only) breaks a pyramid TIFF**:
  it picks the smallest level (125 pixels where 1024 were asked).
  So the renderer reads the size first with `vipsheader`,
  and asks `--size min(box, longer side)` without `>`.

### The renderer

```
VIPS_BLOCK_UNTRUSTED=1 TMPDIR=<render folder> [MALLOC_ARENA_MAX=2]
  timeout … prlimit --as=<vips cap> -- choom … --  vipsheader <file>
  timeout … prlimit --as=<vips cap> -- choom … --  vipsthumbnail <file> --size <N> --vips-concurrency=<C> -o <dir>/preview.<ext>[Q=<q>]
```

- `N = min(box, max(width, height))`;
  a header that cannot be read means the full box.
- `CommandRunner` takes an environment and a cap per run;
  each renderer names its own.
- Success as for poppler: exit 0 and an image the loader accepts.

### Decoders and types

- Each image MIME type names its decoder: TIFF → `tiffload`, JPEG → `jpegload`,
  PNG → `pngload`, WebP → `webpload`, GIF → `gifload`,
  HEIC/HEIF/AVIF → `heifload`, JPEG 2000 → `jp2kload`, JPEG XL → `jxlload`,
  PNM → `ppmload`, BMP → `magickload`.
- A type is available where `vips -l` lists its decoder and,
  while untrusted decoders are blocked, that decoder is not untrusted.
  The page says which and why.

### Detection cache and the config file

- `BinaryLocator` keeps paths,
  versions and `vips -l` in `ICacheFactory::createDistributed()` (a local cache
  where none is distributed);
  the overrides are read from system config on every request.
- A repair step on install and upgrade,
  `occ large-file-previews:check --refresh` and a button on the page refill it.
- `lfp:config:set --binary <program> <path>` and
  `lfp:config:unset --binary <program>` write
  `config/large_file_previews.config.php` (`var_export`, atomically,
  readable by the web server only), creating it where it is not there.

### An editable map (block 8)

- The provider's regex is built from the types once, when the app registers;
  a type outside it never reaches the app.
- Proposal: `extra_types`, MIME type → renderer,
  for types the renderer reports it reads,
  folded into the regex at registration -
  if reading app config in `register()` is sound in Nextcloud 33 to 35.
  If it is not, the block ends with that finding.

### App icon

- `img/app.svg` and `img/app-dark.svg`, monochrome;
  a coloured 512-pixel PNG for GitLab's project avatar,
  and a 1280 x 640 social preview for GitHub, which has no repository avatar.
  The user uploads them.

## Implementation Plan

1. **Measure on jackal.**
   Done; the Analysis records it.
2. **Detection cache, versions, repair step, config file.**
   *Verification:* unit tests with a cache mock;
   the repair step fills the cache in the harness;
   `lfp:config:set --binary` writes a file Nextcloud reads.
3. **Runner.**
   Environment and cap per run; `vips_prlimit_as_mb`;
   `MALLOC_ARENA_MAX` measured on jackal in `/tmp`,
   and the default chosen from it.
   *Verification:* unit tests; the measurement recorded in a NOTE.
4. **`VipsRenderer`.**
   The header read, the box, the outcome.
   *Verification:* unit tests with stub programs;
   real renders where libvips is installed.
5. **Types, decoders and settings.**
   The decoder table, the `vips -l` cache, `vips_block_untrusted`,
   `vips_concurrency`, the image default minimum.
   *Verification:* unit tests;
   the active table shows each image type available or why not.
6. **Preview format.**
   `preview_format`, `preview_quality`, `preview_background`;
   pdftoppm's `-jpeg`, libvips' output, PDF to WebP through `vips copy`.
   *Verification:* unit tests; real renders in each format;
   Nextcloud stores the preview in it.
7. **One-strip TIFF fallback.**
   `tiffinfo` for the strip size, `tiffcp -c none -r 64`, the scratch directory,
   the free-space and tmpfs checks, the retry after `OUT_OF_MEMORY`.
   *Verification:* unit tests with stubs; on jackal,
   once `libtiff-tools` is installed, the one-strip TIFF under the cap.
8. **Editable map.**
   Feasibility; then `extra_types`, or a NOTE.
9. **Settings page.**
   *Advanced* (the libvips switches,
   the scratch directory and the paths read only, with versions,
   and a refresh button); the `occ` command beneath every setting,
   with a copy icon.
   *Verification:* specs; the controller test.
10. **App icon.**
    *Verification:* the icon shows in the harness's app list.
11. **Tests and CI.**
    `libvips-tools` and `libtiff-tools` in CI's PHPUnit job;
    integration tests with small generated images per format and per output format.
    *Verification:* the suites in CI.
12. **Harness on `34-lfp`.**
    Agreed with the harness's own session, and gated: the instance,
    its `config.extra.yaml` (poppler, libvips, libtiff-tools),
    `config.local.ini`, and instance 34 handed back.
    *Verification:* both suites there.
13. **Documentation.**
    README: image types, decoders, the untrusted switch, caps per renderer,
    preview format, the TIFF fallback, the config file, *Measured* for images,
    the security note; ROADMAP without "JPEG previews".
    *Verification:* the reflow check.
14. **jackal.**
    Deploy (gated); TIFF from `preview_max_filesize_image`;
    the 798 MB TIFF and a HEIC end to end; a NOTE.

## Proposed commit messages

Registration: `[UPDATE] APs: +LargeImages v1.0-1.1`,
with a `File-revision-v1` trailer for each.
Then one or more `[TASK]` commits per block,
each with its tests and its CHANGELOG bullet.

## Change History

- v1.0 (2026-10-14): first version.
- v1.1 (2026-10-14): preview format with WebP for PDFs, the app's config file,
  the cap measured with `MALLOC_ARENA_MAX`, the one-strip TIFF fallback,
  copyable `occ` commands and versions on the page, the harness on `34-lfp`.
