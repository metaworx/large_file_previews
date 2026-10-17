# AP PreviewStream v1.0: The render stored as it is

## TL;DR

The provider hands Nextcloud the rendered file as a stream (`OCP\IStreamImage`),
so that the largest preview is stored as rendered,
at a quality of the app's own, with no decoding in PHP;
a switch goes back to today's `OCP\Image`.
Beside it, the page names Nextcloud's own settings plainly,
and the export says which programs wrote what.

- largest preview: `OCP\Image`, re-encoded at Nextcloud's quality →
  the render's bytes, at `preview_quality` (95); smaller sizes as before
- `preview_stream` (on): off, today's path; a render that does not fit Nextcloud's box
  also goes that way, for GD to scale it
- quality: fixed 95 → `preview_quality`, 10 to 100, both renderers, JPEG and WebP
- page: "Nextcloud's own" → "Nextcloud's own settings", an amber note;
  the format's values; a WebP hint under *Format*
- export: `exported.programs`, each path, version and source, never imported

### Blocks

1. Page: Nextcloud's own settings, the note, the format's values.
2. Export: the programs under `exported`.
3. `IStreamImage`: the stream from the provider, behind `preview_stream`.
4. `preview_quality`, and the switch, on the page and by `occ`; texts and documents.

## Discussion

The user asked for Nextcloud's preview quality to be settable (AP PreviewQuality),
then what `OCP\IStreamImage` is, and settled, over several messages:

- the heading "Nextcloud's own settings", with an amber line repeating its help's
  "Nextcloud's own preview settings, which apply to every preview, not only this app's.";
- `preview_format` stays read only, its possible values shown as text;
- program versions in the export, for diagnosis, ignored on import;
- `IStreamImage` implemented, with the app's quality settable,
  and a switch between the stream and `OCP\Image`, on by default.

Asked whether a WebP largest preview with JPEG smaller sizes is possible:
it is not. Nextcloud makes every smaller size from the largest in its type,
and finds a cached size only of that type.
A client that cannot show WebP (the user's: Augias Museum) keeps the app's
*Format* at `jpeg`, or at `nextcloud` with Nextcloud's at `jpeg`.

The open points took the recommendations: 95 by default, so that nothing
changes until set; a WebP hint under *Format*; the switch on.

## Analysis

- **What Nextcloud does with a stream** (33 to 35): `Generator::savePreview()` writes
  `IStreamImage::resource()` to preview storage as it is, and the entry takes
  `width()`, `height()` and `dataMimeType()`.
  Smaller sizes are made from that stored file by `OC\Image`,
  in its type, at Nextcloud's `jpeg_quality` or `webp_quality`.
  `OC\StreamImage` is private; Imaginary uses it since Nextcloud 24.
- **A class of the app's own**, `Preview\RenderedPreview`, implements `OCP\IStreamImage`:
  the open stream, the MIME type, width and height (`getimagesize()`);
  `data()` reads the stream for whoever asks, the rest of `IImage` refuses.
- **The temporary folder** is removed in the provider's `finally`, as now:
  the file is opened first, and the open handle keeps it readable until closed.
- **The box.** Renderers draw into a square, the larger of Nextcloud's
  `preview_max_x` and `preview_max_y`; `OCP\Image::scaleDownToFit()` fits that into
  the box. Where the render already fits - always, with the square defaults -
  it is streamed; where it does not, the provider takes today's path.
- **`preview_quality`**, a scalar of the lexicon, 10 to 100, default 95, replaces
  `PreviewFormat::QUALITY` in `pdftoppm -jpegopt quality=` and libvips' `[Q=]`;
  PNG has none. Streamed, it is the largest preview's quality; not streamed,
  only the intermediate image's, which the field's help says.
  It joins `fingerprint()` through the lexicon, so a change tries failed files again.
- **`preview_stream`**, a switch, on.
  Off is the way back where something reads the provider's image with `data()`
  rather than through the generator.
- **Format values.** `preview_format` of Nextcloud's is read by Imaginary only:
  `webp` gives WebP, anything else JPEG; `config.sample.php` does not document it.
- **Programs in the export.** `exported.programs.<name>`:
  `path`, `version`, `found_by` (`Nextcloud` or `system config`), from
  `PrerequisitesService::programList()`; a reader of `exported` ignores it already.

## Implementation Plan

### Block 1: Nextcloud's own settings

1. `PreviewsSection.vue`: the heading, the amber `NcNoteCard`,
   the format line with its values.
2. Vitest; translations.

**Verification:** lint, typecheck, stylelint, Vitest, build, `l10n.sh check`.

### Block 2: The programs in the export

1. `ConfigTransfer::export()`: `exported.programs`.
2. PHPUnit: listed; ignored on import.
3. `README.md`, *Backup and restore*.

**Verification:** PHPUnit, ECS, Psalm.

### Block 3: The stream

1. `Preview\RenderedPreview` implementing `OCP\IStreamImage`.
2. `LargeFileProvider`: the stream where `preview_stream` is on
   and the render fits the box, `OCP\Image` otherwise.
3. `ConfigLexicon`: `preview_stream`.
4. PHPUnit: each path, the fallback where the render is too large;
   integration: the stored largest preview is the render, byte for byte, streamed,
   and Nextcloud's re-encoding where not.

**Verification:** PHPUnit both suites, ECS, Psalm.

### Block 4: The quality, and the switch, set

1. `ConfigLexicon`: `preview_quality`; both renderers take it.
2. `PreviewsSection.vue`: the quality field, the switch, the WebP hint under *Format*.
3. PHPUnit and Vitest; translations; `README.md`, *Configuration*; `CHANGELOG.md`.

**Verification:** as Blocks 1 and 3; `settings.cy.js` on the harness;
a PDF's largest preview on the harness, its JPEG quality read back.

## Proposed commit message

- `[TASK] Settings: Nextcloud's own settings, said so in amber, the format's values named`
- `[TASK] Backup and restore: the programs that wrote the file, for diagnosis`
- `[TASK] The largest preview stored as rendered, handed to Nextcloud as a stream`
- `[TASK] The app's own preview quality, and the stream switched, on the page and by occ`

## Change History

- v1.0: the plan, from the user's answers on IStreamImage and Nextcloud's settings.
