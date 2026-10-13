> **Fragment** — inlined by `tools/sync-docs.sh`; not a standalone document.

# {{project_name}} (v1.0.0)

<!-- TEMPLATE: installed as {{guidelines_root}}/project/_README.md. This is the
     opening of the generated {{guidelines_root}}/README.md, and the only
     project document written for people first. Replace every {{TODO: …}}.
     Keep it short: what this is, how to work on it, where the rules live. -->

{{TODO: one paragraph: what this project is and who it is for}}

## Working on it

{{TODO: how to get from a fresh clone to a running checkout - the commands,
not the theory.
Anything an agent must also know belongs in _CONTRACT.md instead,
where both audiences read it.}}

## The rules that apply here

The conventions below are not AI configuration:
they are how this project is written,
and an agent is simply another party required to follow them.

- The contract that follows is generated into `/AGENTS.md` as well,
  so people and agents are held to the same text.
- The shared conventions live in `{{shared_root}}` — commit policy,
  quality pass, per-language testing, linting and code style.
- `{{guidelines_root}}/README.md` explains the gate protocol:
  what an agent asks before it changes anything,
  and what `EXEC` and its relatives authorise.

Both generated documents come from the fragments in `{{guidelines_root}}`.
Editing a generated file is wasted work — change the fragment and re-run
`{{shared_root}}/tools/sync-docs.sh`.
