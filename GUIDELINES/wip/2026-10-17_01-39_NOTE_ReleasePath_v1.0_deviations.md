# NOTE ReleasePath v1.0: deviations

Where the run of Blocks 1 to 3 went differently from the plan.

## Block 1

The verification planned, a dry run of `changelog.sh cut 0.2.0` naming the README's block,
cannot run while `[Unreleased]` is empty: the tool stops there.
The tool finds a pin block in every tracked `.md` and `.xml` by its marker,
which the README's carries as the manifest's does.

## Block 3

The plan guarded master by the shipped paths, `project.shipped`.
Those leave out what the build reads - `composer.json`, `package.json`,
`vite.config.ts`, `package.sh` - and the README, which the package carries,
so master could have changed the package and still passed.
`release-meta` names instead what may differ:
tests, docs, CI, the guidelines, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`, `ROADMAP.md`,
and the lint and test configurations.
Anything else refuses the run.
