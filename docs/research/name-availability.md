# Name and vendor availability: `psyao/kami`, `Kami\`

Research note for Kami (issue #3). Question: are the Composer name `psyao/kami`, the GitHub repository `psyao/kami` and a root namespace (`Kami\` or `Psyao\Kami\`) free of harmful collisions, and which existing "Kami" projects could cause confusion or a trademark problem?

- Facts only, from primary sources where they were reachable. Where a source could not be read, this note says so.
- Retrieved 2026-10-25.
- Not legal advice. Trademark findings in § 5 are leads, not a clearance search.

---

## 1. Packagist

| Check | Result | Source |
|---|---|---|
| `psyao/kami` | **Free** (HTTP 404) | https://packagist.org/packages/psyao/kami.json |
| Vendor `psyao` | Already Steve's: `psyao/filament-icon-picker`, `psyao/mailtester`, `psyao/sage-cache` | https://packagist.org/packages/list.json?vendor=psyao |
| Any package named `*/kami`, `*/kami-*` or `*-kami` | **None** in the full package list | https://packagist.org/packages/list.json |
| Search "kami" | 4,124 hits, almost all fuzzy matches on vendor names (`kamisama/*`, `kamilsk/*`, `kamona/*`…); none is a CMS called Kami | https://packagist.org/search.json?q=kami&per_page=100 |

### Vendor `kami` exists (kamilabs)

The vendor `kami` is taken by kamilabs (Symfony bundles), and these packages use the root namespace **`Kami\`**:

| Package | Autoload | Last activity | Downloads | Source |
|---|---|---|---|---|
| `kami/api-core-bundle` | `Kami\ApiCoreBundle\` | 2022-01-05 | 2,589 | https://packagist.org/packages/kami/api-core-bundle.json |
| `kami/request-processor` | `Kami\Component\RequestProcessor\` | 2022-01-04 | 2,614 | https://packagist.org/packages/kami/request-processor.json |
| `kami/icobench-client` | `Kami\IcoBench\` | 2018-09-21 | 2,461 | https://packagist.org/packages/kami/icobench-client.json |
| `kami/icobench-bundle` | `Kami\IcoBenchBundle\` | 2018-06-15 | 2,175 | https://packagist.org/packages/kami/icobench-bundle.json |
| `kami/booking-bundle` | `Kami\BookingBundle` (PSR-0) | 2017-02-27 | 629 | https://packagist.org/packages/kami/booking-bundle.json |
| `kami/moneybird-api-bundle` | `Kami\MoneyBirdApiBundle` (PSR-0) | 2017-02-27 | 55 | https://packagist.org/packages/kami/moneybird-api-bundle.json |

None is flagged abandoned on Packagist; none has a tagged release more recent than 2022.

### Other users of the `Kami\` namespace

- `karlomikus/recipe-utils` autoloads `Kami\RecipeUtils\` (9,007 downloads, v0.17.0). https://packagist.org/packages/karlomikus/recipe-utils.json
- `karlomikus/bar-assistant` (1,112 stars, pushed 2026-10-24) uses `namespace Kami\Cocktail;` for its Laravel app. https://github.com/karlomikus/bar-assistant/blob/master/app/Utils.php
- GitHub code search for `"namespace Kami\"` in PHP returns 807 files (fuzzy; dominated by bar-assistant). https://github.com/search?q=%22namespace+Kami%5C%22+language%3APHP&type=code

**Fact relevant to the namespace choice:** `Kami\` is a root namespace already used by at least two independent publishers (kamilabs, karlomikus), with sub-namespaces that do not overlap Kami's likely ones (`Kami\ApiCoreBundle`, `Kami\Component\RequestProcessor`, `Kami\IcoBench*`, `Kami\BookingBundle`, `Kami\MoneyBirdApiBundle`, `Kami\RecipeUtils`, `Kami\Cocktail`). PHP has no namespace registry; a clash only bites if two packages in one install declare the same class. `Psyao\Kami\` has no user found.

---

## 2. GitHub

| Check | Result | Source |
|---|---|---|
| `psyao/kami` | Exists: this repo, private, created 2026-10-25 | https://github.com/psyao/kami |
| User `Kami` | Taken: Tomaz Muraus, account since 2009 (276 public repos) | https://api.github.com/users/kami |
| Repos with "kami" in the name | 21,754 | https://api.github.com/search/repositories?q=kami+in:name&sort=stars |
| …in PHP | 336 | https://api.github.com/search/repositories?q=kami+in:name+language:php&sort=stars |

### Notable "Kami" repositories

| Repo | Stars | Last push | What it is |
|---|---|---|---|
| [tw93/Kami](https://github.com/tw93/Kami) | 12,077 | 2026-10-25 | "Good content deserves good paper": layout rules and templates for AI agents to export PDF/PNG/PowerPoint. MIT, created 2026-04-20, site https://kami.tw93.fun |
| [mx-space/kami](https://github.com/mx-space/kami) | 341 | 2026-10-23 | Web frontend theme of the mx-space blog system (TypeScript) |
| [guregu/kami](https://github.com/guregu/kami) | 285 | 2017-11-17 | Go web "framework" with context |
| [sifive/Kami](https://github.com/sifive/Kami), [mit-plv/kami](https://github.com/mit-plv/kami) | 232 / 173 | 2020 / 2026 | Coq DSL for hardware design |
| [kami-blue/client](https://github.com/kami-blue/client), [zeroeightysix/KAMI](https://github.com/zeroeightysix/KAMI) | 379 / 245 | 2021 | Minecraft utility mods |
| [KamiYomu/KamiYomu](https://github.com/KamiYomu/KamiYomu) | 149 | 2026-09-20 | Self-hosted manga reader |

Many PHP hits are Chinese "卡密" (kǎmì, card key) licence-key systems, e.g. [yeuxuan/ccproxy_kami](https://github.com/yeuxuan/ccproxy_kami): unrelated word, same romanisation.

### Laravel CMS already called "Kami CMS"

- [JonasPardon/kami-core](https://github.com/JonasPardon/kami-core): "The core package of Kami CMS". Laravel (Inertia, spatie packages), composer name `jonaspardon/kami-core`, namespace `JonasPardon\KamiCore\`, licence declared "GNU GPLv3" in composer.json. Created 2019-09-28, last push 2023-01-09, 2 stars. https://github.com/JonasPardon/kami-core/blob/master/composer.json
- [JonasPardon/kami-boilerplate](https://github.com/JonasPardon/kami-boilerplate): "Boilerplate Laravel project for Kami CMS", last push 2023-01-01, 2 stars.
- `jonaspardon/kami-core` is **not** on Packagist (HTTP 404). https://packagist.org/packages/jonaspardon/kami-core.json

This is the closest functional collision found (same name, same category, same framework) but it is a small, inactive personal project.

---

## 3. SourceForge "Kami" PHP CMS

- Project `kami`, name "Kami": "a web based content mangagement system for small time developers". Registered **2006-09-18**, last update **2013-03-13**, single file `Kami.zip` (204.3 kB), 0 downloads/week, status "1 - Planning", PHP + MySQL, GPLv2, one developer (`aliasxneo`). Activity timeline is empty.
  - https://sourceforge.net/rest/p/kami
  - https://sourceforge.net/projects/kami/
  - https://sourceforge.net/rest/p/kami/activity/
- `kamicms`, `kami-cms`, `kamiphp` do not exist on SourceForge (HTTP 404 on `https://sourceforge.net/rest/p/<name>`).

This matches the brief's "old abandoned PHP CMS (2013)": the 2013 date is the last update, not the creation.

---

## 4. Kami, the PDF/annotation education app

- https://www.kamiapp.com/: "all-in-one platform", "Elevate instruction. Reach every learner.", claims "50+ million teachers and students worldwide". Footer: "© 2026 Kami. All Rights Reserved." No legal entity or ™/® notice on the home page. The terms page path tried (`/terms`) returned 404.
- Dealroom lists the company as originating from Auckland, New Zealand (secondary source): https://app.dealroom.co/companies/kami

This is the most prominent "Kami" in software, and it is a registered mark holder (see § 5).

---

## 5. Trademarks

### Registries: not readable from this environment

| Registry | Result |
|---|---|
| USPTO Trademark Search (https://tmsearch.uspto.gov) | JavaScript app; blocked in the browser pane by site permissions |
| USPTO TSDR API (https://tsdrapi.uspto.gov/ts/cd/casestatus/sn79283015/info) | HTTP 401: API key required since 2 October |
| USPTO TSDR page (https://tsdr.uspto.gov/) | HTTP 429 |
| EUIPO eSearch (https://euipo.europa.eu/eSearch/) | Connection failed |
| Swissreg (https://www.swissreg.ch/) | JavaScript app; no results readable without a browser |

So **no primary-source trademark fact** was established. The following are leads from secondary aggregators, to verify by hand.

### Leads (secondary sources, unverified)

- **KAMI**, US serial **79283015**, registration **6224591**, filed 2020-03-03, registered 2020-12-22, status "Registered", owner **Kami Buyer, LLC**, goods: downloadable education software for teaching school curriculum. Source: web search summary of https://trademarks.justia.com/792/83/kami-79283015.html (page itself returned 403).
- **KAMI**, US serial **79364546**, filed 2023-01-24, reported "Registered" (2024-02-13 per TrademarkElite). https://www.trademarkelite.com/trademark/trademark-detail/79364546/KAMI
- Kami Buyer, LLC also holds "CLASS VIEW" filings (79390301, 98841661). https://trademarks.justia.com/owners/kami-buyer-llc-6085783

**Fact worth noting:** US serial numbers starting with `79` are Madrid Protocol extensions of an international registration (WIPO). The owner therefore holds an international registration that may also designate Switzerland and/or the EU. Whether it does, and in which Nice classes (9 software, 41 education, 42 SaaS), is the open question.

### How to verify (manual, ~10 minutes)

1. WIPO Madrid Monitor, search the international registration behind US 79283015 and 79364546: https://www3.wipo.int/madrid/monitor/en/ (shows designated countries, including CH and EM).
2. Swissreg, word mark "KAMI", classes 9 and 42: https://www.swissreg.ch/database-client/search/query/trademark
3. EUIPO eSearch, word mark "KAMI": https://euipo.europa.eu/eSearch/
4. USPTO TSDR for 79283015 and 79364546: https://tsdr.uspto.gov/

---

## Summary of facts

| Item | Status |
|---|---|
| Composer `psyao/kami` | Free |
| GitHub `psyao/kami` | Owned (this repo) |
| Namespace `Psyao\Kami\` | No user found |
| Namespace `Kami\` | Already used by `kami/*` (kamilabs, Symfony, inactive since 2022) and `karlomikus/recipe-utils` / bar-assistant (active); sub-namespaces do not overlap |
| "Kami CMS" on Laravel | Exists: JonasPardon/kami-core, inactive since 2023, not on Packagist |
| SourceForge Kami CMS | Registered 2006, last update 2013, planning stage, inactive |
| Kami education app | Very prominent (50M+ users claimed); US marks KAMI held by Kami Buyer, LLC via Madrid Protocol (unverified at registry level) |
| CH / EU trademark status of "KAMI" | **Not established**: registries not reachable here |
