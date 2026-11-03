# AP NextcloudVue v1.0: The shared frontend as one package

## TL;DR

Large File Previews and File Checksum Search carry the same Vue pieces,
copied with the class prefix changed.
They become `@metaworx/nextcloud-vue`, a repository of its own beside the apps,
published to GitLab's package registry, and this app takes it first.

- in: `HelpPopover` (this app's, with the slot), `OccCommand`, `NumberField`,
  the four toasts with their placement CSS, the l10n helpers
  (`t`, `n`, `NBSP`, `formatDateTime`, `formatDuration`, `keepNumbersWithWords`),
  `useClipboard`, the OCS call, and under `./testing` `ncActions`, `ncSelect`,
  `settle` and the runner setup as a function
- texts stay in the apps: the package names nine, with English defaults;
  each app's `src/texts.ts` translates them and hands them over once,
  so `l10n.py` finds every text in `src/`
- class prefix `lfp-`/`fcias-` → `mwx-`
- registry: GitLab's, under the real `@metaworx`; one `.npmrc` line per app,
  no token to install, `CI_JOB_TOKEN` to publish
- out: FCS's `MdiIcon`, `icons`, `escapeHtml`; this app's `InstallHint`,
  `scalarField`; the update section (its own plan later);
  FCS's adoption, its session's

### Blocks

1. The repository: created with `glab`, guidelines installed, contract, tooling, `.gitlab-ci.yml`; pipeline green
2. The package: sources moved with their specs, texts, prefix, OCS call, `./testing`; `[Unreleased]`; green
3. Published: v0.1.0 cut and tagged, the publish job; `npm view` answers from a clean directory
4. This app takes it: `.npmrc`, `texts.ts`, the imports, copies deleted, one CSS selector; suites, lint, build, `l10n.sh check`, CI green

## Discussion

### The registry (D1)

`@metaworx` on npmjs belongs to a stranger's user account
(`@metaworx/passport-validation`, six versions, last published in 2021),
and npm cannot rename an account or move a scoped package.
An issue asking whether the scope is still in use is open on that package's GitHub repository;
an answer may take weeks, and may be no.

GitLab's registry needs no name anyone else can hold:
the instance-level endpoint serves a package whose scope is the project's top-level group,
and `metaworx/open-source/nextcloud/nextcloud-vue` makes that `@metaworx`.
Verified on 2026-11-03 against a public package on gitlab.com:
metadata and tarball answer anonymously on the project, group and instance endpoints,
and `npm view` resolves with one registry line and no token.
The registry is on the Free tier and outside the 10 GiB repository quota.

The user chose B (`D1-B`): GitLab's registry under `@metaworx`.
Should the issue be declined, the organisation `mwx-oss` on npmjs is held since 2026-11-03;
a move there is a rename of the package and of the import lines in each app, nothing more.
A second publish job with an `NPM_TOKEN` could publish to both; not in this plan.

### Where the texts live

`scripts/l10n.py` extracts the texts from `git ls-files`, so nothing in `node_modules` is seen,
and each app ships its own translations under its own app id.
The package therefore carries no `t('…', 'Save')` of its own.
It names its texts in one interface, `Texts`, with English defaults,
and reads them through one module-level store: `setTexts(partial)` once, in the page's entry,
and `texts()` wherever a text is needed, in a component or in a toast alike.
Provide/inject was considered and dropped:
a toast has no component context, and `toastSaved()`'s default text is one of the nine.

The nine: `save`, `saveLabel(label)`, `reset`, `resetLabel(label)` (NumberField),
`help(label)` (HelpPopover), `copyCommand`, `commandCopied`, `commandNotCopied` (OccCommand),
`saved` (the toast's default).
A key with a placeholder is a function of it, so each app's `t()` call is spelled out in full
for the extraction tool.

### CI for the new repository (D4)

This app runs on GitHub Actions through a push mirror because GitLab's minutes are billed.
The package's pipeline is small - a guidelines gate, lint, typecheck, test, build, and on a tag the publish -
and the publish job needs `CI_JOB_TOKEN`, which only a GitLab job has;
mwx-lib runs on GitLab for the same reason.
A GitHub pipeline would need a project deploy token stored as a GitHub secret.
Recommended: GitLab CI.

## Analysis

- **What is shared, side by side.** `HelpPopover`: identical but for this app's default slot
  and its wider body; the package takes this app's. `OccCommand`, `NumberField`: this app's only.
  Toasts: FCS's four (`toastSaved` with a default text, `toastSuccess`, `toastError`, `toastUndo`)
  over this app's two; the placement CSS is this app's `toast.css`.
  `l10n.ts`: FCS's superset of this app's `t()`. `useClipboard`, the three test helpers and the
  runner setup with its `sessionStorage` clearing: FCS's. The OCS call: this app's `call()` in
  `useSettings.ts`, with its URL made a parameter.
- **Texts in the pieces today**: eight `t('large_file_previews', …)` calls in the three components,
  and FCS's `'Saved.'` default. They become reads of `texts()`.
- **Import sites in this app**: 19 lines in 17 files import `../l10n` or `../toast`;
  three of them are the pieces that move. `NumberField` imports `./HelpPopover.vue`.
  `TypesSection.spec.ts` reads `.lfp-number__label`, `.lfp-number`, `.lfp-number__reset`, `.lfp-occ`;
  `settings-admin.css` line 170 styles `.lfp-number__field` inside a rule row.
- **Build.** `createLibConfig` from `@nextcloud/vite-config` 2.5, already a dev dependency of both apps:
  ESM, `.d.ts` by its DTS plugin, dependencies and peer dependencies external,
  CSS extracted to one `style.css`. Entries `index` and `testing`.
  The library's JS keeps its side-effect import of `@nextcloud/dialogs/style.css`,
  so a consuming app's Vitest inlines the package as it inlines `@nextcloud/dialogs`.
- **Peer dependencies**: `vue`, `@nextcloud/vue`, `@nextcloud/dialogs`, `@nextcloud/l10n`,
  `@nextcloud/router`, `@nextcloud/auth`; for `./testing`, `vitest` and `@vue/test-utils`, optional.
  Dev dependencies as the apps': `@nextcloud/eslint-config`, `@nextcloud/stylelint-config`,
  `@vitejs/plugin-vue`, `@vue/tsconfig`, `happy-dom`, `typescript`, `vite`, `vitest`, `vue-tsc`.
  Node `^24`, npm `^11`.
- **The apps' `.npmrc`**: `@metaworx:registry=https://gitlab.com/api/v4/packages/npm/`,
  committed; `npm ci` in GitHub Actions and in a stranger's clone reads it, with no token.
  `package-lock.json` records gitlab.com tarball URLs.
- **Publishing**: a job on a `v*` tag writes a two-line `.npmrc` from `CI_PROJECT_ID` and `CI_JOB_TOKEN`,
  builds, and runs `npm publish`. The version is `package.json`'s, which `changelog.sh cut` sets
  as the changelog's `manifest`, and the tag is the `[RELEASE]` commit's.
- **The guidelines there**: `install.sh --ssh`, pinned to the newest release; `config.ini` with
  `languages = ts, shell`, `shipped = src/`, `package.json` and `README.md`, the changelog's manifest
  `package.json`; the contract names the commands. The plan stays here (D3-A), so a commit there
  names it in words, by this repository and the file's path, never as `AP …, Block n`,
  which `commit-msg.sh` there would refuse.
- **Guards kept in this app**: `.eslintrc.cjs`'s bare-string rule and its toast rule stay;
  the toast rule's message still names `t('large_file_previews', …)`, which is right.
  The spec rule against stubbing `Nc*` components stays; the helpers it points at move to the package,
  and its message names `@metaworx/nextcloud-vue/testing`.
- **FH** has no frontend yet; it takes the package when its settings page comes.
- **FCS** adopts in its own session: `toastSaved()`'s default becomes `texts().saved`,
  its `HelpPopover` is the package's (the slot unused), `fcias-` selectors in its specs become `mwx-`.

## Implementation Plan

### Block 1: The repository

1. `glab repo create metaworx/open-source/nextcloud/nextcloud-vue --public --defaultBranch master
   --description "Vue components and helpers shared by metaworx' Nextcloud apps"`,
   cloned to `~/projects/nc_nextcloud_vue` over SSH.
2. `GUIDELINES/shared/tools/install.sh --ssh` from this checkout's submodule; `config.ini`;
   `project/_CONTRACT.md`, `_README.md`, `CI.md`; `sync-docs.sh`.
3. `package.json` (`@metaworx/nextcloud-vue` 0.0.0, `type: module`, `exports` for `.`, `./testing`, `./style.css`,
   `files: dist`, scripts `build`, `lint`, `stylelint`, `typecheck`, `test`), `tsconfig.json`,
   `vite.config.ts` with `createLibConfig`, `vitest.config.ts`, `.eslintrc.cjs`, `.stylelintrc`,
   `.gitignore`, `LICENSE` (AGPL-3.0-or-later, as the apps), `CHANGELOG.md`, `README.md`.
4. `.gitlab-ci.yml`: stage `check` - `guidelines` as mwx-lib's, `frontend` on `node:24`
   (`npm ci`, lint, stylelint, typecheck, test, build), on branches and tags;
   stage `publish` - `publish:npm` on a `v*` tag.
5. Committed and pushed; the pipeline's `check` stage green on the empty package.

**Verification:** `tools/check` there all well; pipeline green; `GUIDELINES/vendor.sources` none.

### Block 2: The package

1. `src/texts.ts`: `Texts`, `defaultTexts`, `setTexts()`, `texts()`.
2. `src/l10n.ts` (FCS's), `src/toast.ts` and `toast.css` (FCS's four, this app's CSS),
   `src/useClipboard.ts`, `src/ocs.ts` (`ocsCall(method, url, body)`), `src/index.ts`.
3. `src/components/HelpPopover.vue`, `OccCommand.vue`, `NumberField.vue`, prefix `mwx-`,
   texts from `texts()`, with `HelpPopover.spec.ts` and `NumberField.spec.ts` moved and reprefixed;
   a spec for `texts.ts` and one for `toast.ts`'s default.
4. `src/testing/`: `ncActions.ts`, `ncSelect.ts`, `settle.ts`, `setup.ts` with `setupNextcloudDom()`
   (the quiet `ResizeObserver`, the teleport stub, auto-unmount, `sessionStorage` cleared), `index.ts`.
5. `README.md`: the `.npmrc` line, `setTexts()`, `style.css`, the testing entry;
   `CHANGELOG.md` `[Unreleased]`.

**Verification:** `npm run lint`, `stylelint`, `typecheck`, `test`, `build`;
`dist/` holds `index.mjs`, `testing.mjs`, `style.css`, the `.d.ts`; `tools/check` all well; pipeline green.

### Block 3: Published

1. `changelog.sh cut CHANGELOG.md 0.1.0`: `[RELEASE] v0.1.0`, the annotated tag; pushed with the tag.
2. The `publish:npm` job runs on the tag.
3. From an empty directory with the one-line `.npmrc`: `npm view @metaworx/nextcloud-vue version` answers `0.1.0`
   without a token; `npm pack` fetches the tarball.

**Verification:** the pipeline's `publish` stage green; the package listed under the project's Package Registry.

### Block 4: This app takes it

1. `.npmrc`; `npm install @metaworx/nextcloud-vue@^0.1.0`; `package-lock.json`.
2. `src/texts.ts`: the nine texts as `t('large_file_previews', …)`; `main.ts` calls `setTexts()`
   and imports `@metaworx/nextcloud-vue/style.css`.
3. The 16 remaining import lines: `../l10n`, `../toast` → `@metaworx/nextcloud-vue`;
   `./HelpPopover.vue`, `./OccCommand.vue`, `./NumberField.vue` → the package.
   `useSettings.ts`'s `call()` → `ocsCall()`.
4. Deleted: `src/l10n.ts`, `src/toast.ts`, `src/toast.css`, the three components and their two specs.
5. `vitest.setup.ts` → `setupNextcloudDom()`; `vitest.config.ts` inlines the package;
   `.eslintrc.cjs`'s spec-rule message.
6. `settings-admin.css` line 170 and `TypesSection.spec.ts`: `lfp-number`, `lfp-occ` → `mwx-`.
7. `scripts/l10n.sh update` then `build`: the texts moved, none changed; `CHANGELOG.md` bullet.

**Verification:** `npm test`, `npm run lint`, `typecheck`, `stylelint`, `build`; `scripts/l10n.sh check`;
`tools/check`; pushed, GitHub Actions green including Cypress.

## Proposed commit messages

This repository:

- `[UPDATE] APs: +NextcloudVue v1.0`
- `[TASK] Settings page: the shared components and helpers come from @metaworx/nextcloud-vue`
  (`AP NextcloudVue v1.0, Block 4`)
- `[UPDATE] APs: -NextcloudVue`, offered at the completion gate

`nextcloud-vue`, naming this plan in words:

- `[TASK] The repository: guidelines, tooling and CI`
- `[TASK] The package: the two apps' shared Vue pieces`
- `[RELEASE] v0.1.0`

## Change History

- v1.0: the plan, from the user's `PLAN+` with D1-B (GitLab's registry), D2-A (the repository under
  `metaworx/open-source/nextcloud/`), D3-A (the plan here). D4, CI on GitLab, and D5, the texts store,
  are proposed in it.
