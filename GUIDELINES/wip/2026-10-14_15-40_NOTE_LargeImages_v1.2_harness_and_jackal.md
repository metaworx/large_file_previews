# NOTE LargeImages v1.2: harness and jackal

Blocks 12 and 14, run on 2026-10-14, and what block 15 found on the way.

## Block 15, the packages

Each checked against its distribution's index:
Arch's package file lists, Alpine's contents search (v3.22),
Fedora's mdapi (f43), AlmaLinux 9 and 10's repositories for RHEL,
and packages.fedoraproject.org for EPEL.

- libvips is packaged for neither RHEL nor EPEL;
  the hint says so rather than name a package.
- RHEL's `libtiff-tools` is in CRB, which must be enabled.
- Alpine's `vips-tools` is in community;
  `prlimit` and `choom` are in `util-linux-misc`, not `util-linux`.
- Arch's `libvips` and `libtiff` carry the tools themselves.

## Block 12, the harness

- `nc-test mount 34-lfp large_file_previews <checkout>`:
  Nextcloud 34.0.4, from the cached tarball.
- `instances/34-lfp/.ddev/config.extra.yaml`:
  `poppler-utils`, `libvips-tools`, `libtiff-tools`;
  libvips 8.16.1, libtiff 4.7.0, poppler 25.03 after `ddev restart`.
- `GUIDELINES/config.local.ini` points the PHPUnit wrapper at it.
- Both suites: 269 tests pass; one skipped,
  the test for an image without libvips, since libvips is there.
- `nc-test release 34`: instance 34 stopped,
  the harness's test app mounted again, free.
  Its `config.extra.yaml` still names `poppler-utils`; harmless, and left.

## Block 14, jackal

- helioscloud, Nextcloud 33.0.5: the app swapped in place, as before;
  no database upgrade; the old copy removed after the checks.
- `occ lfp:check --refresh`: every program found, ready;
  libvips 8.15.1, libtiff 4.5.1, poppler 24.02; "Platform: Debian or Ubuntu."
- TIFF switched on: `min_size_mb` 800,
  following helioscloud's `preview_max_filesize_image` of 800.
  So the 760.7 MB TIFF stays with Nextcloud's own providers:
  `lfp:config:get --file` declines it as smaller than the minimum.
- `vipsthumbnail` now reads as needed, the setup check is green.
- The provider called directly, read only, as `www-data`;
  `getThumbnail()` stores no preview:

| File | Preview | Time |
|---|---|---|
| `010700645.tif`, 760.7 MB, 22040 x 12059 | 4096 x 2241, JPEG | 3.55 s |
| the largest HEIC in a home storage, 20.5 MB | 4096 x 978, JPEG | 3.25 s |

- No failure recorded, and no error of the app's
  in the last 50 MB of the log;
  the two errors the setup check counts since 7 October are older.
- A script needs `getUserFolder()` before it can reach a user's file,
  and writes to stderr: Nextcloud's bootstrap buffers stdout.
