# Roadmap

What is planned beyond the work in progress, in no fixed order.
An item moves into an Action Plan when its turn comes,
and leaves this list when it ships.

## Large images

Previews of large images through libvips (`vipsthumbnail`),
which shrinks on load and reads a striped or tiled TIFF a part at a time.
The image types are already known to the app and switched off;
this switches them on.

HEIC and HEIF included, where the installed libvips reads them:
it does so through libheif, which not every distribution's build links,
so the app checks for it (`vips -l | grep heifload`) and declines the type otherwise.

## JPEG previews

Return the preview as JPEG rather than PNG, as a setting: smaller previews,
for pages and images without transparency to keep.
JPEG has no alpha channel,
so a second setting chooses the background a transparent area is flattened onto,
white or black, white by default.
