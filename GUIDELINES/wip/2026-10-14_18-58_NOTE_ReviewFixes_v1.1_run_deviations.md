# NOTE ReviewFixes v1.1: run deviations

Where the run of blocks 2 to 10 went differently from the plan,
block by block.
Nothing here was reverted or left undone;
each is a choice made in the block, and the reason for it.

## Block 3

- **The lock waits within the render's time.**
  Block 3 waited up to `timeout_seconds` for the one-strip lock;
  block 5 made the wait the time the render has left (Deadline).

## Block 4

- **The switch is `large_file_previews.vips_allow_untrusted`,**
  false unless set, at the user's word,
  in place of the plan's `vips_block_untrusted`, true unless set.
  `occ lfp:config:unset vips_allow_untrusted` blocks them again.
- **Only the value `true` allows them.**
  A string `'true'` in `config.php` blocks, as anything else does.
- **The libvips version is `vipsthumbnail --version`'s,**
  the program that renders, rather than `vips`'s:
  without `vipsthumbnail` nothing is rendered anyway.
  A version that cannot be read counts as one that cannot block.
- **`ProgramVersions` was taken out of `PrerequisitesService`**
  so that `VipsLoaders` asks the same cache;
  block 7's memo went into it.
- **The harness test of the repair step writes the obsolete row directly:**
  Nextcloud's `IAppConfig` writes no key the lexicon does not declare.

## Block 5

- **A failure is remembered under the settings' fingerprint,**
  as well as the file's id and etag:
  every setting, and system config's untrusted switch, scratch directory,
  program paths and, after the second review, `preview_format`.
  A change to any of them tries the file again at once.
  The rules are left out:
  they decide which files are taken, not how one renders.
- **The memory is kept in `DetectionCache`,**
  so that `occ lfp:check --refresh` and the page's button forget it with the programs.
- **A failed one-strip fallback takes the fallback's outcome,**
  with the first reason before its own,
  rather than keeping the first outcome with the reasons appended.
- **The integration test of a file not retried skips itself without a memory cache.**
  It ran in `34-lfp`.

## Block 6

- **"PUT validates everything before it writes" became `ConfigurationService::apply()`,**
  which the occ commands' single changes go through as well.
- **A cached path is checked again; what the finder answers is not,**
  since the finder checks it itself,
  and the tests' stand-in paths would fail the check.
- **A bad scratch override is reported, not refused when read.**
  The render's own check, at copy time, still fails closed.

## Block 7

- **`check()` and `programList()` were not merged into one pass.**
  Each program's version is asked once a request (`ProgramVersions`),
  and block 8 gave both the one program table.

## Block 8

- **The test helper is `settings()`, not `configuration()`:**
  the coding standard files a `config…` method under its own section.
- **`BinaryLocatorTest` and `VipsLoadersTest` keep their own `BinaryLocator`,**
  the one as the unit under test, the other without the memory config.

## Block 9

- **The limit is named "Address-space and file-size caps"**
  rather than the plan's "Address-space cap":
  since block 3, `prlimit_enabled` switches the file-size cap too.
- **"Active rendering" became "What is processed"**,
  for "processed" as the page's one word.

## Block 10

- **Three measured sizes could not be measured again:**
  the tiled pyramid, the WebP and the HEIC, whose files are gone from jackal.
  They stay as measured, marked "about";
  the others are computed in MiB.
- **The second documentation review's findings were fixed in the block,**
  two of them in code:
  the setup check names a missing `/dev/fd` as such,
  and a version that could not be read is kept a minute rather than a day.
- **The second review's finding on the scratch directory was met in the README,**
  which now says that tmpfs and free space are checked only when a copy is needed,
  rather than by reporting them on the page:
  Nextcloud's temporary directory is often on tmpfs,
  and the page would warn on servers that never re-stripe a TIFF.
