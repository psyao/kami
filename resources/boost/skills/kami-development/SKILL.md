---
name: kami-development
description: Installs, configures, deploys and checks Kami, the CMS package in this Laravel application. Use when creating a Kami site, adding Kami to an application or its Filament panel, deploying or updating a site, fixing a kami:doctor or kami:install problem, or choosing what runs on shared hosting.
---

# Kami development

Each task has one guide, the source of truth: open it before acting, and follow it over anything remembered. Guides live in `vendor/psyao/kami/docs/guide/`.

| Task | Guide |
| --- | --- |
| Create a new site from the starter kit, or its first User | `vendor/psyao/kami/docs/guide/installation.md#creating-a-site` |
| Add Kami to an existing application or to its Filament panel | `vendor/psyao/kami/docs/guide/existing-application.md` |
| Fix a problem `kami:doctor` or `kami:install` reports | The guide section the problem names, under `vendor/psyao/kami/docs/guide/installation.md` |
| Deploy a site, the first time or again, or update Kami | `vendor/psyao/kami/docs/guide/deployment.md` |
| Decide whether something can run on the host (cron, queue, Node, PHP limits) | `vendor/psyao/kami/docs/guide/hosting.md` |
| Understand why Kami works the way it does | `vendor/psyao/kami/docs/adr/` |

A task the table does not cover is outside what Kami offers today: say so rather than reaching into `vendor/psyao/kami/src/`, whose `Internal` namespaces are not Kami's public API.
