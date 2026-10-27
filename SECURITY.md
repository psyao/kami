# Security policy

## Reporting a vulnerability

Report it privately through GitHub: [open a draft advisory on `psyao/kami`](https://github.com/psyao/kami/security/advisories/new). This is the only channel, and it also covers [`psyao/kami-starter`](https://github.com/psyao/kami-starter). Please do not open a public issue or pull request, and do not send it by email.

- You get an acknowledgement within 7 days.
- A high or critical vulnerability is fixed within 30 days. A lower one is fixed in the next release.
- The fix ships as a patch release of every supported version. The advisory is published with it, with a CVE requested through GitHub and you credited unless you ask otherwise. Packagist relays it to `composer audit`.

A security fix may tighten a default (the Content Security Policy, a validation rule) in a patch release even if a site depends on the looser one. `UPGRADING.md` and the advisory say so.

## Supported versions

| Version | Supported | Until |
| --- | --- | --- |
| Latest 0.x release | Bug and security fixes | The next release |
| Older 0.x releases | None: update to the latest | |

Before 1.0, only the latest 0.x release receives fixes. From 1.0:

- The latest minor of the current major receives bug and security fixes. Updating within a major never breaks a site.
- The previous major receives security fixes only, for 6 months after the next major's first release, and never beyond the security support of its Laravel or Filament major. The table gives its end date.
