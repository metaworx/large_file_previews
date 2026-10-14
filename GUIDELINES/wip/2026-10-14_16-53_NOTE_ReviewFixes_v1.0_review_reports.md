# NOTE ReviewFixes v1.0: review reports

The three reviews of 2026-10-14, after AP LargeImages retired,
each by an agent reading the repository without changing it.
Their reports follow verbatim, as each agent handed it back.

## Code

```text
## Code review of large_file_previews

This was a read-only review. I ran `phpunit --testsuite unit` (250 tests passed, 4 skipped) and `npm run typecheck` (clean). I did not run `composer psalm` or `npm test`. Every line reference below was checked against the code, and I say where I am unsure.

### Most important

**1. Defect: pdftoppm running out of memory is only caught when it exits 0** (`lib/Renderer/PopplerRenderer.php:140`)
- **What's wrong:** "Out of memory" in stderr is only checked when the run succeeded. As I recall poppler's `gmalloc()` (from memory, not checked here), it prints "Out of memory" and then calls `abort()`. Only the `checkoverflow` path returns, which gives the tiny-PNG case the comment describes.
- **Consequence:** under the cap, a PDF can end as `KILLED` "killed by signal 6", with no out-of-memory outcome and no `prlimit_as_mb` hint. `VipsRenderer::judged()` (`VipsRenderer.php:465-487`) does it the other way round: out of memory only on failure, plus a hint for signals 6 and 11. The two renderers form their outcomes inconsistently.
- **Fix:** share one judge, taking the out-of-memory phrases and the cap key as parameters. Check stderr whatever the exit status, add the crash-signal hint for both, and check that the output file exists.

**2. Defect: `unsetMimeRule()` does not validate the MIME type** (`lib/Service/ConfigurationService.php:352-373`)
- **What's wrong:** `setMimeRule()` refuses a MIME type that `SupportedTypes::matches()` rejects; unset does not.
- **Consequence:** `occ lfp:config:unset --mime foo/bar --field enabled` stores `"foo/bar": []`, prints "MIME type foo/bar: cleared" and exits 0. The page's PUT `{"mimetypes":{"foo/bar":{"enabled":null}}}` does the same. The next `load()` then reports it as "not a MIME type this app handles", so a typo leaves a permanent problem entry.
- **Fix:** run the same `matches()` check, at least when fields are given. Without fields, an unknown type could still be allowed, so junk can be removed.

**3. Defect (performance): a failed `vips -l` is re-run for every file, and the provider's docblock is wrong** (`lib/Renderer/VipsLoaders.php:85-94`, `lib/Preview/LargeFileProvider.php:36,93`)
- **What's wrong:** when `vips -l foreign` fails, or parses to `[]` (for example after a libvips output change), nothing is remembered, not even per instance.
- **Consequence:** every `isAvailable()` call for an enabled image type in the size range starts the process again. A folder listing of 200 TIFFs means 200 runs. `isAvailable()`'s docblock says "it does no I/O", but it reaches `unsupported()` → `loaders()`, which can start a process.
- **Fix:** keep a per-instance "asked, unknown" flag; optionally cache the failure briefly. Correct the docblock.

**4. Improvement: the settings state runs each version program twice, on every GET and PUT** (`lib/Controller/SettingsController.php:309,326`; `lib/Service/PrerequisitesService.php:261-280`)
- **What's wrong:** `state()` calls `check(true)` and `programList()`, which both call `version()` for every program found. `version()` has no per-instance memo; it relies only on `DetectionCache`.
- **Consequence:** without a memcache configured, `ICacheFactory` hands back a `NullCache` (true in Nextcloud core as far as I know), so each page load, and each single toggle (PUT returns `state()`), starts up to about 20 processes plus `vips -l`.
- **Payload duplication:** `prerequisites[].detail` and `programs[]` carry the same path, source and version.
- **Fix:** memoize versions per instance; build `programList()` and `check()` from one pass. Consider leaving the prerequisites out of the PUT response.

**5. Improvement: `runBare()` runs with no timeout at all** (`lib/Service/CommandRunner.php:218-237`)
- **What's wrong:** it is used in the request path (`VipsLoaders`, from `isAvailable()`) and by the settings page.
- **Consequence:** a hung `vips -l` (a broken module, slow NFS) or an odd overridden binary blocks a PHP worker indefinitely. That is exactly what the app otherwise guards against.
- **Fix:** wrap it in `timeout` when that program is found, with a short fixed limit.

**6. Improvement: `timeout_seconds` applies per program run, not per render** (`CommandRunner::run`; `VipsRenderer::render`/`restripped`; `PageProbe::scanBox`)
- **What's wrong:** a PDF render is pdfinfo + pdfimages + pdftoppm, plus `vips copy` for WebP. A TIFF render is vipsheader + vipsthumbnail + tiffcp + vipsthumbnail.
- **Consequence:** worst case is about 4 × (30 + 5) s ≈ 140 s for one preview request. The lexicon calls it "Seconds a render may take" (`ConfigLexicon.php` `TIMEOUT_SECONDS`).
- **Fix:** pass a deadline through the render and give each run the time remaining, or document that the limit is per run.

**7. Improvement (possibly intended): enabling an image type freezes its derived minimum** (`lib/Service/ConfigurationService.php:334`)
- **What's wrong:** `setMimeRule()` lays the change over `mimeRules()[$mime]`, which for an unstored type is the default including `min_size_mb` from `preview_max_filesize_image`.
- **Consequence:** switching `image/tiff` on in the page stores `{enabled:true, min_size_mb:50}`. If the admin later raises `preview_max_filesize_image` to 100, the app still takes 50–100 MB files ahead of core's providers. That contradicts `ConfigLexicon`'s "the app takes exactly what they leave".
- **Also:** the lexicon default for `mimetypes` is the static 50, which differs from `ConfigurationService::defaultMimeTypes()` on servers that set the key.
- **Fix:** lay changes over the stored rule only (`storedMap()`), not over the merged default.

**8. Improvement: the one-strip fallback loses the original reason and skips out-of-memory detection** (`lib/Renderer/VipsRenderer.php:326`)
- **What's wrong:** tiffcp's outcome is not passed through `judged()`, so tiffcp running out of memory ("No space for data buffer") is `FAILED`. Its reason also replaces the first attempt's out-of-memory reason, while the other four fallback exits (294, 302, 307, 318) append to it.
- **Fix:** judge tiffcp's outcome like the others and keep the out-of-memory prefix. The four `$outOfMemory->as(OUT_OF_MEMORY, reason.'; no one-strip fallback: '...)` lines could share one closure.

**9. Improvement: the program list is spelled out in three places**
- **Where:**
  - `BinaryLocator::PROGRAMS` (`BinaryLocator.php:46`)
  - `PrerequisitesService::PROGRAMS` (`:31`)
  - `Platform::SOURCES` (`:93`)
- **Consequence:** `programList()` iterates BinaryLocator's list but `check()` iterates its own. A program added to one only shows on the page without a version, or never becomes blocking. No test ties `check()` to `BinaryLocator::PROGRAMS`; `PrerequisitesServiceTest:157` only covers `programList()`, which is tautological.
- **Related duplicates:**
  - `VipsLoaders::PROGRAM` and `VipsRenderer::CONVERTER` are both `'vips'`.
  - `PrerequisitesService::permissions()` (`:154`) repeats `CommandRunner::FUNCTIONS`, which is private.
- **Fix:** one table holding version flag, limit, purpose and package source per program, plus a test.

**10. Improvement: two commands bypass `ConfigurationService::mimeOf()`** (`lib/Command/ConfigGet.php:123`, `lib/Command/ConfigShow.php:482`)
- **What's wrong:** they call `IMimeTypeDetector::detectPath()` directly, so no normalisation and no mapping of `application/octet-stream` to null.
- **Consequence:** `config:get --ext xyz` and `config:show` report `application/octet-stream`, while the page (`SettingsController.php:283`) and `load()` report no type.
- **Fix:** use `mimeOf()`. `ConfigShow` then no longer needs `IMimeTypeDetector`.

**11. Improvement: the page's "Set but not used" omits override-key problems** (`SettingsController.php:323` vs `ConfigShow.php:313`)
- **What's wrong:** `config:show` merges `binaries->problems()`; the controller does not.
- **Consequence:** a typo such as `binaries.pdftopm` in config.php is invisible on the page.
- **Related:** a hand-written scratch override that is relative or not writable is never reported anywhere. It is only found when the TIFF fallback runs.

### Structure and duplication

**12. Improvement: `ConfigSet` and `ConfigUnset` duplicate their dispatch** (`ConfigSet.php:83-160`, `ConfigUnset.php:298-355`)
- Both do the "--mime xor --ext xor key" check, then `scratch_directory`, then the `binaries.` prefix, then the scalar, with the same error texts.
- **Fix:** a helper in `Base` that classifies the target.

**13. Nit: `ConfigShow` re-implements `Base::fields()`** (`ConfigShow.php:377-383`)
- The yes/no, size and "none" formatting is rebuilt from the `toArray()` output, so the two can drift.

**14. Improvement: `getThumbnail()`'s catch duplicates `failed()`** (`LargeFileProvider.php:185-209`)
- **Fix:** route through `failed()` with a `START_FAILED` outcome, adding the exception to the log context.

**15. Improvement: PUT is not atomic** (`SettingsController.php:112-127,169-193`)
- `applyRule()` unsets the null fields, then sets the rest. If the set is refused (for example a max below -1), the unset has already been stored, and the 400 carries no state, so the page shows stale data. The same applies across parts of one body.
- **Fix:** validate everything first, or return the state with the error.

**16. Nit: `runBare()`'s messages differ from `run()`'s** (`CommandRunner.php:228,233` vs `:131,:104`)
- Program missing drops `$location->problem`, and the proc_open text differs.

**17. Nit (unsure): `DetectionCache`'s local branch looks dead** (`DetectionCache.php:358-360`)
- If I remember Nextcloud's `Memcache\Factory` correctly, the distributed class falls back to the local one. Then `isAvailable()` is false only when both are `NullCache`, so `createLocal()` always returns a `NullCache`.

**18. Nit: renderer coupling and constants**
- PopplerRenderer uses `VipsRenderer::QUALITY` (`PopplerRenderer.php:130`); move it to `PreviewFormat`.
- `Unsupported`, `Prerequisite` and `BinaryLocation` use string constants while `Outcome`, `Verdict`, `Source` and `PreviewFormat` are enums.
- `TypePolicy` round-trips `Source::MIME->value` / `Source::from()` (`TypePolicy.php:311-323`) only because array keys cannot be enums; a list of pairs avoids it.

### Frontend

**19. Improvement: loose typing**
- `Scalar.value` is `number | boolean | string` inside `Record<string, Scalar>` (`types.ts:9,119`), which forces 17 `as number/boolean/string` casts in the `.vue` files. A missing key fails only at runtime.
- `statusText(status: string)`, `sourceText(source: string)` and `processingText(processing: string)` take `string` although `types.ts` defines the unions. `LIMITS` keys are plain `string`.
- **Fix:** a `ScalarKey` union, plus per-key value types or a discriminated `Scalar`.

**20. Improvement: three copies of the fetch logic in `useSettings.ts`** (`:166-236`)
- `refresh()` ignores the server's `{error}` body, which `save()` reads.
- `load()` sends no request token (it relies on Nextcloud intercepting fetch), while `save()`/`refresh()` use the deprecated global `OC.requestToken`.
- **Fix:** one `request()` helper, with `getRequestToken()` from `@nextcloud/auth`.

**21. Improvement: duplicated markup and logic in `TypesSection.vue`**
- The MIME row (`:185-231`) and the extension row (`:250-302`) are near-identical: switch, two clearable `NumberField`s, a button. A `RuleRow` component would serve both.
- `noRenderer()` (`:82-93`) and the badge chain in `ActiveTable.vue` (`:174-178`) branch on the same renderer states separately. One `rendererProblem(renderer)` helper would do.
- `differs()` compares with `JSON.stringify`, which depends on key order. It works today, but is fragile.

**22. Nit: dead code and hard-coded choices**
- `n()` in `src/l10n.ts:18` is exported but never imported.
- `PreviewsSection.vue:126-140` hard-codes `FORMATS`/`BACKGROUNDS`, although the server sends `scalars.preview_format.choices`. A new choice would not appear.

### Tests

**23. Improvement: repeated test setup**
- `new ConfigurationService($this->memoryAppConfig(), $this->englishL10n(), $this->mimeDetector(), $this->systemConfig())` appears in about 9 test files: CommandRunner, PageProbe, VipsRenderer, Prerequisites, TypePolicy, RendererCheck, Provider, Controller, ConfigCommands.
- An `IBinaryFinder` stub with a `findBinaryPath` callback, plus `new BinaryLocator(...)`, is rebuilt in 6 files (CommandRunnerTest:315, PrerequisitesServiceTest:212, VipsLoadersTest:116, SettingsControllerTest:249, ConfigCommandsTest:266, BinaryLocatorTest:146).
- **Fix:** move `configurationService()` and `binaryLocator(array $paths)` into the existing helper traits (`MemoryAppConfig`, `MemoryCache` and the rest).

### Checked and fine

`CommandRunner`'s wait, signal and `readEdges` logic; `ScratchDirectory::fileSystem`; `PageProbe`'s parsing; `TypeRule` validation; controller admin and CSRF handling. Unusually, the controller is a plain `Controller` serving `#[ApiRoute]`s, so responses are not OCS-wrapped. The frontend relies on that; it works, but is unconventional.
```

## Documentation

```text
## Documentation review of large_file_previews

This was a read-only review: no files were changed and no git commands that change state were run. I checked each finding against the code cited. Line numbers are file lines. Findings run from most to least important.

### High

1. **appinfo/info.xml:6, 7–16 is stale.**
   - The summary says "Previews of PDF and Illustrator files…".
   - The description lists only PDF/AI "at the full preview size", plus "a memory cap".
   - The code also renders 14 image types through libvips (SupportedTypes.php:44-60, VipsRenderer).
   - A scanned page is rendered at its own resolution, not full size (PopplerRenderer.php:110-115).
   - Fix: add an images bullet (vipsthumbnail, each type off until switched on, tiffcp second chance), say a scan keeps its own resolution, and change "memory cap" to "address-space cap". This text is what the app store shows.

2. **README.md:22-23 lists JPEG 2000 as always available, but it is blocked by default.**
   - README:159, ConfigLexicon.php:165 and VipsRenderer.php:163 block JPEG 2000 out of the box. JPEG XL, BMP and PNM, in the "where libvips reads them" group, are blocked by default as well.
   - Fix: "TIFF, JPEG, PNG, WebP, GIF, HEIC and HEIF, and AVIF where the server's libvips reads it; JPEG 2000, JPEG XL, BMP and PNM only once `vips_block_untrusted` is switched off."

3. **"A limit switched on whose program is missing declines the file" is wrong.** This is in README.md:137 and GUIDELINES/project/_CONTRACT.md:111.
   - isAvailable() never checks the limits (LargeFileProvider.php:69-125).
   - The render starts, fails with LIMIT_MISSING (CommandRunner.php:193-201), is logged as a warning on every preview, and is recorded as the last failure (LargeFileProvider.php:223-243). Only then does the next provider get its turn.
   - Fix: "…fails the render rather than run without it: a warning in the log, the last failure, and an error in the setup check; the preview is left to the next provider."

4. **The "not ready" messages claim more than is true.**
   - Places: Check.php:123 ("Not ready: large previews are not rendered until what is missing is there, or its limit is switched off"), RendererCheck.php:83 ("Large previews cannot be rendered: …") and StatusSection.vue:62.
   - Blocking is global (PrerequisitesService.php:89-100). With an image type on and vipsthumbnail missing, PDFs still render.
   - The remedy for vipsthumbnail is to switch the image types off, not "its limit". For gd or proc_open there is nothing to switch off.
   - Fix: say "some large previews…", and "…or what needs it is switched off".

5. **README.md:40-49 (Requirements) leaves out blocking prerequisites.** All of these appear in PrerequisitesService.php:150-195:
   - the PHP extension `gd`, which is blocking;
   - `proc_open`, `proc_get_status` and `proc_close` not listed in disable_functions;
   - a writable temporary directory;
   - `sysvsem`, which is optional.

6. **README.md:44-47 makes vipsheader and vips look required, and the tiffcp fallback needs vipsheader.**
   - In code, vipsheader and vips are always optional ('limit' => false, PrerequisitesService.php:40, 43).
   - The fallback runs only when the header was read (`$header !== null && loader === 'tiffload'`, VipsRenderer.php:219). Without vipsheader there is never a second chance.
   - Fix README:46 to "optionally `vipsheader` and `vips`…" and README:144 to "where `tiffcp` and `vipsheader` are installed". Add the same to the 'for' text of PrerequisitesService.php:40.

### Medium

7. **The timeout applies to each program run, not to a render.**
   - Places: ConfigLexicon.php:113 "Seconds a render may take", README.md:131, LimitsSection.vue:37.
   - CommandRunner wraps every run() on its own. A PDF render runs pdfinfo, pdfimages, pdftoppm and possibly `vips copy`. An image render runs vipsheader and vipsthumbnail, plus tiffcp and a second vipsthumbnail on the fallback.
   - A render can therefore take several times timeout_seconds. Fix: "Seconds each program a render runs may take".

8. **An image's start size is frozen once it is first set, and clearing it drops to 0.**
   - README.md:92-93 and ConfigLexicon.php:75-79 say images follow `preview_max_filesize_image`. That holds only until the type is first written.
   - setMimeRule() merges the shipped rule and stores min_size_mb with it (ConfigurationService.php:334-339). After that, a change to preview_max_filesize_image is not followed.
   - Clearing that field (`config:unset --mime image/tiff --field min_size_mb`, or emptying it on the page) falls to `min_size_mb`, which is 0, not 50. The app then takes images of every size ahead of Nextcloud's providers. ConfigUnset.php:69-70 only says "falls to the app's default".
   - Fix: document both; Reset restores the shipped rule.

9. **README.md does not document several settings that are in the lexicon:**
   - `timeout_kill_after_seconds` (5, 1–60);
   - `min_size_mb` (0) and `max_size_mb` (-1) by name; README:89 says only "the app's default";
   - the switch names `timeout_enabled`, `prlimit_enabled` and `choom_enabled`; README:130 says only "each switchable";
   - that `prlimit_enabled` also switches `vips_prlimit_as_mb` (ConfigLexicon.php:141);
   - the ranges: timeout 1–600, caps 256–65536, choom 0–1000, coverage 50–100, threads 1–64.

10. **README.md:152 says "a directory on tmpfs or ramfs is refused", but setting one succeeds.**
    - ScratchDirectory::set() checks only that the path is absolute, a directory and writable (ScratchDirectory.php:111-129).
    - tmpfs and free space are checked only when the fallback runs (problem(), :79-103). The fallback is then skipped with that reason.
    - Fix: "…on tmpfs or ramfs, the second chance is not tried, and the failure says why."

11. **CONTRIBUTING.md:99 and :107 still name only `poppler-utils`.**
    - _CONTRACT.md:135, CI.md:103 and test.yml:158 need poppler-utils, libvips-tools and libtiff-tools.
    - Fix: `webimage_extra_packages: [poppler-utils, libvips-tools, libtiff-tools]`.
    - GUIDELINES/project/_README.md:6, 8 and 24 are equally stale ("and later very large images", "`pdftoppm` first", poppler-utils only).

12. **GUIDELINES/project/_CONTRACT.md:12 says "libvips for large images later".** libvips is implemented. Fix: "`pdftoppm` (poppler) for PDF and Illustrator files, libvips for large images."

13. **The size of the one-strip TIFF disagrees between files.**
    - The 15000×15000 one-strip LZW TIFF is 349 MB in README.md:246, 365 MB in VipsRenderer.php:57 and 348 MB in VipsRenderer.php:265. That fits MiB against decimal MB for one file.
    - The README table mixes the two units as well. 22040×12059×3 = 797 MB decimal, shown as 798 MB. 15000²×3 = 675 MB decimal = 644 MiB, shown as 644 MB.
    - Fix: pick one unit (MiB, matching the caps).

### Low

14. **LargeFileProvider.php:35-37 says isAvailable() "does no I/O". That is not true on a cache miss.**
    - It calls `$renderer->unsupported()`, which runs `vips -l foreign` on a cache miss (VipsLoaders.php:88).
    - Without a memcache that happens on every request (DetectionCache.php:23-25).
    - It also asks the binary finder and `getStorage()->isLocal()`.

15. **CommandRunner.php:28-30 says "stdout goes nowhere, since the programs write files".** pdfinfo, pdfimages, vipsheader and runBare keep stdout with stderr (`$keepStdout`, :263-265). Fix: "stdout goes nowhere unless a program answers on it".

16. **Two docblocks still name PNG only.** ImageLoader.php:16 says "Loads a rendered PNG" and LargeFileProvider.php:263 says "the PNG". The output is PNG, JPEG or WebP.

17. **VipsRenderer.php:268-269 says "Where the copy cannot be made, the render stays out of memory". That covers only failures before tiffcp starts.** If tiffcp runs and fails, the outcome is tiffcp's own (FAILED, TIMED_OUT, KILLED or NO_OUTPUT, :326). Running out of memory in tiffcp is not judged as OUT_OF_MEMORY.

18. **Prerequisite.php:32-35 defines OPTIONAL too narrowly.** It says "an optional extension, or a limit switched off". It also covers pdfinfo, pdfimages, vipsheader, vips and tiffcp, which are always optional, and vipsthumbnail while no image type is on.

19. **ConfigLexicon.php:235 (`last_failure` definition) leaves out `outcome`.** "time, MIME type, reason and exit status" misses `outcome`, which is stored (ConfigurationService.php:487).

20. **ConfigSet.php:54 (the `value` argument help) is incomplete.** "a number, or true/false" leaves out words (`preview_format`, `preview_background`) and paths (`binaries.*`, `scratch_directory`).

21. **README.md:125 says config:get resolves a "size".** `--size` alone is refused ("Give --mime, --ext or --file.", ConfigGet.php:114-118). It only goes with --mime or --ext.

22. **README.md:79-81 and AdvancedSection.vue:70 leave out how detection is cached.** Missing:
    - the cache keeps it for a day (DetectionCache.php:33);
    - without a memory cache nothing is kept;
    - it is also found again by the page's "Find programs again" button and on install. The page text omits install and the day.

23. **The terms for the cap differ.** The UI switch is "Memory cap" (LimitsSection.vue:45), and info.xml:12 and _README.md:9 say "memory cap". README:132/250, the lexicon and the failure reasons (PopplerRenderer.php:142, VipsRenderer.php:472) say "address-space cap". Fix: use "Address-space cap" in the UI.

24. **"Taken" and "processed" mean the same thing.** README, Verdict and config:get say "taken"/"takes". The page says "processed"/"Processing", with enabled/excluded. Pick one word, or say they are the same.

25. **GUIDELINES/project/_CONTRACT.md:113-114 says the file "is never copied to a temporary file first".** The one-strip fallback does make an uncompressed copy. README:212 already says so; add "except the re-striped TIFF of the one-strip fallback".

26. **`vips_concurrency` reaches only vipsthumbnail.** `vips copy` (PDF→WebP, VipsRenderer.php:371-377), vipsheader and tiffcp run with libvips' default threads. ConfigLexicon.php:173 says "Threads libvips may use for one render". I am not sure how much this matters in practice.

27. **The list of blocked decoders is stated as fact (ConfigLexicon.php:165, README.md:159, AdvancedSection.vue:50).** The code blocks whatever `vips -l` marks untrusted (VipsLoaders.php:108-119), and that set can differ by libvips version and build. Suggest "as libvips 8.15 marks them". I am not sure the set really differs.

28. **AdvancedSection.vue:103 (scratch directory) is incomplete.** "on disk, not tmpfs, with room for the image" leaves out ramfs, writability, and the 256 MB on top of the uncompressed size (ScratchDirectory.php:40, 97).

### Checked and consistent

- Package and program names: README table against Platform.php.
- occ command names and aliases, and the README examples.
- Defaults in the README against ConfigLexicon: timeout 30, caps 512/1024, choom 1000, scan 90 %, concurrency 1.
- The 96 MB / 512 MB PDF figures.
- The HEIC/WebP 640/768 figures.
- CI.md against test.yml, and the lint commands in the contract.
- CHANGELOG.md and ROADMAP.md.
- PageProbe, PopplerRenderer and SettingsController docblocks.
- The settings-page help for formats, background, scans and extensions.
```

## Security

```text
## Security review: large_file_previews (read-only; no files changed, no git state changed)

I found no critical or high findings. Programs are started with an argument array, so no shell is involved. Every user path is absolute, so it can't be read as an option. App config holds no paths or commands. There are two medium findings, four low ones and a few informational items.

### MEDIUM 1: Disk exhaustion through the one-strip TIFF fallback
**Where:** lib/Renderer/VipsRenderer.php:285-290 (size estimate and free-space check), :322 (tiffcp), :335-340 (cleanup); lib/Service/ScratchDirectory.php:79-103

**Attack (input → effect):**
1. A user uploads a TIFF of 50 MB or more (the image minimum) holding one Deflate/LZW strip of zeros. Such data compresses about 1000:1, so the file expands to about 50 GB.
2. vipsthumbnail genuinely runs out of memory under the 1 GB cap, which triggers `restripped()`.
3. `problem($bytes)` passes whenever there is 50 GB plus 256 MB free.
4. tiffcp decodes scanline by scanline, so it stays under the address-space cap. It writes the uncompressed copy to the scratch directory. By default that is Nextcloud's temp dir, often on the root filesystem next to the database.

What makes it worse:
- **The free-space check races.** Several workers, or one user requesting several such files at once, all pass the check before anyone has written. Together they fill the disk. Only the timeout bounds the damage (about 30 s × write speed per run, more if an admin raised it).
- **Nothing caps the file size.** prlimit sets `--as` but not `--fsize`.
- **Copies can be orphaned.** If the worker dies (FPM `request_terminate_timeout`, OOM kill, fatal error), the `finally` never runs. `lfp-restrip-*` directories holding multi-GB copies then stay forever. Nextcloud's `cleanOld()` only removes `oc_tmp_*` entries, and nothing else sweeps them.
- **libvips spill is unchecked too.** TMPDIR (the render folder) has no free-space check, so any libvips disc spill to a temp image is also unbounded.

**Exploitable?** Yes, by any user who can upload, once TIFF previews are enabled and tiffcp is installed. Unauthenticated public-link viewers can trigger the render too.

**Fix:**
- Pass `prlimit --fsize=<estimate + margin>` for tiffcp, and a modest `--fsize` for every run. RLIMIT_FSIZE bounds the copy whatever the header says, and covers stderr and spill files.
- Serialise the fallback with a lock (flock on a file in the scratch dir, or Nextcloud's locking), or reserve the space under the lock.
- At the start of each fallback, sweep `lfp-restrip-*` entries older than about 2 × the timeout.
- Consider a hard maximum for the copy size (a setting that defaults to a few GB).

### MEDIUM 2: `vips_block_untrusted` does nothing on libvips older than 8.13, and the per-type decoder check isn't a security boundary
**Where:** lib/Renderer/VipsRenderer.php:150-166 and :388-402; lib/Renderer/VipsLoaders.php:110

**Scenario:**
- `VIPS_BLOCK_UNTRUSTED` and the `untrusted` flag in `vips -l` only exist from libvips 8.13. Ubuntu 22.04 ships 8.12 and Debian 11 ships 8.10.
- On those versions `parse()` marks every loader as trusted, and libvips ignores the variable. The page and README still say untrusted decoders are blocked.
- libvips picks its loader by sniffing the content, not by the extension or MIME type. A file named `x.tif` that is really PSD, DCM, XCF, PostScript or similar goes to magickload, meaning ImageMagick and its delegates. That is exactly the decoder set the switch is meant to block.
- On 8.13 and later, the environment variable does block it at runtime. `unsupported()` only compares the decoder expected for the declared MIME type. It shouldn't be relied on: trusted but content-sniffed loaders such as svgload, pdfload and heifload stay reachable from any enabled image type.

**Exploitable?** Yes, by any uploader, on libvips 8.12 or older with an image type enabled. Image types are off by default.

**Fix:** Run `vips --version` (it is already cached). While `vips_block_untrusted` is on and the version is below 8.13, fail closed: mark every image type unsupported and report it as a blocking prerequisite. State the minimum libvips version in the README.

### LOW 3: libvips output is matched by text that contains the user's file path
**Where:** lib/Renderer/VipsRenderer.php:207-208 with the regex at :439; :470 and :489-500 (out-of-memory detection)

**Two problems:**
- **Forged header.** `header()` takes the *first* line that matches, and the path vipsheader echoes is user-controlled. I tested the regex in PHP.
  - Input: a TIFF at `…/x: 64x64 uchar, 1 bands, b-w, tiffload\ny.tif` (the name contains a newline).
  - Effect: the header parses as 64×64×1, so the free-space estimate (Medium 1) is bypassed. The forged size can also shrink the box, or set the loader to `tiffload` and trigger the fallback.
  - Without a newline it is safe, because the greedy `.*` takes the real line.
  - Nextcloud's own filename validation rejects control characters on WebDAV upload. A newline only arrives through scanned or local external storage, hence Low.
- **Misread errors.** The out-of-memory check matches stderr substrings, and libvips error messages include the input path.
  - Input: a corrupt TIFF named `out of memory.tif`.
  - Effect: every failure is classed as out of memory. That reaches the tiffcp fallback deterministically, and the recorded failure tells the admin to raise `vips_prlimit_as_mb` when memory was never the problem.
- **A second route to forging.** A user can also replace the file (upload a new version) between vipsheader and tiffcp. The symlink is resolved again at tiffcp time.

**Fix:**
- Run every libvips and tiffcp call on a fixed-name symlink in the render folder, e.g. `<tmp>/input`. That keeps names out of the output and out of stderr, and also covers the `]` case.
- Or use `vipsheader -f width|height|bands|format|vips-loader`, which prints only the values.
- Make the `--fsize` cap from Medium 1 the real bound.

### LOW 4: One preview can run much longer than the timeout, and failures are retried without limit
**Where:**
- lib/Renderer/PageProbe.php:67 and :81, plus PopplerRenderer.php:120 and :155: up to 4 runs per PDF
- VipsRenderer.php:207, :239, :322, :329: up to 4 runs per image
- LargeFileProvider.php:243: a failure record written per failed render

**Scenario:**
- Each run gets its own `timeout_seconds + kill_after`. With defaults that is about 140 s per preview request; at the maximum settings it is about 44 min.
- Nothing remembers that a file failed (no negative cache), so every request for its preview, including from an unauthenticated public-link viewer, runs it again.
- Concurrency is bounded only by Nextcloud's `preview_concurrency_*`, and that needs sysvsem, which is marked optional.
- Each failure also writes `last_failure` (`lazy: false`, ConfigLexicon.php:309), i.e. one appconfig write per failure.

**Fix:**
- Give each render one deadline and pass the remaining time to each run.
- Cache failures per fileid+etag for a while.
- Make sysvsem a warning.
- Make `last_failure` lazy, or throttle its writes.

### LOW 5: Hardening switches are writable by an admin without shell access
**Where:** lib/Config/ConfigLexicon.php:163; `prlimit_enabled`, `timeout_enabled` and the caps

An admin can switch off `vips_block_untrusted` through the provisioning API and then upload content that reaches ImageMagick (see Medium 2). This is wider decoder exposure, not code execution as such, but it cuts against the app's own model ("whoever can write config.php is trusted"). Nextcloud itself keeps `enabledPreviewProviders` in config.php for a similar reason.

**Fix:** Allow untrusted decoders only when a system-config opt-in is also set (and possibly when the limits are switched off too).

### LOW 6: The stderr temp file can grow without bound
**Where:** lib/Service/CommandRunner.php:250 and :264-266

A hostile PDF or TIFF can make poppler or libtiff print warnings non-stop until the timeout, filling Nextcloud's temp dir. Only the first and last few KB are ever read.

**Fix:** Cover it with `prlimit --fsize`, or read stderr through a pipe with `stream_select` and drop the middle.

### INFORMATIONAL
- **Cached paths are run unchecked.** DetectionCache.php and BinaryLocator.php:166-179 run cached finder paths without checking them again (absolute, is_file, is_executable). Anyone able to write the distributed cache (e.g. an unauthenticated Redis) could plant a command. The distributed cache also shares paths and loader lists between servers that may differ. Fix: check the cached value again before use; consider a host-local cache for paths.
- **ScratchDirectory checks fail open.**
  - At ScratchDirectory.php:72, `override()` doesn't check again that the path is absolute. A `config/*.config.php` bypasses `set()`, but that file is trusted.
  - The tmpfs check fails open when `/proc/mounts` can't be read (:88), or for bind mounts, overlayfs or zram. The free-space check is skipped when `disk_free_space()` returns false (:97).
  - Fix: treat false or unknown as a problem.
- **Log contents.** LargeFileProvider.php:229-237 logs the file name and the first stderr line at warning level. That line can carry the full data path, including the username, and text from the hostile file (e.g. poppler operator names), possibly invalid UTF-8 that breaks the JSON log entry. It is visible to admins only. Fix: strip control characters and invalid UTF-8.
- **No limits on version and listing runs.** `runBare()` (version checks and `vips -l`) runs with no timeout or cap. It never touches user files and is admin- or cache-driven, so this is acceptable. A hanging binary would hang the settings page.
- **Forged page size has no impact.** A PDF Title containing a newline can forge pdfinfo's "Page 1 size" line (PageProbe.php:105). It can only shrink the user's own preview.

### Checked and handled
- **Argument injection:** none found.
  - proc_open is called with an argv array and no shell.
  - Every user path is checked to start with `/` (VipsRenderer.php:187, PopplerRenderer.php:101), so a leading `-` can't become an option for pdftoppm, pdfinfo, pdfimages, vips* or tiffcp. timeout uses `+` getopt, and prlimit and choom get `--`.
  - libvips `[...]`: a trailing `]` is refused. `%` in the vipsthumbnail output is refused, and the output path is never user-controlled.
  - The pdftoppm stem is fixed. tiffcp's `path,dir` syntax is handled by the comma-free symlink plus the comma check on the scratch path.
- **Environment:** programs get only LC_ALL, MALLOC_ARENA_MAX, TMPDIR and VIPS_BLOCK_UNTRUSTED. Nothing like LD_PRELOAD or MAGICK_* is inherited.
- **Limits:**
  - `run()` applies timeout, prlimit and choom to every user-file run: vipsheader, vipsthumbnail, vips copy, tiffcp, pdfinfo, pdfimages and pdftoppm. The libvips runs also get the decoder block.
  - A limit that is switched on but whose program is missing fails closed.
  - Integers are clamped on read and words are validated on read.
- **App config:** it holds no paths or commands. Program overrides live in system config and are validated when read, not only when set. Detection results go to the memory cache, not app config.
- **Scratch and temp files:**
  - The restrip directory has a random 128-bit name, mode 0700, and must not exist yet.
  - Cleanup unlinks the symlink itself, never its target. The render folder's glob-and-unlink doesn't follow links.
  - Temp files and folders come from ITempManager and are unique per run.
- **Settings controller:**
  - It is admin-only (no NoAdminRequired, no AuthorizedAdminSetting).
  - It is a plain Controller, so the CSRF check is enforced. The frontend sends `requesttoken` and `OCS-APIRequest`.
  - Input is validated through ConfigurationService and TypeRule. Paths, versions and the last failure go to admins only, and the setup check is admin-only.
  - `last_failure` stores the reason only, with no file name or stderr.
- **Frontend:**
  - There is no v-html; everything is mustache interpolation. @nextcloud/dialogs toasts escape their text.
  - The occ command quoting (`'…'\''…'`) is correct.
  - The clipboard only writes commands built from validated values or from fixed Platform templates.

Main files:
- /home/mdr/projects/nc_large_file_previews/lib/Renderer/VipsRenderer.php
- /home/mdr/projects/nc_large_file_previews/lib/Service/ScratchDirectory.php
- /home/mdr/projects/nc_large_file_previews/lib/Service/CommandRunner.php
- /home/mdr/projects/nc_large_file_previews/lib/Renderer/VipsLoaders.php
- /home/mdr/projects/nc_large_file_previews/lib/Preview/LargeFileProvider.php
- /home/mdr/projects/nc_large_file_previews/lib/Config/ConfigLexicon.php
```
