# NOTE LargeFilePreviews v1.0: binary paths and prerequisites

Two additions the user asked for on 2026-10-13, and one rule for the whole plan.
The blocks stand; these change what blocks 4, 7 and 8 deliver.

## 1. Binary paths can be overridden

**Conflict:** contract §3.3 says a renderer's path is never read from app config. The
reason holds: an administrator can set app config over the provisioning API without
shell access, so a path set there would be code execution as the web server's user.

**Resolution:** the override lives in **system config** (`config.php`), not app config.
Only someone who can already write files or run occ on the server can set it.

- Key `large_file_previews.binaries`: a map from program to absolute path, e.g.
  `'large_file_previews.binaries' => ['pdftoppm' => '/opt/poppler/bin/pdftoppm']`.
  Set by hand, or with `occ config:system:set`.
- The programs are `pdftoppm`, `timeout`, `prlimit`, `choom`, and `vipsthumbnail` later.
- The path is resolved in this order: the override, then `IBinaryFinder`.
  - An override must be absolute, exist, and be executable. Otherwise the program counts
    as **missing**: it fails closed and is not replaced by the finder's path, since the
    administrator asked for that particular binary.
  - A key for an unknown program is ignored with a warning.
- **Not settable from the settings page or `config:set`.** The page and `config:show` show
  each path and its source (`override` / `finder`) read-only, with the `occ config:system:set`
  line to change it.
- **Block 4** adds the resolution, as `BinaryLocator`, with unit tests.
- **Contract §3.3** is amended in the same block:
  > Never from app config; an override only from system config.

## 2. Prerequisites command

`large-file-previews:check` (alias `lfp:check`), with `--output=plain|json|json_pretty`.
It lists each item, its status (`ok` / `missing` / `disabled` / `optional`) and what it is needed for:

- **Permissions:**
  - `proc_open`, `proc_get_status`, `proc_terminate` and `proc_close` are not in `disable_functions`;
  - the temp directory is writable.
- **PHP extensions:**
  - required: `gd`, which `OCP\Image` loads the PNG with;
  - optional: `sysvsem`, without which `preview_concurrency_new` does not apply. This is
    reported, not required.
- **Binaries:** each program's resolved path, source and version
  (`pdftoppm -v`, `timeout --version`, and so on).
  - A limit that is switched off makes its tool `optional`.
  - A limit that is switched on but whose tool is missing is an error.
- **Exit status:** 0 when every required item is ok, 1 otherwise, so it can run in a script.

One `PrerequisitesService` answers for the command, the setup check, the tools part of
`config:show` and the settings page's status section, so the four cannot disagree.

- **Block 7** delivers it with the setup check.
- **Block 4**'s `config:show` gets its tools part only once block 7 is done.
  Until then it shows the stored and resolved parts.

## 3. FCIAS is never named

FCIAS is the pattern to copy from. It is never named in code, comments, shipped
documentation, `CHANGELOG.md`, commit messages or tag messages. Naming it in the plan
and its NOTEs is fine.
