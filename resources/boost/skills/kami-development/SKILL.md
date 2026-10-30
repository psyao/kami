---
name: kami-development
description: Builds, installs, deploys and checks sites on Kami, the CMS package in this Laravel application. Use when creating a Kami site or adding Kami to an application, declaring a Content Type or its Fields, writing or publishing Entries, adding a Locale, rendering a type, reacting to content changes, changing security headers, managing Roles and Permissions, deploying or updating a site, fixing a kami:doctor or kami:install problem, or choosing what runs on shared hosting.
---

# Kami development

Each task has one guide, the source of truth: open it before acting, and follow it over anything remembered.

| Task | Guide |
| --- | --- |
| Create a new site from the starter kit | `vendor/psyao/kami/docs/guide/installation.md#creating-a-site` |
| Add Kami to an existing application or to its Filament panel | `vendor/psyao/kami/docs/guide/existing-application.md` |
| Declare a Content Type or its Fields, tabs, sections and conditions, with its labels, its view and its migration | `vendor/psyao/kami/docs/guide/content-types.md` |
| Write, publish, schedule or unpublish Entries, from the admin or from code | `vendor/psyao/kami/docs/guide/publishing.md` |
| React to content changes with Content events | `vendor/psyao/kami/docs/guide/publishing.md#reacting-to-content` |
| Add a Locale, or understand Translations, Fallback and `lang` | `vendor/psyao/kami/docs/guide/multilingual.md` |
| Render a type, write its view, override `render()` or place `Kami::routes()` | `vendor/psyao/kami/docs/guide/routing-and-rendering.md` |
| Load from another origin, extend the front CSP, or weigh an option that weakens it | `vendor/psyao/kami/docs/guide/security.md` |
| Give the User model Kami's Roles, declare a Role, or check a Permission | `vendor/psyao/kami/docs/guide/users-and-roles.md` |
| Create the first administrator or give an existing User the Role, from a terminal or a deploy script | `vendor/psyao/kami/docs/guide/installation.md#the-first-user` |
| Fix a problem `kami:doctor` or `kami:install` reports | The section of `vendor/psyao/kami/docs/guide/installation.md` the problem names |
| Fix a Path clash, or Paths that do not follow the declarations | `vendor/psyao/kami/docs/guide/routing-and-rendering.md#after-a-deploy` |
| Deploy a site for the first time | `vendor/psyao/kami/docs/guide/deployment.md#first-deploy` |
| Deploy again, update Kami, or ship a changed Prefix or Locale | `vendor/psyao/kami/docs/guide/deployment.md#later-deploys` |
| Decide whether something can run on the host (cron, queue, Node, PHP limits) | `vendor/psyao/kami/docs/guide/hosting.md` |
| Understand why Kami works the way it does | `vendor/psyao/kami/docs/adr/` |

A task the table does not cover is outside what Kami offers today: say so, and keep to Kami's public classes rather than its `Internal` namespaces under `vendor/psyao/kami/src/`.
