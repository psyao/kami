# Triage Labels

The skills speak in terms of five canonical triage roles. This file maps those roles to the actual label strings used in this repo's issue tracker.

| Label in mattpocock/skills | Label in our tracker | Meaning                                  |
| -------------------------- | -------------------- | ---------------------------------------- |
| `needs-triage`             | `needs-triage`       | Maintainer needs to evaluate this issue  |
| `needs-info`               | `needs-info`         | Waiting on reporter for more information |
| `ready-for-agent`          | `ready-for-agent`    | Fully specified, ready for an AFK agent  |
| `ready-for-human`          | `ready-for-human`    | Requires human implementation            |
| `wontfix`                  | `wontfix`            | Will not be actioned                     |

When a skill mentions a role (e.g. "apply the AFK-ready triage label"), use the corresponding label string from this table.

Edit the "Label in our tracker" column to match whatever vocabulary you actually use.

## Project labels

| Label      | Meaning                                                                                                                                       |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `friction` | A problem met while trying a milestone on the playground Site or while migrating a reference site. Triaged like any issue; the verdict goes in the closing comment. |

## Triage in Kami

- No category label: `bug` and `enhancement` are not used here. Triage gives an issue a **target** instead, a Milestone or `V1`, in a comment (`Target: M9.`); the issue stays `needs-triage` until the target Milestone's `/to-spec` folds it into the spec, moves it into the Milestone and labels it `ready-for-agent`.
- `/mattpocock-skills:triage` is for issues outside that cycle: one with no target yet, or one from outside the project. It skips the AI disclaimer, never writes `.out-of-scope/` (a rejected request is closed `wontfix` with its reason in the closing comment), and keeps its "verify the claim" step: an issue is reproduced before it is `ready-for-agent`.
