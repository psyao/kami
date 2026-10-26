# Kami is MIT-licensed, and the licence of the core never changes

The brief asks for a licence Kami can hold for good. October CMS changing its licence midway is one of its anti-goals, and no dependency may restrict a future open-source or commercial distribution. `psyao/kami` and `psyao/kami-starter` are therefore both MIT, copyright Steve Aguet. MIT is also the licence of Laravel, Filament, Livewire, Tiptap and every candidate dependency ("Candidate dependencies", #4). Contributions come in under the same licence, inbound = outbound, with no CLA. Without a CLA, nobody holds the right to relicense other people's code, which makes the commitment credible: the core's licence never changes. Anything paid (extensions, hosting, support) sits beside the core and never replaces its licence. Velox was MIT too; it is evidence only.

## Considered options

- **GPL-3.0 or AGPL-3.0.** Copyleft would prevent closed forks. But Kami lives inside host applications it does not own (ADR 0001): an agency project or kawaiidream.net would have to accept copyleft for its own code, and the AGPL drives such users away. Keeping a commercial door would also need dual licensing with a CLA, which is the very mechanism that makes a licence change midway possible.
- **Source-available licences (BSL, Commons Clause).** They are not open source, and they are the path October CMS took. Rejected.
- **A more permissive licence for the starter kit (0BSD, Unlicense)**, so sites would not have to keep the notice. This means a second licence to explain for no real gain: `laravel/laravel` is MIT and its sites never notice.

## Consequences

- Every pull request runs `composer licenses` against an allow-list (MIT, BSD-2-Clause, BSD-3-Clause, Apache-2.0, ISC). A dependency outside the list fails CI until an ADR admits it, which turns the brief's "flag any restricting licence" into a check.
- The repositories are public before the first release (ADR 0031). `LICENSE.md` lands with the first commit of application code, so no code is ever published as all rights reserved.
- MIT grants no trademark. The "Kami" name risk found in "Name and vendor availability" (#3) is a separate matter.
