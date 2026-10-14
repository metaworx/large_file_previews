> **Fragment** — inlined by `tools/sync-docs.sh`; not a standalone document.

# {{project_name}} (v1.0.1)

Large File Previews is a Nextcloud app for instances that hold files too large for
Nextcloud's own preview providers - multi-gigabyte PDFs and very large images. It
renders the first page or a reduced image straight from the file where it lies with a
command-line renderer (`pdftoppm` for PDFs, libvips for images), so no file is loaded into
memory or sent over a socket, and every renderer runs under a time limit, an address-space
and file-size cap and a raised out-of-memory score. It is for administrators who run
Nextcloud on local storage and want sharp previews of archives such as scanned catalogues.

## Working on it

```bash
git clone --recurse-submodules git@gitlab.com:metaworx/open-source/nextcloud/large_file_previews.git
cd large_file_previews
composer install
```

The app is tested inside a `nextcloud_testing` instance, which mounts this checkout as
`apps/large_file_previews`; put that instance's directory in
`GUIDELINES/config.local.ini` under `[phpunit]` (see the contract below). The instance
needs `poppler-utils`, `libvips-tools` and `libtiff-tools` installed.

## The rules that apply here

The conventions below are not AI configuration:
they are how this project is written,
and an agent is simply another party required to follow them.

- The contract that follows is generated into `/AGENTS.md` as well,
  so people and agents are held to the same text.
- The shared conventions live in `{{shared_root}}` — commit policy,
  quality pass, per-language testing, linting and code style.
- `{{guidelines_root}}/README.md` explains the gate protocol:
  what an agent asks before it changes anything,
  and what `EXEC` and its relatives authorise.

Both generated documents come from the fragments in `{{guidelines_root}}`.
Editing a generated file is wasted work — change the fragment and re-run
`{{shared_root}}/tools/sync-docs.sh`.
