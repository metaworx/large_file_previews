# AP LargeImages v1.2: images through libvips

## TL;DR

Step two of the app, large images through libvips: blocks 1 to 11 and 13 are done.
This revision adds what to install where a program is missing,
and runs the two blocks left: the harness on `34-lfp`, and jackal.

- Install hints → per platform family from `/etc/os-release`:
  Debian/Ubuntu `apt`, Fedora/RHEL `dnf`, Alpine `apk`, Arch `pacman`
- Shown → `occ lfp:check`, the page's Status table and its Advanced programs
- "not needed" → "optional"
- `vipsthumbnail` → required, and blocking "Ready", while an image type is on
- Harness → instance `34-lfp` with poppler, libvips and libtiff tools;
  instance 34 released
- jackal → the app updated in place, programs found again,
  the 798 MB TIFF and a HEIC rendered by the provider, read only; a NOTE

### Blocks

1. Measure on jackal (done)
2. Detection cache, versions, repair step, paths in system config (done)
3. Runner: environment per run, cap per renderer (done)
4. `VipsRenderer` (done)
5. Types, decoders and settings (done)
6. Preview format (done)
7. One-strip TIFF fallback through `tiffcp` (done)
8. Editable map: not built, a finding (done)
9. Settings page: Advanced, copyable commands, versions (done)
10. App icon (done)
11. Tests and CI (done; the last fixes wait for CI)
12. Harness on `34-lfp`
13. Documentation (done)
14. jackal: update, programs, real files, NOTE
15. Install hints per platform; "optional"; `vipsthumbnail` required while images are on

## Discussion

Settled with the user on 2026-10-14, after the PLAN++ run of v1.1:

- **Packages per platform**, for all four families,
  shown wherever a program is missing or optional.
- **Blocks 12 and 14** are in this run's scope, with block 15 before them,
  so that jackal and the harness get it.

Everything v1.1 settled stands;
where the run went differently is
`2026-10-14_14-52_NOTE_LargeImages_v1.1_run_deviations_and_measurements.md`.

## Analysis

### The platform

- `/etc/os-release` gives `ID` and `ID_LIKE`, readable by the web server's user
  on every distribution that ships it. It is one file, and no program runs.
- Families: `debian` (debian, ubuntu, and anything `ID_LIKE` either);
  `rhel` (fedora, rhel, centos, rocky, almalinux, or `ID_LIKE` rhel or fedora);
  `alpine`; `arch` (arch, manjaro, or `ID_LIKE` arch).
  Any other, or no file, gives no hint rather than a guess.
- It is read once a request; a server does not change distribution.

### Packages

| Program | Debian/Ubuntu | Fedora/RHEL | Alpine | Arch |
|---|---|---|---|---|
| pdftoppm, pdfinfo, pdfimages | poppler-utils | poppler-utils | poppler-utils | poppler |
| vipsthumbnail, vipsheader, vips | libvips-tools | vips-tools | vips-tools | libvips |
| tiffcp | libtiff-tools | libtiff-tools | tiff-tools | libtiff |
| timeout | coreutils | coreutils | coreutils | coreutils |
| prlimit, choom | util-linux | util-linux | util-linux-misc | util-linux |

- Debian/Ubuntu's names are proven on jackal (Ubuntu 24.04), in CI
  and in the harness (Debian trixie).
  The others are checked against each distribution's package index in block 15;
  a name that cannot be confirmed is left out of the map.
- RHEL has `vips-tools` only from EPEL; the hint says so.

### `vipsthumbnail` while images are on

- Today it is optional, so "Ready" stays green with an image type on and no libvips,
  and every image then fails and is recorded.
- Required while any MIME type in `SupportedTypes::IMAGE_TYPES` is enabled,
  or any extension rule enables a file of such a type;
  `vipsheader`, `vips` and `tiffcp` stay optional.

### The harness

- `scripts/nc-test mount 34-lfp large_file_previews <checkout>`
  makes `instances/34-lfp`, Nextcloud 34 from the cached tarball;
  `instances/` is not tracked, so the harness repository is not written.
- `instances/34-lfp/.ddev/config.extra.yaml`:
  `[poppler-utils, libvips-tools, libtiff-tools]`; `ddev restart` builds the image,
  about 8 MB of packages measured in instance 34, plus apt's lists.
- `GUIDELINES/config.local.ini` `[phpunit]` points to `instances/34-lfp`.
- `scripts/nc-test release 34` hands 34 back:
  it remounts the harness's test app and stops it.

### jackal

- helioscloud has `preview_max_filesize_image` at 800,
  so TIFFs are the app's from 800 MB up, and the 798 MB file stays Nextcloud's.
  The provider is called directly for it, and for a HEIC found in the file cache:
  `getThumbnail()` writes no preview and changes no setting.
- The update is the swap used before:
  unpack beside, `mv` the old to `.old`, the new in, check, remove `.old`.
- TIFF is switched on with its default minimum, following
  `preview_max_filesize_image`, as v1.1 planned.

## Implementation Plan

15. **Install hints; "optional"; `vipsthumbnail` required while images are on.**
    `Platform` reads the family; `PrerequisitesService` gives each program its
    package and install command; `lfp:check`, the Status table and Advanced
    show them; the status reads "optional".
    *Verification:* unit tests with `os-release` samples of each family;
    the page's specs; the package names checked against each index.
12. **Harness on `34-lfp`.**
    *Verification:* both suites there, the image tests among them,
    no longer skipped; instance 34 released.
14. **jackal.**
    Build the app (`appinfo`, `lib`, `templates`, `img`, built `js` and `css`,
    `LICENSE`, `README.md`, `CHANGELOG.md`), swap it in, `occ upgrade` only if asked,
    `lfp:check --refresh`; TIFF on; the provider on the 798 MB TIFF and a HEIC.
    *Verification:* `lfp:check` ready, the setup check green,
    both renders' sizes and times; a NOTE with the results.

## Proposed commit messages

Registration: `[UPDATE] APs: +LargeImages v1.2`, with its `File-revision-v1` trailer.
Block 15: one `[TASK]` commit with its tests and CHANGELOG bullet.
Block 12 commits nothing:
it writes `GUIDELINES/config.local.ini` and the harness's `instances/`,
neither of them tracked.
Block 14: the NOTE, `[UPDATE] APs: +NOTE LargeImages v1.2`.

## Change History

- v1.0 (2026-10-14): first version.
- v1.1 (2026-10-14): preview format with WebP for PDFs, the app's config file,
  the cap measured with `MALLOC_ARENA_MAX`, the one-strip TIFF fallback,
  copyable `occ` commands and versions on the page, the harness on `34-lfp`.
- v1.2 (2026-10-14): blocks 1 to 11 and 13 done; block 15, install hints per platform,
  "optional", and `vipsthumbnail` required while images are on;
  blocks 12 and 14 in scope.
