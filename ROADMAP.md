# Roadmap

What is planned beyond the work in progress, in no fixed order.
An item moves into an Action Plan when its turn comes,
and leaves this list when it ships.

## Types beyond the built-in list

Further types libvips reads, such as OpenEXR or a camera's raw format,
as a map from MIME type to renderer that an administrator sets,
rather than built into the app.
Nextcloud tries a provider for the MIME types its registration's regex matches,
and that regex is fixed when the app registers,
before services may be asked for anything, app config included
(`IBootstrap::boot()` is where they may):
such a map needs a way to register that keeps to that.
Most such decoders are untrusted in libvips besides,
and stay blocked unless switched on.
