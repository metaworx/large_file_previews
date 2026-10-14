# NOTE SettingsLayout v1.0: field values and help placement

Two defects the user found on the page after blocks 1 to 3,
fixed in a `[FIX]` commit of their own rather than by amending commits already pushed.
Block 4 then runs as planned.

## The MIME fields show the shipped default

- **Found:**
  a MIME type's minimum and maximum showed its rule in force,
  the shipped default with what is stored laid over it,
  so every image type showed 50 as if it were set.
  Block 1 changed the command beneath the row, and left the fields.
- **Fix:**
  the fields show what is stored for the type, empty where nothing is,
  with the value in force as the field's placeholder.
  A value typed sets it at this level; one cleared returns to the default.
  The switch stays on the value in force, since a switch cannot be empty.
  An extension's fields already show what is stored for it.

## The ⓘ beside a field overlaps its Save button

- **Found:**
  in *Advanced*, the help placed beside a `NumberField`
  sat over the field's *Save* button.
- **Fix:**
  `NumberField` takes a `help` text and places the ⓘ itself, after *Save*,
  so every field with help lays out alike.

## Decided

- *Threads per render* stays on the page, with the timeouts, caps,
  the copy's maximum and the failure retry:
  none widens what an administrator without shell access can do
  beyond what the caps already allow.
  Paths and untrusted decoders stay in system config.
