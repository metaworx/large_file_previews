# AP Sandbox v1.0: Each render in bubblewrap

## TL;DR

An off-by-default switch, `bwrap_enabled`, adds bubblewrap as the innermost wrapper:
each render runs in fresh namespaces that see the system's programs and its own folder,
not the data directory, `config.php` or the network;
the file still arrives as an open, seekable descriptor, with no copy.

- chain: `timeout → prlimit → choom → program` → `… → choom → bwrap → program`
- inside: `/usr`, `/etc` read-only; `/proc`, a minimal `/dev`, an empty `/tmp`;
  the render's folder writable; the fontconfig cache read-only where there is one
- on but unusable (missing, or user namespaces blocked, as in Docker or AIO):
  the render fails closed, as `prlimit` and `choom` do
- status: a bwrap row, its version and a probe that it can start a sandbox, cached;
  the setup check warns where it is on and cannot

### Blocks

1. Runner: `bwrap_enabled`, the sandbox's arguments, the render's folder in `RunOptions`.
2. Status: the bwrap row, its probe, its package.
3. Page and documents: the switch in *Limits*, translations, README, CHANGELOG.
4. Proof: sandboxed renders and what they cannot reach, on the host and in CI.

## Discussion

The user asked whether the renderers could be encapsulated, Docker and blast radius
in mind, the file passed through a socket, hardlinks or a seekable stream.
Evaluated: Docker per render needs its socket, which is root on the host, and passes
no descriptor; hardlinks into staged folders cost mount discovery, leftover links and
a shared view across renders; a sidecar receiving the descriptor over a socket works
but is a larger project, for containerised setups. bubblewrap was recommended for a
host: no daemon, the descriptor inherited as it is now.
The user chose it (`PLAN D2-A`, then `PLAN++`), with:

- D1-A: an allowlist of what the sandbox shows, not the whole tree with parts hidden;
- D2-A: all of `/etc` read-only;
- D3-A: tested on the host and in CI; the harness, whose container blocks user
  namespaces, skips.

## Analysis

- **What a compromised renderer reaches now:** the web server user's rights -
  every user's files, `config.php` and its database password, the network.
  The limits contain a runaway, not an exploit.
- **Prototype, on the host (WSL, bubblewrap 0.9):**
  `bwrap --ro-bind /usr /usr --symlink usr/lib /lib … --ro-bind /etc /etc --proc /proc
  --dev /dev --tmpfs /tmp --bind <out> <out> --unshare-all --die-with-parent
  --new-session -- pdftoppm … /dev/fd/4 <out>/page 4<in.pdf`
  rendered the page; `/home` and `/var` do not exist inside;
  a child killed by SIGKILL makes bwrap exit 137, which `CommandRunner` reads as killed.
- **The chain:** `prlimit` and `choom` exec what follows, so bwrap's parent is
  `timeout`, and `--die-with-parent` ends the sandbox with it.
  The caps and the OOM score are inherited into the sandbox.
- **What is writable:** a render writes only into its folder from
  `ITempManager::getTemporaryFolder()`; the one-strip TIFF copy goes through
  descriptor 3, probes answer on stdout. `RunOptions` gains the folder,
  and the runner binds it; a run without one writes nothing.
  Version checks and `vips -l` (`runBare()`) stay outside the sandbox:
  they read no user's file.
- **The layout:** `/bin`, `/sbin`, `/lib`, `/lib64`, `/lib32` are symlinks into
  `/usr` on a merged-/usr system and directories on others:
  each is recreated as it is, a symlink or a read-only bind.
  A program set in system config outside `/usr` gets its directory bound read-only;
  libraries it loads from elsewhere do not, which the README says.
- **Fonts:** with `/var` hidden, fontconfig would rebuild its cache in every render;
  `/var/cache/fontconfig` is bound read-only where it exists. Measured in Block 1.
- **Unusable bwrap:** missing, or refused user namespaces (Docker's default seccomp,
  Ubuntu's AppArmor where bwrap has no profile).
  A probe, `bwrap <the same arguments> -- true`, cached by `DetectionCache`
  with the program's path and mtime, tells; a render with the switch on and the
  probe failing returns `LIMIT_MISSING` with the probe's message, before it starts.
- **Where it can be tested:** not in the harness (no bwrap, user namespaces blocked);
  on the host, and in CI where GitHub's Ubuntu runner allows bwrap its namespaces,
  which Block 4 finds out.

## Implementation Plan

### Block 1: The runner

1. `ConfigLexicon`: `bwrap_enabled`, false; `Limits` gains `bwrapEnabled`,
   carried by `withRun()`, off in `timeoutOnly()`.
2. `Service\Sandbox`: the arguments for a program and its writable folder,
   from the system's layout (each top-level directory a symlink, a directory or absent);
   the probe, cached.
3. `RunOptions`: `writable`, the render's folder; `PopplerRenderer`, `VipsRenderer`
   and the TIFF copy pass theirs. `CommandRunner::prefix()` puts bwrap innermost,
   failing closed where it is on and missing or unusable.
4. PHPUnit: the arguments for each layout, the chain's order, the closed failure.

**Verification:** unit suite, ECS, Psalm; a render sandboxed on the host through
`CommandRunner`, with and without the fontconfig cache, timed.

### Block 2: The status

1. `BinaryLocator::PROGRAMS`: `bwrap`, a limit, `--version`; `Platform`: `bubblewrap`
   for every family.
2. `PrerequisitesService`: the row needed while the switch is on;
   where found but the probe fails, missing with the probe's message.
3. PHPUnit for the row's states; `RendererCheck` warns through the existing path.

**Verification:** unit and integration suites; `occ lfp:check` on the harness
(off: optional; on: missing, user namespaces blocked).

### Block 3: The page and the documents

1. `LimitsSection.vue`: *Sandbox (bubblewrap)*, a switch with no value, its help,
   its occ command; `types.ts`, `testState.ts`.
2. Translations, `de` and `de_DE`.
3. README: *Configuration*, and *Paths, and what the app trusts*; CHANGELOG.
4. Vitest; Cypress `settings.cy.js` where the switch shows.

**Verification:** Vitest, ESLint, vue-tsc, stylelint, `scripts/l10n.sh check`,
Cypress `settings.cy.js` on the harness.

### Block 4: The proof

1. Integration test, skipped where the probe fails: a PDF and an image rendered
   sandboxed; a program run in the sandbox cannot list the data directory,
   read `config.php`, or open a socket to the outside.
2. `test.yml`: bubblewrap installed for the PHPUnit job.
3. Run on the host where possible; CI's result reported.

**Verification:** the integration test on the host, as far as a Nextcloud runs there,
and in CI; where the runner refuses the namespaces, the skip is reported.

## Proposed commit message

- `[TASK] Sandbox: each render in bubblewrap, behind bwrap_enabled`
- `[TASK] Status: bubblewrap, and whether it can start a sandbox here`
- `[TASK] Settings: the sandbox switched on the page; README and CHANGELOG`
- `[TASK] Tests: a sandboxed render, and what it cannot reach`

## Change History

- v1.0: the plan, from the user's `PLAN D2-A` and `PLAN++`, D1-A, D2-A and D3-A.
