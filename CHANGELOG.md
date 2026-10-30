# Changelog

## [0.2.1](https://github.com/psyao/kami/compare/v0.2.0...v0.2.1) (2026-10-30)


### Features

* fill the slug from the title in the admin form ([#165](https://github.com/psyao/kami/issues/165)) ([7961e08](https://github.com/psyao/kami/commit/7961e08dac1178c336714aa73f360f96467607f7))
* kami:install --email grants an existing User the administrator Role ([#164](https://github.com/psyao/kami/issues/164)) ([f13a267](https://github.com/psyao/kami/commit/f13a267534bfbad0ab897bf8363624ce7562e8ec))


### Bug Fixes

* kami:install and kami:make:migration detect a missing terminal ([#156](https://github.com/psyao/kami/issues/156)) ([2c191df](https://github.com/psyao/kami/commit/2c191dfc5f94a796d4edc522dc1489816b0c91ce))
* kami:install matches emails case-insensitively on every engine ([#166](https://github.com/psyao/kami/issues/166)) ([822689b](https://github.com/psyao/kami/commit/822689b876431bff80dbdfffae141d6d0657f4da))
* kami:install's existing-email message fits the prompt ([#158](https://github.com/psyao/kami/issues/158)) ([6eb25fc](https://github.com/psyao/kami/commit/6eb25fcc2d6c6711629d69740dcb5c915c59ffed)), closes [#150](https://github.com/psyao/kami/issues/150)

## [0.2.0](https://github.com/psyao/kami/compare/v0.1.0...v0.2.0) (2026-10-30)


### ⚠ BREAKING CHANGES

* SaveEntry::handle() takes a fifth argument, ?Authenticatable $actor, and throws ValidationException on invalid input; kami_translations.title and slug are nullable and kami_entries gains created_by and updated_by (migration edited in place before production, ADR 0035).

### Features

* a declared Content Type renders at its Path ([#111](https://github.com/psyao/kami/issues/111)) ([6f3ce67](https://github.com/psyao/kami/commit/6f3ce6702c90752f43dacc58f3bec406ffb78351))
* a second Locale, Shared and Translatable Fields, Fallback with lang ([#113](https://github.com/psyao/kami/issues/113)) ([e3bd70f](https://github.com/psyao/kami/commit/e3bd70fe160c9c62b1908e18e9d31aa531d52861)), closes [#97](https://github.com/psyao/kami/issues/97)
* admin edits each Locale, Shared Fields once, state per Locale ([#119](https://github.com/psyao/kami/issues/119)) ([c95f804](https://github.com/psyao/kami/commit/c95f80410b2a02610372f95ac1d61267b4fc0eba))
* admin lists, creates and edits Entries in the default Locale ([#118](https://github.com/psyao/kami/issues/118)) ([1c6145b](https://github.com/psyao/kami/commit/1c6145b09371279b27eb442a2308e5ba3bf9dd8c))
* Content events after commit ([#115](https://github.com/psyao/kami/issues/115)) ([34216ab](https://github.com/psyao/kami/commit/34216ab328058a484757255b8697398bcd11d9aa))
* kami:install creates the first administrator ([#125](https://github.com/psyao/kami/issues/125)) ([9e9b033](https://github.com/psyao/kami/commit/9e9b03389d6d49b98c624fc6e42ef2fa50329ac0))
* kami:install syncs Paths with the declarations ([#136](https://github.com/psyao/kami/issues/136)) ([10a0446](https://github.com/psyao/kami/commit/10a0446e66e72dd3ed3226c38eb8e5329a0e49fe))
* kami:make:migration writes the kami_type_* migrations ([#129](https://github.com/psyao/kami/issues/129)) ([e4586e5](https://github.com/psyao/kami/commit/e4586e59f0e11eda6068377a7a9437142149cbf2))
* Roles in code, Permissions and EntryPolicy ([#116](https://github.com/psyao/kami/issues/116)) ([72677dd](https://github.com/psyao/kami/commit/72677dd23495d651d164206c712a10e5a98f680b))
* SaveEntry validates, publishes per ADR 0019, catches slug clashes and records the actor ([#114](https://github.com/psyao/kami/issues/114)) ([137a560](https://github.com/psyao/kami/commit/137a5607ac9134b32f439805e47afd13df91e30a)), closes [#98](https://github.com/psyao/kami/issues/98)
* SecurityHeaders sends Kami's CSP on the front and the admin ([#122](https://github.com/psyao/kami/issues/122)) ([60b94f2](https://github.com/psyao/kami/commit/60b94f2fccc9ded74d83023725aaf3c9321ef371))
* validate declarations and read labels from kami-types ([#134](https://github.com/psyao/kami/issues/134)) ([8a4d50f](https://github.com/psyao/kami/commit/8a4d50f7a1278e95eeff2193c84d6be03b854c0b))


### Bug Fixes

* kami:make:migration emits void return types on the Schema closures ([#141](https://github.com/psyao/kami/issues/141)) ([99ec9d6](https://github.com/psyao/kami/commit/99ec9d6819c1252c8708d76d96aed354a9176704))


### Miscellaneous Chores

* multisite ponytail markers name V1 ([#146](https://github.com/psyao/kami/issues/146)) ([7154dcc](https://github.com/psyao/kami/commit/7154dcc2cd09789d97629dc0c659cc615cdef1e8))
* require Rector 2.8.3 and lift the PHPStan 2.3.4 exclusion ([#142](https://github.com/psyao/kami/issues/142)) ([3466886](https://github.com/psyao/kami/commit/3466886627b42c5132868429de674c9088000dc1))

## 0.1.0 (2026-10-28)


### Features

* kami:doctor reports environment problems with severities ([#54](https://github.com/psyao/kami/issues/54)) ([acf030f](https://github.com/psyao/kami/commit/acf030f2b293b4b70469f5f7bba2c7134497002c)), closes [#39](https://github.com/psyao/kami/issues/39)
* kami:doctor warns when a Kami panel keeps Filament's remote avatars ([#70](https://github.com/psyao/kami/issues/70)) ([bc2f59b](https://github.com/psyao/kami/commit/bc2f59b3e4981447d1fa41912ab5a59d73732666))
* kami:install installs and redeploys Kami idempotently ([#55](https://github.com/psyao/kami/issues/55)) ([27e6bdc](https://github.com/psyao/kami/commit/27e6bdc7197105d012d3fb05974ed34a87c81ec7))
* local initials avatar provider for the admin ([#60](https://github.com/psyao/kami/issues/60)) ([508c9f5](https://github.com/psyao/kami/commit/508c9f5d37cdd6db23bd71a9f8e72a829cf8ff9c))
* package skeleton installed in a host panel ([#52](https://github.com/psyao/kami/issues/52)) ([f38bc66](https://github.com/psyao/kami/commit/f38bc6613a3f6a288d9d758cabdbbd2e7c5b78d6)), closes [#37](https://github.com/psyao/kami/issues/37)


### Bug Fixes

* kami:doctor accepts a missing SQLite file in a writable directory ([#58](https://github.com/psyao/kami/issues/58)) ([6dc71b5](https://github.com/psyao/kami/commit/6dc71b51ed4dd8133d91f2f4ad75aa4ac5cad45a)), closes [#56](https://github.com/psyao/kami/issues/56)
* keep storage/framework/kami out of git ([#89](https://github.com/psyao/kami/issues/89)) ([c99d271](https://github.com/psyao/kami/commit/c99d2714be94af6d9b8f19d89675fada824a373f)), closes [#84](https://github.com/psyao/kami/issues/84)
* let sites run php artisan optimize ([#66](https://github.com/psyao/kami/issues/66)) ([a33eb73](https://github.com/psyao/kami/commit/a33eb73542099c1880ef6b3c720fb1b8a0b300b1)), closes [#65](https://github.com/psyao/kami/issues/65)
* send X-Forwarded-Proto with smoke probes, drop the force-HTTPS step ([#90](https://github.com/psyao/kami/issues/90)) ([66a733e](https://github.com/psyao/kami/commit/66a733e7d52c5533fe0ab72b312b295aee90caf4))


### Miscellaneous Chores

* Larastan max, Rector and lefthook from Composer ([#57](https://github.com/psyao/kami/issues/57)) ([6649bff](https://github.com/psyao/kami/commit/6649bff72c024190a4f505bbe4e730df46114a77)), closes [#44](https://github.com/psyao/kami/issues/44)

## Changelog
