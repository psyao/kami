# The starter kit keeps a `next` branch on Kami's `dev-main`, which the package's smoke installs

A starter kit change often needs Kami API that is not released yet: a component in the layout, a line in `public/index.php`, a new contract the `User` model implements. The starter kit's `main` is what `laravel new --using=psyao/kami-starter` installs, so it must always require a released Kami. Until M6, the package's shared-hosting smoke installed that same `main`, with the checkout swapped in for Kami. A starter change needing new API could therefore land neither before the release, since `main` would break `laravel new`, nor be tested by the smoke before it landed. M6 paid for it three times: in #349, in #392, and at the ritual's After Packagist step, which shipped 0.6.0 without the starter's MFA and Users screen because they waited for Packagist.

So `psyao/kami-starter` keeps a `next` branch whose `composer.json` requires `psyao/kami: dev-main`. During a milestone, the starter kit's pull requests target `next`, never `main`. The package's smoke (`tests/Smoke/run.sh`) installs the starter from `next` with the checkout as Kami, so a package pull request is tested against the starter it will ship with. At the ritual's After Packagist step, once the release is on Packagist, `next` merges into `main` with the new `^0.x` constraint, `laravel new` is checked to install it, and `next` restarts from `main` with `dev-main` committed again. `laravel new` keeps installing `main`, always on a released Kami. This amends psyao/kami#30 decision 9, under which the smoke tested the branch `laravel new` installs.

The decision trades a little simplicity (principle 6) for stability (principle 5): one more long-lived branch and a reset at every release, against a starter kit that can be changed and tested within the milestone that needs it.

## Considered options

- **Run the smoke on the starter kit's own CI.** The starter would test its pull requests against Kami's `dev-main`, but a package pull request would no longer be tested against the starter, which is the point of the smoke. It also duplicates the Docker stack in a second repository. Rejected for stability (principle 5) and simplicity (principle 6).
- **Hold starter pull requests until Kami is on Packagist** (the status quo). Every starter change needing new API waits for the ritual's After Packagist step, untested by the smoke until then: M6's three incidents. Rejected for stability (principle 5).

## Consequences

- The smoke tests `next`, not what `laravel new` installs. `main` only changes at the ritual's After Packagist step, after a release whose smoke ran on `next` with the same Kami, and that step checks `laravel new` once by hand.
- A starter pull request that needs a package change merges after it: `next` installs Kami's `dev-main` from Packagist, so the starter's own CI only sees what `main` of `psyao/kami` holds.
- Between releases `next` may be ahead of `main` with commits that only make sense with unreleased Kami; nobody installs `next` but the smoke.
- The After Packagist step is the only point where `next` merges into `main`: a release that skips it leaves `main` drifting behind, on the previous Kami, while `next` already relies on the new one.
- **Shared hosting and server** are not concerned: this is the release flow of two repositories, not a runtime behaviour.
