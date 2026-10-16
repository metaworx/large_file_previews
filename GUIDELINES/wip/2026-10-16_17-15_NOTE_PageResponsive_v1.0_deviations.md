# NOTE PageResponsive v1.0: deviations

Where the run went differently from the plan.

## Block 2: the fields

- **16rem had never applied.**
  Nextcloud's own width for every input field outweighed `NumberField`'s,
  so each field was as wide as its label.
  The rule now has two classes and wins: every field is 16rem,
  shrinking to 6rem where the line is shorter.
- **Shrinking needed more than the plan said:**
  - `NumberField`'s root is an inline block rather than a column flexbox,
    which Chrome sized to its row's narrowest, truncating every label at any width;
  - a wrapping row was tried and dropped: Chrome sized it by its widest item,
    so the Save button wrapped beneath the field at any width;
  - `.lfp-fields > *` is no wider than the line,
    where each setting's field and command had been wider than its column.
- **The add-extension field** is 12rem as a flex basis,
  which Nextcloud's width does not override.

## Block 3: the test

At 400 px with both lists of rules on the page,
it checks cells, buttons, fields and commands against the page's edge,
and that the page does not scroll sideways.
The test itself never ran against the old layout;
the same measurement, taken by hand on the harness between Block 1 and Block 2,
found the page 392 px wide in 385 at 400 px, and 401 in 305 at 320 px.

## Block 4: the screenshots

The MIME types shot ends at JPEG, not HEIC:
each type is taller in four rows, and the list would not fit the viewport.
Every shot was retaken, as every one had changed.
