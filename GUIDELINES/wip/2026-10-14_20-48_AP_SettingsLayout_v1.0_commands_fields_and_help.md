# AP SettingsLayout v1.0: commands, fields and help

## TL;DR

The settings page's commands reproduce what is stored and break at spaces;
its fields sit on the left with short labels,
and the long help moves into ⓘ buttons.

- MIME command: effective rule → stored fields only; nothing stored → `unset`
- Command wrap: anywhere → at spaces, copy button on the first line
- Fields: side by side → one per line on the left, about 16rem, labels in MiB
- Help: paragraphs → ⓘ popover beside each setting's name (component copied from
  another app of ours)
- Nextcloud's own preview settings: shown read only (block 4, not in this run)

### Blocks

1. Each MIME type's stored fields from the server; its command built from them,
   `unset` where none.
2. `OccCommand` breaks at spaces only, its copy button on the first line.
3. `HelpPopover` copied; fields on the left with short labels;
   help into popovers; README in step.
4. Nextcloud's `preview_format`,
   `jpeg_quality` and `webp_quality` shown read only, with their commands.

## Discussion

- **The user's questions** (2026-10-14): a line break for long commands;
  all options with placeholders; inputs on the left, wider,
  or with an info button as another app of ours has.
- **Placeholders are not used.**
  A copied `--max={MAX}` is refused whole, so every copy would need editing,
  and the rule "the command reproduces what the page shows" would be lost.
  The full syntax is in each info text and in `occ lfp:config:set --help`.
- **Nextcloud's preview settings are shown, not edited.**
  They are the server's, apply to every preview,
  and writing `config.php` from the page would give an administrator without
  shell access what the app keeps from it everywhere else.
- **Shared libraries** are a decision of their own;
  copying the help popover now does not stand in the way of a package later.
- **Run:**
  `PLAN++ 1-3` - blocks 1 to 3, each committed and pushed to GitLab;
  block 4 stays open.

## Analysis

- **The frozen minimum.**
  `SettingsController::state()` sends a MIME type's `rule` as `mimeRules()[$mime]`,
  the shipped default with the stored fields laid over it.
  `RuleRow` builds the command from it, so an image type shows `--min=50`;
  running that stores 50,
  and the minimum no longer follows `preview_max_filesize_image`.
  An extension's `rule` is what is stored, and is right as it is.
- **Wrapping.**
  `OccCommand`'s `<code>` has `overflow-wrap: anywhere`,
  which breaks inside a key;
  the flex row centres the copy button against a wrapped block.
- **Labels cut.**
  `NumberField`'s input is 12rem, 8rem narrow; "Address space, MB,
  libvips" does not fit.
  The long hints under each setting make the page tall.
- **The popover.**
  The other app's `HelpPopover.vue` (about 140 lines with its spec) runs on the
  same `@nextcloud/vue` 9.8 and Vue 3; only its CSS prefix,
  its app id in `t()` and one docblock line change.

## Implementation Plan

1. **Stored fields.**
   `ConfigurationService::storedMimeRules()`: what is stored for each type,
   valid fields only.
   `state()` adds `stored` to each MIME row; `MimeRow.stored` in `types.ts`;
   `RuleRow` builds the command from `stored` for a MIME type,
   `ruleCommand()` giving `unset` where it is empty.
   *Verification:* a unit test of the row's `stored`;
   a spec that an image type at its default shows
   `occ lfp:config:unset --mime image/tiff`,
   and one with a stored minimum shows only it.
2. **Wrapping.**
   `OccCommand`: break at spaces (`white-space: normal`,
   `overflow-wrap: break-word`), the copy button aligned to the first line.
   *Verification:* specs; the build; a look at the page in `34-lfp`.
3. **Fields and help.**
   `HelpPopover.vue` and its spec copied, prefix `lfp-`,
   app id `large_file_previews`.
   Each setting's name gets an ⓘ with what is now its hint paragraph;
   the paragraphs go, except a section's one-line introduction.
   `NumberField` 16rem, fields one per line on the left; labels short,
   sizes in MiB ("Address space, MiB", "libvips, MiB", "Largest file, MiB").
   The format choices shortened ("Nextcloud's preview_format"),
   their detail in the ⓘ.
   README's names of page items kept in step.
   *Verification:* specs, lint, typecheck, stylelint, build;
   a look at the page in `34-lfp`.
4. **Nextcloud's preview settings** (not in this run).
   `state()` adds `preview_format` from system config and `preview`
   `jpeg_quality`/`webp_quality`;
   *Previews* shows them read only with `occ config:system:set` /
   `occ config:app:set` commands.
   *Verification:* a unit test and a spec.

## Proposed commit messages

Registration: `[UPDATE] APs: +SettingsLayout v1.0`, with its record.
Then one `[FIX]` for block 1 and one `[TASK]` each for blocks 2 and 3,
each with its tests and CHANGELOG bullet.

## Change History

- v1.0 (2026-10-14): first version.
