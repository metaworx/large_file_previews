# NOTE LargeFilePreviews v1.0: scans and deviations

A decision taken with the user on 2026-10-13,
and where the run has gone differently from the plan so far.

## 1. A scanned page is not enlarged

**Problem:**
`pdftoppm -scale-to 4096` scales up as well as down,
so a page that is one 300 dpi scan of A4 (2480 x 3508 pixels) came out at 4096
pixels tall: a larger preview with no more detail.

**Decision:**
the renderer asks first what page 1 is,
using two programs from the same `poppler-utils`.
Each reads page 1 only, as `pdftoppm` does:

- `pdfinfo -f 1 -l 1`:
  the page size in points;
- `pdfimages -list -f 1 -l 1`:
  each image's pixel size and its resolution on the page.

Where page 1 holds exactly one image (masks aside),
and that image covers at least 90 % of the page each way, the page is a scan.
Its box is then the page's size at the image's resolution,
capped at the box Nextcloud asked for.
Anything else - a vector page, text with a logo, several images -
keeps the full box.

- Nextcloud takes a largest preview smaller than it asked for,
  and makes every smaller size from it.
- Both probes run under the same limits as the render.
  A probe that fails, or a program that is missing, only means the full box:
  they are optional prerequisites, never blocking.
- `CommandRunner::run()` gains `$keepStdout`,
  since both programs answer on stdout.
- Tests: the parsing as unit tests,
  and in the integration suite a generated PDF holding one 300 dpi image,
  rendered at its own size.

This is a step of block 6's renderer, added without a new revision:
the blocks stand.

## 2. Where the run went differently

- **Block 3 ran before block 2.**
  The user gave the certificate request priority.
- **Block 2 created no GitHub repository.**
  It already existed, as did the GitLab project and the mirror.
- **Block 1's check "the app enables in harness instance 34"** was done at block 3,
  once the user chose instance 34.
  Instance 34 was handed over from file_checksum_search.
- **`config:show` lists the programs from block 4 on,** not from block 7.
- **Block 9's harness change is a local instance file,** not a change to
  nextcloud_testing: `instances/34/.ddev/config.extra.yaml`,
  as that repository's own session advised.
- **`ConfigurationService::forget()`** was added in block 9:
  the rules are read once per request,
  and a long process needs to read them again.
- **Block 10 waits for the certificate**,
  which the user expects to take days or weeks.
  Block 11 comes first.
