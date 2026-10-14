# NOTE LargeImages v1.1: run deviations and measurements

The PLAN++ run of 2026-10-14, blocks 2 to 11 and 13:
where it went differently from the plan, and what was measured on the way.
Measurements were taken on jackal as `www-data`, in `/tmp`,
libvips 8.15.1 and libtiff 4.5.1, user data only read.

## Deviations

- **Block 2, the config file.**
  No `config/large_file_previews.config.php` of the app's own:
  Nextcloud writes its whole merged config back to `config.php` on every change,
  so a file of its own could not keep paths out of `config.php`.
  By the user's choice (b), paths go to system config through `IConfig`,
  set with `occ lfp:config:set binaries.<program> <path>`,
  a key like every other setting's rather than a `--binary` option.
- **Block 3, the arenas.**
  `MALLOC_ARENA_MAX=2` and a libvips cap of 1024 MB, as planned,
  but the first measurement read `/usr/bin/time`'s report,
  which gives a process killed by a signal as exit 0:
  "a HEIC passes at 512 MB with two arenas" was a segfault.
  Remeasured by the shell's exit status and the output, twice each, below.
  The block 3 commit message carries the wrong claim;
  the code and the README no longer do.
- **Block 4, load options.**
  No `[fail_on=error]` and no option suffix:
  under the cap `fail_on` changed nothing,
  and a name ending in `]` cannot be opened with or without one.
  Such a name is refused, and so is an output path holding `%`,
  which `vipsthumbnail` reads as a format and does not unescape.
- **Block 6, no quality setting.**
  Nextcloud re-encodes every stored preview at its own quality,
  app config `preview` `jpeg_quality` and `webp_quality`,
  so an app setting would change nothing:
  the app writes at 95 and the README points to Nextcloud's.
- **Block 7, the one-strip fallback.**
  It runs only after a render ran out of memory,
  not up front on a strip-size estimate:
  the failure takes a tenth of a second,
  and an estimate would copy files libvips reads within its cap.
  The image's size comes from `vipsheader`, so `tiffinfo` is not used.
  The scratch directory is `scratch_directory`, in system config like the paths;
  there is no switch for the fallback.
  `tiffcp` reads `<path>,<image>` up to the first comma,
  so the file is given as a link in the scratch directory.
- **Block 7, what a one-strip TIFF needs.**
  Not its decoded image, as first written:
  a black 15000 x 15000 TIFF in one LZW strip, 675 MB decoded,
  rendered within 113 MB under 256.
  It needs about its compressed strip, and more for some formats.
  Corrected in the README and the code in block 11.
- **Block 8, the editable map: not built.**
  The provider's regex is fixed when the app registers,
  and `IBootstrap` documents `boot()`, not `register()`,
  as where services may be asked for anything, app config included.
  Reading app config in `register()` would work today and is outside that contract.
  The block ends with this finding; ROADMAP.md keeps the idea.
- **Block 11, found by CI.**
  libtiff's "No space for data buffer" was not counted as out of memory,
  and the runner kept only the first 4 KB of stderr,
  while libvips warns once per tile and says why it failed last.
  Both fixed; a crash by SIGSEGV or SIGABRT now says the cap is often why.
- **Types Nextcloud does not map.**
  Nextcloud 34 gives `.avif`, `.jxl`, `.ppm`, `.pgm` and `.pbm` no image type,
  so those types reach the app only with a server's own mimetype mapping.
  The README says so.
- **Not in this run**, as stated at its start:
  block 12 (the harness on `34-lfp`) and block 14 (jackal).

## Measured

### Two malloc arenas

By the shell's exit status and the output file, twice each;
`fail(n)` is exit status n, 134 SIGABRT, 139 SIGSEGV.

| File | Cap MB | Default arenas | Two arenas |
|---|---|---|---|
| real TIFF, 798 MB, uncompressed | 224 | fail, fail(134) | fail(134), fail(134) |
| | 256 | fail(134), pass | pass, pass |
| | 320 | fail, pass | pass, pass |
| HEIC, 40 MB, 8000 x 6000 | 512 | fail, fail | fail(139), fail(139) |
| | 640 | fail(139), fail(139) | pass, pass |
| | 768 | pass, pass | pass, pass |
| WebP, 10000 x 10000 | 640 | fail, fail | pass, pass |
| | 768 | pass, pass | pass, pass |

### One-strip TIFFs and tiffcp

- `tiffcp` holds the compressed strip, mapped or read:
  a 348 MB LZW strip passed at 384 MB, a 6 MB one needed 12 MB.
- 15000 x 15000 bytes in one LZW strip, 365 MB, under 512 MB:
  libvips fails in 0.11 s; `tiffcp` copies in 2.5 s;
  the copy renders in 1.0 s within 98 MB.
- Floats in one LZW strip under 256 MB:
  at 105, 151 and 206 MB libvips fails at once,
  `tiffcp` copies within about the file's size,
  and each copy renders in under 0.6 s; at 269 MB `tiffcp` fails too.
  CI's integration test uses the 151 MB case.

### libvips' loaders

- `vips -l foreign` lists 22 loaders, in about 16 KB;
  `jp2kload`, `jxlload`, `magickload` and `ppmload` are untrusted.
- A JPEG of an image with alpha is flattened onto black unless `background=255` is given;
  PNG and WebP keep the alpha.
