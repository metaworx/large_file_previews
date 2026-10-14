# AP ReviewFixes v1.1: review findings

## TL;DR

Fixes every finding of the three reviews of 2026-10-14 (code, documentation,
security),
whose reports are kept verbatim in
`2026-10-14_16-53_NOTE_ReviewFixes_v1.0_review_reports.md`.

- Every program reads the file as `/dev/fd/N`,
  a descriptor PHP opened → no user file name in an argument or in output,
  no symlink, no replaced file
- The re-striped copy → a file unlinked as soon as it is opened,
  passed as a descriptor: gone when the last process ends, whatever kills it
- Disk → `prlimit --fsize` on every run, a lock and a size limit on the copy,
  each a setting
- Untrusted decoders → closed on libvips below 8.13;
  the switch moves to system config only, its app key removed by a repair step
- Outcomes → one judge for both renderers; one deadline per render;
  a failed file not retried for a set time
- Configuration, performance, structure, frontend,
  documentation → each finding fixed

### Blocks

1. Measure on jackal: descriptors, unlinked copies, `--fsize`
2. Inputs and copies by descriptor
3. Disk safety
4. Untrusted decoders in system config
5. Outcomes, deadlines, failures remembered
6. Configuration correctness
7. Performance
8. Structure and tests
9. Frontend
10. Documentation

## Discussion

The user asked, on 2026-10-14,
whether `tiffcp` could write to a file that is opened and then deleted,
and whether that descriptor can be mapped.

- **Yes, on Linux.**
  PHP opens a new file in the scratch directory and unlinks it at once,
  keeping the handle.
  `proc_open()` hands that handle to the child as descriptor 3,
  and the program is given the path `/dev/fd/3`.
  Opening `/dev/fd/N` (`/proc/self/fd/N`) reopens the same inode,
  even when it has no name left,
  so `tiffcp` writes into it and `vipsthumbnail` reads from it,
  each given the same descriptor.
  The space is freed when the last descriptor closes,
  which the kernel does even when a worker is killed: no copy can be orphaned.
- **Mapping works too.**
  libtiff maps its input with `mmap()` where it can;
  an unlinked file maps like any other,
  and where the address-space cap refuses the mapping, libtiff reads instead.
- **Not solved by it:**
  the size of the copy.
  `df` still counts it while it is open,
  so other workers' free-space checks see it,
  but a single copy can still fill the disk:
  that is block 3's `--fsize` and size limit.
- **The same trick for the input** removes a whole class of findings:
  PHP opens the user's file, and every program reads `/dev/fd/4`.
  The name never reaches a program, nor what it prints:
  no forged `vipsheader` line, no "out of memory" in a file name,
  no `]` or `,` to refuse, no symlink,
  and the file cannot be swapped between two runs of one render.
  Block 1 measures it with every program before block 2 builds on it.

Settled with the user on 2026-10-14, after v1.0:

- **The time a failed file is not retried is a setting**,
  `failure_retry_minutes`, 60 by default, 0 for always retry.
- **The copy's maximum and the file-size cap are settings too**,
  with v1.0's defaults: `tiff_restrip_max_mb` 4096,
  `prlimit_fsize_mb` 1024.
- **The untrusted-decoder switch moves to system config entirely**,
  `large_file_previews.vips_block_untrusted`, true unless set:
  whoever can write `config.php` can already run code,
  an administrator without shell access cannot widen what decoders a user's file reaches.
  The app-config key `vips_block_untrusted` is ignored,
  and a repair step removes it, with any other obsolete app-config key;
  it is the only one now.
  v1.0's separate opt-in, `allow_untrusted_decoders`, is not needed.

## Analysis

The findings, grouped as the plan fixes them;
the NOTE has each reviewer's wording.

### Security

- **Disk filling** (medium):
  a TIFF in one highly compressible strip makes `tiffcp` write a copy of its
  full uncompressed size; the free-space check races between workers;
  no file-size cap; copies of a worker that died stay.
  Blocks 2 and 3.
- **`vips_block_untrusted` before libvips 8.13** (medium):
  the variable and the `untrusted` flag are 8.13's;
  on 8.12 (Ubuntu 22.04) nothing is blocked,
  and libvips picks its loader by content.
  Block 4.
- **User names in parsed output** (low):
  a name with a newline forges `vipsheader`'s line;
  a name holding "out of memory" makes any failure look like it.
  Block 2.
- **The block switched off from app config** (low).
  Block 4.
- **stderr files without bound** (low).
  Block 3.
- **Cached paths run unchecked; scratch checks fail open;
  log lines with control characters** (informational).
  Blocks 3, 6 and 8.

### Correctness

- pdftoppm out of memory caught only on exit 0; the renderers judge differently;
  `tiffcp`'s outcome not judged, its reason replacing the first.
  Block 5.
- One render may run four programs, each with the full timeout;
  a failed file is rendered again on every request;
  `last_failure` written each time.
  Block 5.
- `setMimeRule()` stores the merged default,
  freezing the image minimum and making a cleared field fall to 0;
  `unsetMimeRule()` accepts any type; PUT not atomic;
  override-key problems missing from the page; two commands bypass `mimeOf()`.
  Block 6.

### Performance

- A failed `vips -l` run again per request; `runBare()` without a timeout;
  versions asked twice per page load and per save.
  Block 7.

### Structure

- The program list in three places; ConfigSet and ConfigUnset dispatch alike;
  ConfigShow formats rules itself;
  `getThumbnail()`'s catch duplicates `failed()`; constants in the wrong class;
  test set-up repeated in nine files.
  Block 8.
- Frontend: loose `Scalar` typing, three fetch copies, near-identical rule rows,
  hard-coded choices the server sends, an unused export.
  Block 9.

### Documentation

- `info.xml` names PDF only; README: JPEG 2000 listed as available,
  a missing limit "declines" the file, requirements and settings incomplete,
  units mixed; "not ready" texts overstate; CONTRIBUTING,
  the contract and the guidelines' README stale; docblock drift.
  Block 10.

## Implementation Plan

1. **Measure on jackal**, as `www-data` in `/tmp`, user data only read:
   each program (`pdfinfo`, `pdfimages`, `pdftoppm`, `vipsheader`,
   `vipsthumbnail`, `vips copy`, `tiffcp`) reading `/dev/fd/N` under `timeout`,
   `prlimit` and `choom`;
   `tiffcp` writing an unlinked file through `/dev/fd/3` and `vipsthumbnail`
   reading it;
   `prlimit --fsize` stopping a write, and what each program then says.
   *Verification:* the results in a NOTE;
   where a program cannot read a descriptor,
   that program keeps a fixed-name link in the render folder instead.
2. **Inputs and copies by descriptor.**
   `CommandRunner` takes extra descriptors;
   every renderer and the probe pass the input as `/dev/fd/4`;
   the fallback writes and reads its copy through an unlinked file.
   The `]`, `%`-in-input and comma refusals and the symlink go
   where no longer needed.
   *Verification:* unit tests; the integration suites in `34-lfp`;
   a file named with a newline and with "out of memory" rendered correctly.
3. **Disk safety.**
   `prlimit --fsize` on every run, `prlimit_fsize_mb` (1024 by default,
   64 to 65536), switched with `prlimit_enabled`;
   on `tiffcp` the copy's size with a margin;
   a lock (Nextcloud's `ILockingProvider` or `flock` in the scratch directory)
   around the fallback;
   `tiff_restrip_max_mb` (4096 by default, 256 to 1048576):
   a larger copy is not made, and the failure says so;
   free space unknown or `/proc/mounts` unreadable counts as a problem.
   *Verification:* unit tests; on jackal, a copy stopped by `--fsize`.
4. **Untrusted decoders in system config.**
   `large_file_previews.vips_block_untrusted`, true unless set,
   set and unset with `occ lfp:config:set vips_block_untrusted <bool>`
   as the paths are; the lexicon key removed, and its stored value ignored;
   a repair step on install and upgrade deletes obsolete app-config keys,
   now only `vips_block_untrusted`;
   libvips' version from the cached `vips --version`,
   and below 8.13 with the block on every image type is unsupported,
   which `lfp:check` and the page say;
   the page shows the switch read only, with its command.
   *Verification:* unit tests with versions 8.12 and 8.15,
   the repair step in the harness.
5. **Outcomes, deadlines, failures remembered.**
   One judge for both renderers,
   out-of-memory phrases and cap key as parameters,
   checked whatever the exit status, the crash hint for both; `tiffcp` judged,
   reasons appended; one deadline per render, each run given the time left;
   `runBare()` under `timeout`;
   a failed file remembered by file id and etag in the memory cache
   for `failure_retry_minutes` (60 by default, 0 to 10080, 0 never remembers);
   `last_failure` lazy.
   *Verification:* unit tests; integration tests for a failed file not retried.
6. **Configuration correctness.**
   Changes laid over the stored rule only; `unsetMimeRule()` validated;
   PUT validates everything before it writes, and a refusal carries the state;
   override-key problems on the page;
   `ConfigGet` and `ConfigShow` use `mimeOf()`;
   scratch override and cached paths checked again when read.
   *Verification:* unit tests for each.
7. **Performance.**
   `VipsLoaders` remembers "unknown" per instance and for a minute in the cache;
   versions memoized per instance; `check()` and `programList()` from one pass.
   *Verification:* unit tests counting runs.
8. **Structure and tests.**
   One table of programs (version flag, need, purpose,
   package source) for BinaryLocator, PrerequisitesService and Platform,
   with a test tying them; ConfigSet and ConfigUnset share their dispatch;
   ConfigShow uses `Base::fields()`;
   `getThumbnail()`'s catch goes through `failed()`;
   the quality constant in `PreviewFormat`; one constant for `vips`;
   the log line stripped of control characters and invalid UTF-8;
   the test traits give a ConfigurationService and a BinaryLocator.
   *Verification:* the suites unchanged in what they test.
9. **Frontend.**
   A `ScalarKey` union and typed values;
   one `request()` helper with `@nextcloud/auth`'s token,
   refresh reading the server's error;
   one rule-row component for MIME types and extensions;
   one renderer-problem helper; `differs()` by field;
   the format choices from the server; `n()` removed; "Address-space cap";
   "processed" as the page's one word;
   the new settings with their commands.
   *Verification:* specs, lint, typecheck, build.
10. **Documentation.**
    `info.xml`; README (types and the untrusted block,
    what a missing limit does, every requirement,
    every setting with its default and range, units in MiB,
    the scratch directory's checks, the minimum libvips, the descriptors,
    the switch in system config);
    the "not ready" texts; CONTRIBUTING, `_CONTRACT.md`,
    `GUIDELINES/project/_README.md`; each docblock the reviews named; CHANGELOG.
    *Verification:* the reflow check; a second documentation review by an agent.

## Proposed commit messages

Registration: `[UPDATE] APs: +ReviewFixes v1.0-1.1, +NOTE ReviewFixes v1.0`,
with a record for each.
Then `[SECURITY]` for blocks 2 to 4, `[FIX]` or `[TASK]` for the rest,
one or more per block, each with its tests and CHANGELOG bullet.

## Change History

- v1.0 (2026-10-14): first version.
- v1.1 (2026-10-14): `failure_retry_minutes`, `tiff_restrip_max_mb` and
  `prlimit_fsize_mb` as settings; the untrusted-decoder switch in system config
  only, its app key removed by a repair step.
