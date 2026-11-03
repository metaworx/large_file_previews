# NOTE NextcloudVue v1.0: how the run went

Where the run of blocks 1 to 4 (`EXEC++`,
2026-11-03) went differently from the plan, and what each taught.

1. **The first release went out as 0.1.1, then 0.1.2.**
   v0.1.0's pipeline failed before its script ran:
   `install.sh --ssh` had recorded the guidelines by their SSH address in `.gitmodules`,
   and the runner has no SSH client.
   The address became https (`[FIX] The runner clones the guidelines by https`),
   and since a pushed tag is not moved here,
   0.1.1 was cut as the same package. 0.1.2 followed once this app's lock file
   showed that GitLab's registry drops `peerDependenciesMeta`:
   `vitest` and `@vue/test-utils`, optional peers for `./testing`,
   reached npm as required ones and flipped the dev flag on ninety-odd packages.
   They are declared nowhere now; the package's contract §3.5 says why.
2. **A release runs one pipeline, its tag's.**
   The user asked that a `[RELEASE]` commit pushed to master not run a branch
   pipeline beside the tag's; `workflow` rules in `.gitlab-ci.yml` skip it,
   and the package's CI.md §2 records the rule.
3. **Pushed once, after block 3.**
   Blocks 1 and 2 were committed locally and pushed with the release,
   because `changelog.sh check` reports a manifest version with no released section
   until the first cut, and the first push would have been red.
4. **The lock file records a sha1 integrity** for the package:
   the only hash GitLab's registry provides.
   Nothing to do; noted so that nobody "fixes" it by hand.
5. **The run's edits were delegated**:
   an Opus agent did block 4's edits and verification against a brief;
   the commits, the releases and the checks over its work stayed here.
6. **The old `HelpPopover.spec.ts` left an unhandled focus-trap error** in this
   app's suite (`NcPopover`'s trap with no tabbable node);
   the package's spec of the same component does not,
   and the suite is clean after block 4.
