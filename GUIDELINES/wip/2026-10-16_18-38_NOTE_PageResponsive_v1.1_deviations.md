# NOTE PageResponsive v1.1: deviations

Where the run of Blocks 5 to 10 went differently from the plan.

## Block 5

A size and its source sit on one template line:
Vue drops the whitespace between two elements on separate lines,
and with it the only place the two could break.

## Block 7

`storedMimeRules()`, recomputed on every call before,
is now read once per request with the merged rules,
and dropped by every write, a scalar's included,
since what a type ships with follows `min_size_all_types`.
The enabled switch's source of a type nobody set is now `shipped`, not `mime`.

## Block 9

- **Labels above the fields.**
  A label inside a field covers its placeholder until the field has the focus,
  so "30 (default)" would not have shown.
  Every number field with a default as placeholder has its label above it,
  as a rule's sizes have; so do the defaults, for one look.
- The Cypress check reads a setting's `stored` from `occ lfp:config:show`.

## Block 10

The limits shot ends at the file-size cap:
with its labels above its fields the section no longer fits the viewport,
and the out-of-memory score is left out of it.

## On the way

The commit of Block 7 first took the scratch spec `tests/e2e/zz-scratch-look.cy.js` with it,
staged with `tests`;
it was amended out before anything was pushed.
