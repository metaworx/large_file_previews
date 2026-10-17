# NOTE ConfigTransfer v1.1: deviations

Where the run of Blocks 1 to 5 went differently from the plan.

- **Translations went with each block**, not with Block 5:
  `scripts/l10n.sh check` runs in CI on every commit,
  and would have failed on Blocks 1 and 4 without them.
- **An extension of a type the app does not handle** is left out and named,
  as a MIME type it does not handle is,
  rather than refused: `apply()` would refuse it,
  and an export can hold one that a hand-written configuration stored.
- **`ConfigurationService::apply()`** gained a dry run (`write: false`),
  `normaliseScalar()` and `untrustedStored()`;
  `BinaryLocator` and `ScratchDirectory` their path checks as public `pathProblem()`:
  an import checks everything before anything is written.
- **The full Cypress suite** was started after Block 5's own spec passed,
  and its result is reported at the completion gate rather than before the commit.
