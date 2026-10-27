---
name: kami-development
description: Installs, configures, deploys and checks Kami, the CMS package in this Laravel application. Use when creating a Kami site, adding Kami to an application or its Filament panel, deploying or updating a site, fixing a kami:doctor or kami:install problem, or choosing what runs on shared hosting.
---

# Kami development

Each task has one guide, the source of truth: open it before acting, and follow it over anything remembered.

| Task | Guide |
| --- | --- |
| Create a new site from the starter kit | `vendor/psyao/kami/docs/guide/installation.md#creating-a-site` |
| Create the first User of the admin | `vendor/psyao/kami/docs/guide/installation.md#the-first-user` |
| Add Kami to an existing application or to its Filament panel | `vendor/psyao/kami/docs/guide/existing-application.md` |
| Fix a problem `kami:doctor` or `kami:install` reports | The section of `vendor/psyao/kami/docs/guide/installation.md` the problem names |
| Deploy a site for the first time | `vendor/psyao/kami/docs/guide/deployment.md#first-deploy` |
| Deploy again, or update Kami | `vendor/psyao/kami/docs/guide/deployment.md#later-deploys` |
| Decide whether something can run on the host (cron, queue, Node, PHP limits) | `vendor/psyao/kami/docs/guide/hosting.md` |
| Understand why Kami works the way it does | `vendor/psyao/kami/docs/adr/` |

A task the table does not cover is outside what Kami offers today: say so, and keep to Kami's public classes rather than its `Internal` namespaces under `vendor/psyao/kami/src/`.
