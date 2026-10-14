# AP LargeImages v1.0: images through libvips

## TL;DR

Step two of the app: large images rendered by `vipsthumbnail`, beside poppler,
under the same limits,
with the decoders a server's libvips has read once and cached.

- Renderer: `vipsheader` → size; `vipsthumbnail <file> --size N -o out.png`,
  N ≤ the image's own size → never enlarged
- Untrusted decoders (JPEG 2000, JPEG XL, ImageMagick → BMP) blocked → switch,
  on by default
- Address-space cap per renderer → poppler 512 MB, libvips 2048 MB
- Binary paths and `vips -l` → detected once, cached,
  refreshed by a repair step and on request; overrides stay in `config.php`
- Advanced settings: the libvips switches, the paths shown read only
- Image default minimum → the server's `preview_max_filesize_image`
- App icon for Nextcloud, GitLab and GitHub

### Blocks

1. Measure on jackal (done; the Analysis holds the numbers)
2. Detection cache and repair step
3. `VipsRenderer`
4. Types, decoders and settings
5. An editable MIME type → renderer map (feasibility first)
6. Advanced settings on the page, and prerequisites
7. App icon
8. Tests, harness and CI
9. Documentation
10. jackal: deploy, enable TIFF, measure real files, NOTE

## Discussion

Settled with the user on 2026-10-14:

- **`VIPS_BLOCK_UNTRUSTED`** is a switch, on by default,
  in an *Advanced* part of the settings page.
- **`--vips-concurrency`** is a setting there too.
- **Binary paths** are detected once and cached, re-detected by a repair step;
  an override stays in `config.php` (contract 3.3: never app config,
  which the provisioning API writes).
  The page shows each path and its source, read only,
  with the `occ config:system:set` line.
- **A file of the app's own in `config/`** needs no code:
  Nextcloud merges every `config/*.config.php` into its system config,
  so `config/large_file_previews.config.php` holding `large_file_previews.binaries`
  works as `config.php` does.
  The README says so; `occ config:system:set` still writes to `config.php`.
- **The cache is Nextcloud's memory cache**, not app config,
  for the same reason:
  a cached path written over the provisioning API would be one an administrator
  without shell access chose.
- **The 798 MB TIFF** `Zentralbibliothek Zürich/Zuercher_Maja/010700645.tif` is
  in the measurements, read only.
- **HEIC**:
  the x265 plugin was installed on jackal by the user,
  for generating test images.
- **An app icon**, for Nextcloud and for the GitLab and GitHub repositories.
- **The MIME type → renderer map**:
  the user asked whether it should be editable,
  so that a renderer that learns a new type needs no app update.
  Block 5.

Taken from the agent's recommendations, the user not having answered otherwise:

- **The image default minimum** follows the server's
  `preview_max_filesize_image` (50 MB unless set; 800 on helioscloud):
  the app takes exactly what Nextcloud's own image providers refuse.
  A stored value overrides it as before.
- **JPEG output** (ROADMAP) is a plan of its own:
  it concerns PDF too.
- **The harness** gets `libvips-tools` in instance 34's `config.extra.yaml`.

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

- **Four threads** gave no speed (2.97 s against 2.95 s for the real TIFF) and
  more memory: one thread by default.
- **No temporary file was left**:
  libvips' spill to disc went to the `TMPDIR` given, and nothing remained.
- The one-strip TIFF needs its whole strip decoded, as foreseen:
  the cap must allow it, or the file goes to the next provider.

### Two pitfalls found

- **`[page=0]` breaks JPEG and PNG**:
  their decoders have no `page` option, and the load fails.
  Page 0 is the default anyway; the option is not used.
- **`--size NxN>` (shrink only) breaks a pyramid TIFF**:
  it picks the smallest level,
  and a 2000-pixel pyramid gave 125 pixels where 1024 were asked.
  So the renderer reads the size first with `vipsheader` (the header only),
  and asks `--size min(box, longer side)` without `>`: never enlarged,
  levels chosen right.

### The renderer

```
VIPS_BLOCK_UNTRUSTED=1 TMPDIR=<render folder>  timeout … prlimit --as=<vips cap> -- choom … --
  vipsheader -f width -f height <file>      (or the whole header, parsed)
  vipsthumbnail <file> --size <N> --vips-concurrency=<C> -o <render folder>/preview.png
```

- `N = min(box, max(width, height))`;
  a header that cannot be read means the full box.
- The environment is passed to `proc_open` beside `LC_ALL=C`;
  `CommandRunner` takes an environment per run.
- **The address-space cap becomes per renderer**:
  `prlimit_as_mb` stays for poppler (512), `vips_prlimit_as_mb` is added (2048).
  The settings page shows both under *Limits*.
- Success as for poppler: exit 0 and a PNG the image loader accepts.

### Decoders and types

- In code, each image MIME type names the decoder it needs: TIFF → `tiffload`,
  JPEG → `jpegload`, PNG → `pngload`, WebP → `webpload`, GIF → `gifload`,
  HEIC/HEIF/AVIF → `heifload`, JPEG 2000 → `jp2kload`, JPEG XL → `jxlload`,
  the PNM types → `ppmload`, BMP → `magickload`.
- A type is available where `vips -l` lists its decoder, and,
  while untrusted decoders are blocked, where that decoder is not untrusted.
  So BMP, JPEG 2000 and JPEG XL are unavailable by default; the page says why.
- `vips -l` is read once and cached with the paths.

### Detection cache and repair step

- `BinaryLocator` keeps what it found in
  `ICacheFactory::createDistributed( 'large_file_previews' )`,
  falling back to a local cache;
  an override in `config.php` is read on every request, never cached.
- A repair step (on install and after each upgrade) clears and refills the cache;
  `occ large-file-previews:check --refresh` and a button on the page do the same.

### An editable MIME type → renderer map (block 5)

- Today the types are code (`SupportedTypes`),
  the provider's regex is built from them, and Nextcloud reads that regex once,
  when the app registers.
  A type outside the regex never reaches the app, whatever any map says.
- **Proposal:**
  a setting `extra_types`, MIME type → renderer,
  for types a renderer reports it can read
  (for libvips, a decoder in `vips -l`);
  the regex is built from `SupportedTypes` plus `extra_types` at registration.
- **To find out first:**
  whether reading app config in `register()` is sound in Nextcloud 33 to 35;
  the documented rule is not to query services there.
  If it is not, the block ends with that finding, and the map stays code.
- The ordering argument holds: a longer regex is still the longest.

### App icon

- `img/app.svg` (and `img/app-dark.svg`), monochrome,
  as Nextcloud's app icons are;
  a coloured PNG of 512 pixels for the repositories.
- GitLab takes a project avatar; GitHub has no repository avatar,
  only a social preview image (1280 x 640).
  Both are uploaded by the user; the files are produced here.

## Implementation Plan

1. **Measure on jackal.**
   Done before this plan was written; the Analysis records it.
2. **Detection cache and repair step.**
   `BinaryLocator` caches; the repair step; `check --refresh`;
   tests with a cache mock.
   *Verification:* unit tests; in the harness, the repair step fills the cache.
3. **`VipsRenderer`.**
   `CommandRunner` takes an environment; the header read and the box;
   the per-renderer cap; the outcome.
   *Verification:* unit tests with stub programs; in the harness, real renders.
4. **Types, decoders and settings.**
   The MIME type → decoder table; the `vips -l` cache;
   settings `vips_block_untrusted`, `vips_concurrency`, `vips_prlimit_as_mb`;
   the image default minimum from `preview_max_filesize_image`.
   *Verification:* unit tests;
   `config:show` and the active table show image types available or why not.
5. **Editable map.**
   The feasibility check; then `extra_types` and the regex,
   or a NOTE saying why not.
   *Verification:* an integration test registering a type through the map,
   if built.
6. **Advanced settings and prerequisites.**
   An *Advanced* h3 on the page: the libvips switches,
   the paths read only with their source, a refresh button;
   `vipsheader` and `vipsthumbnail` as prerequisites,
   needed where an image type is enabled.
   *Verification:* specs; the controller test.
7. **App icon.**
   The SVGs and the repository PNGs.
   *Verification:* the icon shows in the harness's app list and settings.
8. **Tests, harness and CI.**
   `libvips-tools` in instance 34's `config.extra.yaml` (a gated download) and
   in CI's PHPUnit job; integration tests with small generated images:
   TIFF striped and pyramid, JPEG, PNG, WebP, HEIC where the encoder is there.
   *Verification:* the integration suite in the harness and in CI.
9. **Documentation.**
   README: the image types, the decoders, the untrusted switch,
   the cap per renderer, *Measured* for images, and the security note:
   libvips' decoders run as the web server's user, under the limits.
   *Verification:* the reflow check.
10. **jackal.**
    Deploy (gated); enable TIFF from the server's `preview_max_filesize_image`;
    the 798 MB TIFF and a HEIC end to end; a NOTE with the numbers.

## Proposed commit messages

Registration: `[UPDATE] APs: +LargeImages v1.0`,
with its `File-revision-v1` trailer.
Then one or more `[TASK]` commits per block,
each with its tests and its CHANGELOG bullet.

## Change History

- v1.0 (2026-10-14): first version.
