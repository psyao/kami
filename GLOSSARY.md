# Kami

A Laravel + Filament CMS distributed as a package: developers model a site in code, editors manage its content in a tailored admin.

## Language

### Distribution

**Kami package**:
The Composer package that holds the whole product; every site gets fixes and features through it.
_Avoid_: Core, plugin (when meaning the whole package)

**Starter kit**:
A ready-wired Laravel application from which a new site is created; once created, its code belongs to the site.
_Avoid_: Skeleton, boilerplate, theme

**Host application**:
The Laravel application Kami is installed into, which owns its users, routes and admin panel.
_Avoid_: Parent app, base app
