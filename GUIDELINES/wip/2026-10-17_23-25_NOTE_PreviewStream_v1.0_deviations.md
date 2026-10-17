# NOTE PreviewStream v1.0: deviations

Where the run of Blocks 1 to 4 went differently from the plan.

- **A JPEG's Huffman tables are optimized** (`-jpegopt optimize=y`,
  libvips' `optimize_coding`), which the plan did not have.
  Measured on the harness, the tests' drawn page at 4096 x 2048:
  pdftoppm's JPEG was 132 KB at 95 and at 60 alike, against Nextcloud's 62 KB at 80;
  optimized, 50 KB. libvips' went from 232 KB to 100 KB.
  Without it, the stream would have made the largest preview larger than before,
  where the plan's 95 meant that nothing changes until set.
- **The harness check** read the largest preview's JPEG quality back with `identify`:
  95 by default and 60 when set, streamed; Nextcloud's 80 with the stream off.
- **An image test that read the provider's GD image**
  (`ImageRenderTest`, a JPEG's background) reads the stream's bytes decoded,
  in Block 3.
- **The CHANGELOG bullet of Block 1** still named the heading "Nextcloud's own";
  Block 4 corrected it to "Nextcloud's own settings".
