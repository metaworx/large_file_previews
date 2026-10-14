# NOTE ReviewFixes v1.1: descriptors measured

Block 1, on jackal, 2026-10-14:
a PHP script as `www-data`, in a folder of its own under `/tmp`,
removed afterwards; no user data touched.
Each program ran as the app runs it:
`proc_open()` with an argument array and extra descriptors,
under `timeout --kill-after=5s 60s`, `prlimit --as` and `choom -n 1000`,
with `LC_ALL=C`, `MALLOC_ARENA_MAX=2`, `VIPS_BLOCK_UNTRUSTED=1`.
poppler 24.02, libvips 8.15.1, libtiff 4.5.1.

## Every program reads `/dev/fd/4`

The inputs had names a user could give:
a newline, `out of memory`, a forged `vipsheader` line, a trailing `]`, commas.
PHP opened each and passed it as descriptor 4;
no name reached a program.

| Run | Status | Said |
|---|---|---|
| `pdfinfo -f 1 -l 1 /dev/fd/4` | 0 | the page as usual |
| `pdfimages -list -f 1 -l 1 /dev/fd/4` | 0 | the table as usual |
| `pdftoppm … /dev/fd/4 <stem>` | 0 | the page written |
| `vipsheader /dev/fd/4` | 0 | `/dev/fd/4: 300x200 uchar, 3 bands, srgb, pngload` |
| `vipsthumbnail /dev/fd/4 … -o <out>.jpg[…]` | 0 | the JPEG written |
| `vipsheader /dev/fd/4`, the one-strip TIFF | 0 | `/dev/fd/4: 6000x6000 float, 1 band, b-w, tiffload` |
| `vipsthumbnail`, the one-strip TIFF, 256 MB | 255 | `TIFFFillStrip: No space for data buffer` |

The output names `/dev/fd/4`, never the file:
nothing a user names can be read as a header line or as "out of memory",
and `]` and `,` in a name no longer matter.

## The copy through an unlinked file

- PHP opened `copy-<random>.tif` in the folder with `x+` and unlinked it at once;
  the name was gone, the handle open.
- `tiffcp -c none -r 64 /dev/fd/4,0 /dev/fd/3`, the input as 4 and the copy as 3,
  under 256 MB: status 0 in 0.85 s, 144 000 962 bytes written through the handle.
- `vipsheader /dev/fd/3` read it; `vipsthumbnail /dev/fd/3` under 256 MB
  rendered 1024 x 1024 in 0.36 s.
- After `fclose()`, the folder held no copy and nothing named for one:
  the space went with the last descriptor.

## `--fsize`

- `tiffcp` under `prlimit --fsize=64 MiB`: ended by signal 25, SIGXFSZ,
  in 0.30 s, with the copy at exactly 67 108 864 bytes; it says nothing first.
- `pdftoppm` at 4096 pixels and `vipsthumbnail` at 1024 under a 1 MiB `--fsize`:
  status 0; their outputs and stderr stayed below it.
- So the judge names signal 25 as the file-size cap (`prlimit_fsize_mb`, or for
  `tiffcp` the copy's limit), as signal 6 and 11 point to the address-space cap.

## For block 2

- Every program can be given its input as a descriptor;
  no fixed-name link is needed for any.
- `tiffcp` reads `<descriptor>,0` as it reads `<path>,0`.
- The renderers' refusals of a trailing `]` and of a comma in the scratch path,
  and the symlink, can go.
- The refusal of `%` stays, for the output: `vipsthumbnail`'s `-o` is a format.
