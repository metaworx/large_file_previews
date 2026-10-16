# AP PageResponsive v1.0: The page at every width

## TL;DR

Tables wrap their text. Below 40rem each table row becomes a block,
with each cell labelled by its column header.
Fields shrink to the line,
and one browser test checks that nothing overflows at phone width.

- table cells: `nowrap` → wrap; below 40rem a row becomes a block, each value labelled
  (`data-label`, a container query)
- number fields: fixed 16rem → at most 16rem, shrinking
- add-extension: field at its own width, with Add beside it
- Cypress: at 400 px, no horizontal scroll and no hidden column
- the screenshot retake, Block 2 of AP RuleLayout v1.0, taken over to run once, here

### Blocks

1. Tables: wrap, then stack below 40rem (Status, What is processed, Programs).
2. Fields and the add-extension form shrink to the line.
3. Cypress: no horizontal overflow at 400 px.
4. Screenshots retaken, taking over AP RuleLayout v1.0's Block 2.

## Analysis

Measured on the harness instance at 1150, 600 and 400 px wide:

- **Nextcloud sets `white-space: nowrap` on tables**, and every cell inherits it.
  The status table is 1219 px wide at any width:
  its *Detail* column is off the page even at 1150 px.
  *What is processed* (seven columns, 674 px) and *Programs* (635 px)
  overflow at 600 px.
  These three tables are all that makes the page scroll sideways.
- **`NumberField`'s field is 16rem**, fixed: at 400 px its Save button
  reaches the edge.
- **The add-extension field spans the line**, Add wrapping beneath it:
  its width, set on the component's root, does not reach the input's box.
- The rule lists (AP RuleLayout v1.0), switches, radio buttons, notes and
  `occ` commands already fold.
- Out of scope: Nextcloud's own navigation toggle,
  which covers the section headings at a narrow width.

Sideways scrolling inside a table was considered and rejected:
it is what hides the *Detail* column now.
A row becoming a block keeps the table in the markup,
so its semantics stay for assistive technology;
the label of each cell comes from its column header,
as a `data-label` attribute that CSS shows before the value.

AP RuleLayout v1.0's Block 2 is taken over whole:
`tests/e2e/screenshots.cy.js` follows the rule lists
(`tr[data-mime]` → `li[data-mime]`),
and every image in `docs/Screenshots/` that the two plans changed is retaken.

## Implementation Plan

### Block 1: Tables

1. `settings-admin.css`: `.lfp-table` cells wrap;
   each table is put in a container (`container-type: inline-size`),
   and below 40rem its header row is hidden,
   each row a block, each cell a line with its label before it
   (`attr(data-label)`), an empty cell left out.
2. `StatusSection.vue`, `ActiveTable.vue`, `AdvancedSection.vue`:
   each `<td>` carries its column's header text as `data-label`,
   and each table sits in a `div.lfp-table-wrap` that is the container.
3. Vitest: each cell carries its label.

**Verification:** lint, typecheck, stylelint, Vitest, build;
the three tables looked at at 1150, 600 and 400 px.

### Block 2: Fields

1. `NumberField.vue`: the field at most 16rem, shrinking with the line,
   its Save button beside it.
2. `TypesSection.vue` / CSS: the add-extension field at 12rem, Add beside it,
   both on one line where they fit.

**Verification:** as Block 1, and the fields looked at at 400 px.

### Block 3: No horizontal overflow

1. `tests/e2e/settings.cy.js`: at a 400 px viewport, the settings page is
   no wider than its container, and every table cell is within it.
2. `tests/e2e/README.md`: the spec's row says so.

**Verification:** `settings.cy.js` on the harness instance.

### Block 4: Screenshots

1. `tests/e2e/screenshots.cy.js`: the selectors follow the rule lists.
2. `docs/Screenshots/*.png` retaken, with `CYPRESS_capture=1`.

**Verification:** each image looked at.

## Proposed commit message

One commit per block:

- `[TASK] Settings: tables wrap, each row a block of labelled values where the page is narrow`
- `[TASK] Settings: number fields and the extension field shrink to the line`
- `[TASK] Browser tests: nothing on the settings page overflows a phone's width`
- `[TASK] Screenshots retaken: each rule in four rows`

## Change History

- v1.0: the plan, from the TL;DR the user answered with PLAN++.
