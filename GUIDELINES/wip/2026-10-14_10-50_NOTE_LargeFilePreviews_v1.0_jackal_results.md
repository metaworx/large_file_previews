# NOTE LargeFilePreviews v1.0: jackal results

Block 11, run on 2026-10-14 on jackal's `helioscloud` instance,
the one holding the test file,
rather than the `nextcloud` instance the plan first named.

## The server

- Nextcloud 33.0.5, PHP 8.4.26, no `disable_functions`.
- `/var/www/helioscloud/apps` is its only app directory.
- poppler 24.02 (`pdftoppm`, `pdfinfo`, `pdfimages`), coreutils 9.4 (`timeout`),
  util-linux 2.39.3 (`prlimit`, `choom`), and `vips`.
- Preview providers enabled: Image, HEIC, Imaginary, ImaginaryPDF;
  `preview_max_filesize_image` = 800.
- The app was installed from the commit "Settings page: one section,
  what is active, and only what is set", with a clean frontend build,
  and enabled.
  `lfp:check` answered Ready, and the setup check is green.
  It stays enabled: every new PDF and AI preview there now comes from it.

## The test file

`MZ_151009_0_Katalog_WV_W.pdf`:
1.7 GB, 1817 pages, page 1 A4 turned 90 degrees, no image on page 1 (vector),
so not a scan and rendered into the full box.

## Measured

| What | Result |
|---|---|
| `pdftoppm -f 1 -l 1 -scale-to 4096`, as `www-data` | 0.47 s, 61 MB resident at most, 4096 x 2895 |
| under `prlimit --as` 2048 / 1024 / 512 / 256 / 160 / 128 / 96 MB | the same, every time |
| under 64 MB | "Out of memory" on stderr, **exit 0**, and a PNG of 90 bytes |
| under 32 MB | exit 127: the program cannot start |
| `pdfimages -list -f 1 -l 1` | 0.01 s, 11 MB |
| `preview:generate -s 1024x1024` on a copy without previews | 2.1 s, `occ` start-up included; the largest preview `4096-2895-max.png` is this app's |

The copy, its folder and its previews were removed afterwards and the folder rescanned.
The copy was removed on disk, not with `occ files:delete`,
which would have left 1.7 GB in the account's trash bin.

## Decided from it

- **Out of memory is a failure** of its own, `OUT_OF_MEMORY`,
  whatever the exit status:
  poppler's exit 0 and few bytes of PNG were otherwise caught only by the image loader,
  as "not an image".
- **`prlimit_as_mb` defaults to 512**, from 2048:
  five times what this file needs.
- **README, *Measured*:** these figures,
  beside Imaginary and Collabora on the same file,
  as measured on 12 and 13 October:
  Imaginary killed at 2.5 GB and at 6 GB, Collabora at most about 1123 pixels.

## Open

- jackal runs the version installed before these two changes: a 2048 MB cap,
  no `OUT_OF_MEMORY`.
  Updating it is a step of its own.
- A `find` over the whole preview store, run to locate the preview,
  was too slow for production and was stopped;
  a preview is found by its file id
  (`preview/<first 7 hex of md5(fileid), one per level>/<fileid>/`).
