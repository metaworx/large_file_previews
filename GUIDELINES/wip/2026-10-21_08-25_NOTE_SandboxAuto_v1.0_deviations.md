# NOTE SandboxAuto v1.0: deviations

Where the run of Blocks 1 to 4 went differently from the plan.

- **The page's three choices and their translations went with Block 1,** not Block 3:
  the setting's rename would otherwise have left the page reading `bwrap_enabled`,
  gone, at Block 1's commit. Block 3 kept the README.
- **The browser test no longer compares the page with `occ lfp:check`:**
  the two now probe as different processes and may rightly disagree.
  It holds the status to what is true wherever it runs:
  `auto` never blocks; `on` is ready or missing.
- **CI before the release:** the blocks were pushed and CI's run checked
  (378 PHPUnit tests, no skips, the sandbox's among them) before the cut,
  rather than the tag's run being the first.
