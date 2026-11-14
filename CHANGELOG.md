# Changelog

## [0.10.0](https://github.com/psyao/kami/compare/v0.9.1...v0.10.0) (2026-11-14)


### ⚠ BREAKING CHANGES

* a Taxonomy overriding `render(Entry $entry)` must declare `render(Entry $entry, ?CursorPaginator $entries = null)`.
* nested Entries' Paths hold their ancestors' slugs from the next `kami:install`, once-public ones answering 301; an Entry under a non-visible ancestor answers 404 and its `url()` is `null`.

### Features

* a hierarchical type's Path holds its ancestors' slugs, an Entry under a non-visible ancestor is hidden ([#645](https://github.com/psyao/kami/issues/645)) ([6c393b6](https://github.com/psyao/kami/commit/6c393b6d86cabfcbc2828be134276e5a77d8ce5d))
* a public SubmitForm action holds the Form route's visitor checks ([#642](https://github.com/psyao/kami/issues/642)) ([f061312](https://github.com/psyao/kami/commit/f0613129b2e525956feb36c8e3eff452748e424d))
* Archives and Term Archives list visible Entries with cursor pagination ([#646](https://github.com/psyao/kami/issues/646)) ([b25d409](https://github.com/psyao/kami/commit/b25d4099a29fcfc7388f10afccd4861d341a95f5))
* Kami::routes(middleware:) adds a rendering middleware to Kami's pages ([#643](https://github.com/psyao/kami/issues/643)) ([55a6450](https://github.com/psyao/kami/commit/55a64500e3df9ae3fff3baba56192e51e1abc2a8))
* RSS Feeds at /feed and /{prefix}/feed, with autodiscovery and page caching ([#647](https://github.com/psyao/kami/issues/647)) ([f5e842b](https://github.com/psyao/kami/commit/f5e842b1e7012a6001cb66c10e04346efccf89b9))
* the page cache keeps Inertia's JSON answer apart, served to its asset version only ([#641](https://github.com/psyao/kami/issues/641)) ([775da85](https://github.com/psyao/kami/commit/775da85a5655a2aa8bbff5b85f92c7305b2fedd8))


### Bug Fixes

* the page cache stores the response as it is sent ([#639](https://github.com/psyao/kami/issues/639)) ([5758daa](https://github.com/psyao/kami/commit/5758daafe7380d2f574bbbd42b7832c5373a3a18))

## [0.9.1](https://github.com/psyao/kami/compare/v0.9.0...v0.9.1) (2026-11-13)


### Features

* the Form route answers JSON, so a site can submit without a reload ([#601](https://github.com/psyao/kami/issues/601)) ([1bf298a](https://github.com/psyao/kami/commit/1bf298ad9571114db01fde7cc09b24616e558f61))

## [0.9.0](https://github.com/psyao/kami/compare/v0.8.0...v0.9.0) (2026-11-13)


### ⚠ BREAKING CHANGES

* `Import::entry()` also takes its values as a closure. `Import` is unreleased: no site is affected.

### Features

* Import adopts the Entry kami:install created for a Singleton or Global Set ([#573](https://github.com/psyao/kami/issues/573)) ([09736c0](https://github.com/psyao/kami/commit/09736c0b724eb0c94349857e10981e0c62cd72d7))
* import Entries replayably through Legacy ids ([#566](https://github.com/psyao/kami/issues/566)) ([ab21f71](https://github.com/psyao/kami/commit/ab21f71f9b78fc52a2a7656e4dcf4373f66c2cba))
* Import::media() and SaveMedia's rollback cleanup ([#568](https://github.com/psyao/kami/issues/568)) ([48ead28](https://github.com/psyao/kami/commit/48ead2847f19e621ee404d5358beb5102ea1fa4b))
* Import::submission() and aguet.dev's acceptance import ([#569](https://github.com/psyao/kami/issues/569)) ([1daa0e6](https://github.com/psyao/kami/commit/1daa0e65a91ce1498cd3f05999289c5bfebf9aa7))
* reach the move buttons by Tab, name selection checkboxes by title ([#581](https://github.com/psyao/kami/issues/581)) ([1b9e6d5](https://github.com/psyao/kami/commit/1b9e6d54f16a9f23653827a136f6a2782323a0df))


### Bug Fixes

* a wildcard field's old input, a failed Import's audit, empty items, the next-try zone ([#586](https://github.com/psyao/kami/issues/586)) ([3ea428f](https://github.com/psyao/kami/commit/3ea428f0283ead818727e6150e234f296d2e3a77))
* an importer's failed value conversion names its item ([#598](https://github.com/psyao/kami/issues/598)) ([277639c](https://github.com/psyao/kami/commit/277639c0858e5f53093d272e9ec583aaa6f9ab9d))
* claim a delivery before sending, keep to the Tick's budget, name waiting deliveries ([#577](https://github.com/psyao/kami/issues/577)) ([686a010](https://github.com/psyao/kami/commit/686a0106c89ccae894037ada104293b14d32a235))
* ContentMigrator refuses an unknown Field ([#583](https://github.com/psyao/kami/issues/583)) ([2fcd206](https://github.com/psyao/kami/commit/2fcd206164f56ebb6e4c8d7c803892ad2f190400))
* disabling MFA spends a recovery code only when it disables ([#582](https://github.com/psyao/kami/issues/582)) ([aea304a](https://github.com/psyao/kami/commit/aea304ae90097ed6b4543e07a1244fe8db9cf05b))
* Forms take any input shape, and the guides fill the playground's gaps ([#574](https://github.com/psyao/kami/issues/574)) ([c0d4aea](https://github.com/psyao/kami/commit/c0d4aeae8e933bd982f24664ecb4f1f5e875fe18))
* kami:install warns when it ignores --email or --name, UPGRADING 0.9.0 cites the link's copy button ([#595](https://github.com/psyao/kami/issues/595)) ([115c306](https://github.com/psyao/kami/commit/115c3065c3d42d8cf18983f9bf597ce8e845f4f5))
* kami:make:form declares replyTo(): string ([#576](https://github.com/psyao/kami/issues/576)) ([e1cfa17](https://github.com/psyao/kami/commit/e1cfa17f0486fc749b7c974f701eec2b1b0db80c))
* recovery codes spent once, translated admin titles, a set-password link ([#579](https://github.com/psyao/kami/issues/579)) ([6780608](https://github.com/psyao/kami/commit/6780608ee12acde1d6b5a29b3fe8da20456dc75b))
* singular labels read in lower case mid-sentence, the fallback in sentence case ([#592](https://github.com/psyao/kami/issues/592)) ([5739c2f](https://github.com/psyao/kami/commit/5739c2fe688dacf71d3de7d97abff8c2c85ac2c3)), closes [#588](https://github.com/psyao/kami/issues/588)
* the Entry table's move buttons and their announcements read in French ([#594](https://github.com/psyao/kami/issues/594)) ([d68810e](https://github.com/psyao/kami/commit/d68810e4df91ac6bef1e9c6451474717063759fe))


### Documentation

* UPGRADING 0.9.0, what a site acts on in the switch-over release ([#585](https://github.com/psyao/kami/issues/585)) ([46277ba](https://github.com/psyao/kami/commit/46277ba5237775a49e7d885244591d7f129bb839))

## [0.8.0](https://github.com/psyao/kami/compare/v0.7.0...v0.8.0) (2026-11-11)


### ⚠ BREAKING CHANGES

* Kami no longer registers `kami:sitemap` on Laravel's scheduler by itself. A site calls `Kami::schedule()` in `routes/console.php`, and a server's crontab runs `php artisan kami:tick` in place of `schedule:run`.

### Features

* announce Scheduled publications with kami:announce-publications ([#501](https://github.com/psyao/kami/issues/501)) ([0b72d0f](https://github.com/psyao/kami/commit/0b72d0fde8cfd4f1b01e13456b901e7e05672ba2))
* declare Forms and store their Submissions from a session-free route ([#491](https://github.com/psyao/kami/issues/491)) ([f023abe](https://github.com/psyao/kami/commit/f023abe8c5c6ce2daeff5e290e85ddd52e94c23f)), closes [#479](https://github.com/psyao/kami/issues/479)
* deliver Submissions through SMTP after the response, with kami:forms:deliver as backstop ([#495](https://github.com/psyao/kami/issues/495)) ([cdf7386](https://github.com/psyao/kami/commit/cdf7386b7382e7d93b2772f56368eec1018a3ad6))
* drain the database queue from each Tick ([#492](https://github.com/psyao/kami/issues/492)) ([bd852c8](https://github.com/psyao/kami/commit/bd852c81e4dc53160f8db7da0baa35d3922f5296))
* kami:doctor sees the web PHP and warns about a stalled scheduler ([#493](https://github.com/psyao/kami/issues/493)) ([a841d97](https://github.com/psyao/kami/commit/a841d97dff21832c6b74fa23dee600cff123e076))
* prune Submissions after their Form's retention (kami:forms:prune) ([#499](https://github.com/psyao/kami/issues/499)) ([7208f1a](https://github.com/psyao/kami/commit/7208f1a754d152eb0fcdee2db8db9cff94194482))
* read and delete form Submissions in the admin (SubmissionResource) ([#498](https://github.com/psyao/kami/issues/498)) ([8bff474](https://github.com/psyao/kami/commit/8bff474e58aeca7b4035feec10c3e057802f4d06))
* run overdue Scheduled tasks in-process with kami:tick and Kami::schedule() ([#487](https://github.com/psyao/kami/issues/487)) ([03fa0a1](https://github.com/psyao/kami/commit/03fa0a14e68b8e2d355249a6dccf0e92cbd2dd03))
* start a Form and its accessible view with kami:make:form ([#502](https://github.com/psyao/kami/issues/502)) ([617b523](https://github.com/psyao/kami/commit/617b5237d1ce369e534ea62418627ef25c060426))
* tick from the planner URL and from visits ([#489](https://github.com/psyao/kami/issues/489)) ([d59f459](https://github.com/psyao/kami/commit/d59f459ba718b0733b3b326cc5577a12e541d969))
* warn in kami:doctor about an smtp mailer with no timeout whether or not a Form is declared ([#517](https://github.com/psyao/kami/issues/517)) ([ab4d498](https://github.com/psyao/kami/commit/ab4d498b17f2e15298ffb276baa3577f80eab57a))
* warn in kami:doctor when a declared Form is mailed through an smtp mailer with no timeout ([#516](https://github.com/psyao/kami/issues/516)) ([6ef0b33](https://github.com/psyao/kami/commit/6ef0b334fa4d9a8e071613e4b2f8d50c1d782107))


### Bug Fixes

* announce a Scheduled Entry restored from the Trash after its moment ([#505](https://github.com/psyao/kami/issues/505)) ([7374429](https://github.com/psyao/kami/commit/73744290089b8d5f114af982b6baa750ee263cba))
* drop the Workbench 5xx views' dead stylesheet link, give them a main landmark and render the Workbench's own 503 view ([#512](https://github.com/psyao/kami/issues/512)) ([b67243f](https://github.com/psyao/kami/commit/b67243fa3de6452b52f358fc368931164a24a79e))
* give a badge's remove button a 24 px target and run axe's WCAG 2.2 AA rules on admin pages ([#511](https://github.com/psyao/kami/issues/511)) ([51bf4f2](https://github.com/psyao/kami/commit/51bf4f2e398bb04dd46b3653a9f2a6c27c1341f1))
* kami:bench measures server time only and never calls an uncached page cached ([#504](https://github.com/psyao/kami/issues/504)) ([ac9f6e6](https://github.com/psyao/kami/commit/ac9f6e61f01bb505c06d249635f7cdc3fbe8be9d))
* keep the announcement window when its read fails, refuse a non-string form origin with a 422 ([#531](https://github.com/psyao/kami/issues/531)) ([12845f0](https://github.com/psyao/kami/commit/12845f031b9647a9084a7f4ed1bd1996c2179bd3))
* lower-case the Site host, word reserved slugs, summarise paragraphs only, search the Audit log's User filter ([#503](https://github.com/psyao/kami/issues/503)) ([75f5ce7](https://github.com/psyao/kami/commit/75f5ce78c703c5e6005f3cf1a946fd13650cd813)), closes [#473](https://github.com/psyao/kami/issues/473)

## [0.7.0](https://github.com/psyao/kami/compare/v0.6.0...v0.7.0) (2026-11-08)


### ⚠ BREAKING CHANGES

* keep moved addresses after a reschedule, cache nothing on an unwritable manifest, lock sitemap builds ([#448](https://github.com/psyao/kami/issues/448))
* a Prefix starting with sitemaps or kami fails at boot, a slug taking a reserved segment or whole Path is refused at save, and kami:doctor --strict fails on hidden Paths. See UPGRADING.md section 0.7.0 item 6.
* an EntryForm building its own list for a type with a URL places the SEO section with $this->seo(), or the edit screen throws.
* `seo_image` is a reserved Field handle, ContentType gains socialImage(), and an EntryForm for SiteSettings must place `default_social_image`. See UPGRADING.md section 0.7.0 item 4.
* ContentType gains a public summary(Entry $entry): ?string, so a type declaring its own summary() with another signature fails to load. An EntryForm of the site's own for SiteSettings must place or hide the new default_description Field. kami:doctor --strict fails on a routable type with the default summary() and no RichText Field.
* pages are cached by default unless APP_DEBUG is on.

### Features

* hreflang, x-default, og:locale and the Locale region option ([#430](https://github.com/psyao/kami/issues/430)) ([df8f4a4](https://github.com/psyao/kami/commit/df8f4a49401ec77313d7ec859f0f9b450126773d))
* KAMI_INDEXABLE, X-Robots-Tag and the managed robots.txt ([#427](https://github.com/psyao/kami/issues/427)) ([be0dabc](https://github.com/psyao/kami/commit/be0dabc169baa8675ff5975efc47d42dc9245be4))
* Kami::exceptions() renders error pages in the URL's Locale ([#438](https://github.com/psyao/kami/issues/438)) ([2f6589f](https://github.com/psyao/kami/commit/2f6589f76758fb5cb34636664d193f146caa7141))
* Kami::routes(session: true) keeps a host application's session on Kami's pages ([#439](https://github.com/psyao/kami/issues/439)) ([e64d3ad](https://github.com/psyao/kami/commit/e64d3ad18ab8cb3e2ae81a93ebdfc67858da66fc))
* kami:bench, its last result in kami:doctor, and the public listing on MariaDB and MySQL ([#426](https://github.com/psyao/kami/issues/426)) ([daa8b6b](https://github.com/psyao/kami/commit/daa8b6b8873e69b70732385d73dd86519a0b767b))
* old addresses redirect after a slug or Prefix change, and Kami's own segments are reserved ([#437](https://github.com/psyao/kami/issues/437)) ([4c94fbb](https://github.com/psyao/kami/commit/4c94fbb06959517f832b85958d17bea4c415ce46))
* page cache with one Content version, manifest and writer ([#417](https://github.com/psyao/kami/issues/417)) ([6e8b70a](https://github.com/psyao/kami/commit/6e8b70a35007fc061bb08dfc9f69ecbaec9e71f6))
* serve cached pages before Laravel boots ([#424](https://github.com/psyao/kami/issues/424)) ([1a192cc](https://github.com/psyao/kami/commit/1a192cc6e52040060363334e291c229b96d69eef))
* the Open Graph image and cards ([#429](https://github.com/psyao/kami/issues/429)) ([6acd1b7](https://github.com/psyao/kami/commit/6acd1b72166b14ed5315054e86b666500baa8159))
* the SEO head, PageMeta and Kami::seo() ([#428](https://github.com/psyao/kami/issues/428)) ([885e0d9](https://github.com/psyao/kami/commit/885e0d9136ad1bdf8d561fd8fd3e66f447e9504a)), closes [#402](https://github.com/psyao/kami/issues/402)
* the SEO section in the admin ([#436](https://github.com/psyao/kami/issues/436)) ([83a4a22](https://github.com/psyao/kami/commit/83a4a22179dee50077980420259e6329e4fb8788))
* the static sitemap, kami:sitemap and Kami::sitemapUrls() ([#435](https://github.com/psyao/kami/issues/435)) ([a008e27](https://github.com/psyao/kami/commit/a008e2711397c4a55cc8f2a5e15668d0cb0f6421))
* x-kami::locales, x-kami::date and Entry::langAttribute() ([#421](https://github.com/psyao/kami/issues/421)) ([c7f9795](https://github.com/psyao/kami/commit/c7f97956222db0f87ed0bf2e8c2b42142d36b053))


### Bug Fixes

* keep moved addresses after a reschedule, cache nothing on an unwritable manifest, lock sitemap builds ([#448](https://github.com/psyao/kami/issues/448)) ([2efb440](https://github.com/psyao/kami/commit/2efb4407cbed15b7b046be20d3f48e705829716c))

## [0.6.0](https://github.com/psyao/kami/compare/v0.5.0...v0.6.0) (2026-11-08)


### ⚠ BREAKING CHANGES

* pickers no longer show titles of types the User may not view, and quick-create from a picker takes the target type's `view` as well as `create`. See UPGRADING.md section 0.6.0.
* a sixth sign-in attempt within a minute for one email is refused, the MFA code step counting as an attempt. See UPGRADING.md section 0.6.0 item 5.
* kami:install fails, and kami:doctor exits with 1, on a panel whose guard uses another model than auth.providers.users.model; kami:doctor --strict fails in production on an http:// URL or a session cookie not always Secure. See UPGRADING.md section 0.6.0.
* kami:doctor exits with 1 when a ->multiFactorAuthentication() or ->requiresMultiFactorAuthentication() set after the plugin replaces Kami's MFA, or when the User model lacks the two contracts; --strict fails while a manager has no MFA set up.
* the panel's User model must implement Filament's HasAppAuthentication and HasAppAuthenticationRecovery through HasKamiMfa, or sign-in fails; Users holding kami.users.manage must set MFA up at their next request.
* deleting the only User holding kami.users.manage through Eloquent now throws a ValidationException.

### Features

* assign Roles through AssignRoles, with the lockout guard ([#360](https://github.com/psyao/kami/issues/360)) ([d8053ab](https://github.com/psyao/kami/commit/d8053abf97326dd7ae1a038e584450c9a47369a3))
* check the panel's guard model, HTTPS and the secure cookie in kami:doctor ([#364](https://github.com/psyao/kami/issues/364)) ([0ce772c](https://github.com/psyao/kami/commit/0ce772cce3b8415e2ce3215eb18698a33bb55b6d))
* give Users Roles from the admin through the KamiRoles field ([#375](https://github.com/psyao/kami/issues/375)) ([1312300](https://github.com/psyao/kami/commit/1312300a05163e9093461cf51a15fa7d4bda8a61))
* pickers offer and title only types the User may view ([#372](https://github.com/psyao/kami/issues/372)) ([920186d](https://github.com/psyao/kami/commit/920186d8ebf11e38a5e1cfe34d48bc86642ac829))
* print a one-time link to set the first administrator's password ([#359](https://github.com/psyao/kami/issues/359)) ([5ddf917](https://github.com/psyao/kami/commit/5ddf917cba9c637f974ca4efaf097581bb3013fb)), closes [#352](https://github.com/psyao/kami/issues/352)
* read the Audit log in the admin and prune it with kami:audit:prune ([#368](https://github.com/psyao/kami/issues/368)) ([f7a6719](https://github.com/psyao/kami/commit/f7a6719b430237c8f584fd6094085bef0b2e2224))
* record a wrong MFA code after the right password in the Audit log ([#367](https://github.com/psyao/kami/issues/367)) ([08031d0](https://github.com/psyao/kami/commit/08031d0250e2c21ed6633ac647a2fdf3756d3e29))
* record visibility and deletion events in the Audit log ([#356](https://github.com/psyao/kami/issues/356)) ([6b2c8cd](https://github.com/psyao/kami/commit/6b2c8cd766f3bca16403cf42eb29d67b96fac138))
* require app MFA for kami.users.manage ([#362](https://github.com/psyao/kami/issues/362)) ([fee9c63](https://github.com/psyao/kami/commit/fee9c63e0a7ce26852da7ea800b037dfa2db1392)), closes [#351](https://github.com/psyao/kami/issues/351)
* rescue a lost authenticator from the shell, and check MFA in kami:doctor ([#363](https://github.com/psyao/kami/issues/363)) ([058d7b1](https://github.com/psyao/kami/commit/058d7b147ceb60fd669ab5cce04bb0a90d795f1d))
* serve a password reset page that reveals nothing ([#358](https://github.com/psyao/kami/issues/358)) ([507d5f2](https://github.com/psyao/kami/commit/507d5f246c4c13c16dcba1e7aac9dacaaf980200)), closes [#350](https://github.com/psyao/kami/issues/350)
* throttle admin sign-in per email, and record sign-in events ([#366](https://github.com/psyao/kami/issues/366)) ([8129e8c](https://github.com/psyao/kami/commit/8129e8c5ab646b82f40626d1fe2114d8344261af))


### Bug Fixes

* count, not title, linking Entries of types the User may not view ([#373](https://github.com/psyao/kami/issues/373)) ([e30ce77](https://github.com/psyao/kami/commit/e30ce77943478a6f9340a2c2dd458313f450e5a7))
* delete for good in plain words, no bulk Publish in the Trash, one reminder per save ([#370](https://github.com/psyao/kami/issues/370)) ([b9b587e](https://github.com/psyao/kami/commit/b9b587e7575234725b1356bfa63faae711de7b09))
* M6 playground frictions: dashboard with a Term, install without MFA contracts ([#392](https://github.com/psyao/kami/issues/392)) ([9662981](https://github.com/psyao/kami/commit/9662981345df42d1c367a74bf421820da2bf949f))
* M6 pre-release fixes: soft-deleted managers, trashed Entry in the Audit log, Volume fixture ([#382](https://github.com/psyao/kami/issues/382)) ([dfda364](https://github.com/psyao/kami/commit/dfda364e197e26fe3881b930c14358f75115d864))

## [0.5.0](https://github.com/psyao/kami/compare/v0.4.0...v0.5.0) (2026-11-06)


### ⚠ BREAKING CHANGES

* kami:install fails, and kami:doctor exits with 1, on an originals disk the web server can serve.
* kami:install fails on a non-local kami.media.public_disk or an existing public/media directory, and kami:doctor exits with 1 on a missing public/media link or an array or null default cache store. See UPGRADING.md section 0.5.0.
* generate a Conversion on its first request ([#312](https://github.com/psyao/kami/issues/312))

### Features

* add the Media Field ([#317](https://github.com/psyao/kami/issues/317)) ([bc692d2](https://github.com/psyao/kami/commit/bc692d2309adfe0e366ff94ee8e9daa746d3202f)), closes [#303](https://github.com/psyao/kami/issues/303)
* add the Media library with multiple upload ([#316](https://github.com/psyao/kami/issues/316)) ([18c6016](https://github.com/psyao/kami/commit/18c6016f2e43ab27089c5f78d1549e6393670682)), closes [#301](https://github.com/psyao/kami/issues/301)
* edit a Media item's alt text per Locale, decorative and file ([#318](https://github.com/psyao/kami/issues/318)) ([43b1b7d](https://github.com/psyao/kami/commit/43b1b7d3da9db5774cb39f8bd8a6c6113631369f))
* generate a Conversion on its first request ([#312](https://github.com/psyao/kami/issues/312)) ([29112fb](https://github.com/psyao/kami/commit/29112fb6325531220f8a1781a506563c980dac5d)), closes [#296](https://github.com/psyao/kami/issues/296)
* offer AVIF and WebP by a real encode test ([#315](https://github.com/psyao/kami/issues/315)) ([90ea127](https://github.com/psyao/kami/commit/90ea1271cd943a9b7a418607631456d854fb410e))
* pre-generate and prune Media files with kami:media:warm and kami:media:prune ([#324](https://github.com/psyao/kami/issues/324)) ([6d13195](https://github.com/psyao/kami/commit/6d13195ba3726000a556a3e276296a506bece886))
* refuse to delete Media in use ([#321](https://github.com/psyao/kami/issues/321)) ([30814dd](https://github.com/psyao/kami/commit/30814dde6943b3c93409315de9785ce8ca686018))
* render images with x-kami::image ([#319](https://github.com/psyao/kami/issues/319)) ([6e01296](https://github.com/psyao/kami/commit/6e0129691ad15050abdec66b37ff2aa4debf3387))
* serve Conversions from public/media through Apache, with nosniff ([#314](https://github.com/psyao/kami/issues/314)) ([7c930c9](https://github.com/psyao/kami/commit/7c930c9c51503497b246b7a1c46376de0f587e8c))
* serve documents and SVG unchanged ([#320](https://github.com/psyao/kami/issues/320)) ([4ccb762](https://github.com/psyao/kami/commit/4ccb762f29ae02a53da121f900d14e448d6209eb))
* store Media through SaveMedia ([#310](https://github.com/psyao/kami/issues/310)) ([8aa8623](https://github.com/psyao/kami/commit/8aa8623ef8ea5d3549a9fc945feb38c035e4d256))
* surface missing alt text on publish, in kami:doctor and in search ([#322](https://github.com/psyao/kami/issues/322)) ([373c2cb](https://github.com/psyao/kami/commit/373c2cbb06e78646f976882b9ee99cfeda399ac5))


### Bug Fixes

* refuse public originals, 404 stale Conversions, fill media defaults ([#334](https://github.com/psyao/kami/issues/334)) ([fb07179](https://github.com/psyao/kami/commit/fb0717925f7765055fc418db7eb03dc73a3aaeed))

## [0.4.0](https://github.com/psyao/kami/compare/v0.3.0...v0.4.0) (2026-11-04)


### ⚠ BREAKING CHANGES

* SaveEntry::handle() takes an optional sixth argument, updatedAt; no existing call changes. Every save now touches the Entry's updated_at.

### Features

* add bulk actions and the custom action recipe ([#280](https://github.com/psyao/kami/issues/280)) ([14cc547](https://github.com/psyao/kami/commit/14cc547874f368fc3f06daa3940fda80643b6653))
* add inline Markdown to Text and Textarea ([#263](https://github.com/psyao/kami/issues/263)) ([32678a9](https://github.com/psyao/kami/commit/32678a91ad61f37f393dfb13e2fc1d20914df794))
* add the dashboard widgets Recently edited, Scheduled and Needs attention ([#279](https://github.com/psyao/kami/issues/279)) ([5f3ca06](https://github.com/psyao/kami/commit/5f3ca06bda70ccdeb3f303c715844b078a9f39db)), closes [#256](https://github.com/psyao/kami/issues/256)
* add the Locale switcher and per-Locale table state ([#265](https://github.com/psyao/kami/issues/265)) ([664a7f4](https://github.com/psyao/kami/commit/664a7f47d2825f9cb0d9fc7fd4c1b7bda80eb13a))
* add the RichText Field and its Document ([#260](https://github.com/psyao/kami/issues/260)) ([af497c9](https://github.com/psyao/kami/commit/af497c9de200c22e054578fa4ddc379f78eb9c5b)), closes [#242](https://github.com/psyao/kami/issues/242)
* delete Entries for good and never-published Translations ([#275](https://github.com/psyao/kami/issues/275)) ([d2a95fb](https://github.com/psyao/kami/commit/d2a95fb156a29b5cb907fd9cde10a3092c1d360e))
* duplicate an Entry ([#276](https://github.com/psyao/kami/issues/276)) ([3ca4d27](https://github.com/psyao/kami/commit/3ca4d27121f544f99a1b8c1a6d1092cea14ecfa5))
* import HTML with Document::fromHtml and remind of accessibility issues on save ([#271](https://github.com/psyao/kami/issues/271)) ([4190b35](https://github.com/psyao/kami/commit/4190b3504b19df7baf88dc3102ec74f91a54e89d))
* preview the unsaved form from the create and edit pages ([#272](https://github.com/psyao/kami/issues/272)) ([d286151](https://github.com/psyao/kami/commit/d286151fa6b6e809106de7e993d56c69a2b6bd6b))
* refuse a save over a concurrent edit ([#269](https://github.com/psyao/kami/issues/269)) ([e2c8c5c](https://github.com/psyao/kami/commit/e2c8c5c8e93f99e407603df78c2bb012e989e324))
* search Entries from the admin's global search ([#278](https://github.com/psyao/kami/issues/278)) ([161e7f2](https://github.com/psyao/kami/commit/161e7f28bbbb7d0ae0854e094e7508b8b6d7d81a))
* share a Translation through Preview links ([#274](https://github.com/psyao/kami/issues/274)) ([8bf5e36](https://github.com/psyao/kami/commit/8bf5e3694c7a333ea3d1db47b5d042a2d1a31f2b))
* show scheduling in the admin and write the next visibility change ([#267](https://github.com/psyao/kami/issues/267)) ([50b085e](https://github.com/psyao/kami/commit/50b085e91c8c9b5454872e0750b0ce66b32f02ef))
* show the Creator and Last modifier in the admin ([#277](https://github.com/psyao/kami/issues/277)) ([0acebee](https://github.com/psyao/kami/commit/0acebeed443688e5ccb1bb56130627ef11552276))
* trash and restore Entries ([#273](https://github.com/psyao/kami/issues/273)) ([3a055d9](https://github.com/psyao/kami/commit/3a055d94c5e590ebecb97ffdf50c4b3903d314c8))


### Bug Fixes

* keep a trashed Entry's Position and read an unreadable form date as Publish ([#292](https://github.com/psyao/kami/issues/292)) ([a50cab8](https://github.com/psyao/kami/commit/a50cab8b1b6a20aa3efd08cb181436c4d5d7f482))
* refuse a RichText that is not valid UTF-8 on save ([#285](https://github.com/psyao/kami/issues/285)) ([7e56295](https://github.com/psyao/kami/commit/7e56295bb4526ad1813a6573765b069426f36151))
* refuse text that is not valid UTF-8 on save ([#284](https://github.com/psyao/kami/issues/284)) ([06cbb65](https://github.com/psyao/kami/commit/06cbb655b94b5928623b9693617ab165a4fa5ec2))


### Miscellaneous Chores

* give coding agents Boost's Laravel and testing skills ([#261](https://github.com/psyao/kami/issues/261)) ([5f9318c](https://github.com/psyao/kami/commit/5f9318c49d7e3c256ec706ae83621a8af31772ed))
* keep hand-overs on tickets, write the milestone ritual, check ponytail markers ([#257](https://github.com/psyao/kami/issues/257)) ([6d6189d](https://github.com/psyao/kami/commit/6d6189d84aa8310cb0d0e78b427d9f0bd5e61151))

## [0.3.0](https://github.com/psyao/kami/compare/v0.2.1...v0.3.0) (2026-11-01)


### Features

* add ContentMigrator ([#213](https://github.com/psyao/kami/issues/213)) ([1d9791e](https://github.com/psyao/kami/commit/1d9791eaa68d3a2ef5067f1e1064bc6890f1ce18))
* add EntryForm and EntryTable for a site's own admin form and table ([#216](https://github.com/psyao/kami/issues/216)) ([5776fc9](https://github.com/psyao/kami/commit/5776fc9a54ba54b2c5447cf7a8b1c2d10336838b))
* add Global Sets, SiteSettings and kami:install seeding ([#201](https://github.com/psyao/kami/issues/201)) ([6d289a3](https://github.com/psyao/kami/commit/6d289a31919670cd950951aa98363c62ba2d2927))
* add hierarchical Content Types ([#208](https://github.com/psyao/kami/issues/208)) ([8d97c62](https://github.com/psyao/kami/commit/8d97c62eb02f5dbcac5c731b6453b3c413d1ec51))
* add kami:make:type and cover a Singleton and a relation in the smoke ([#221](https://github.com/psyao/kami/issues/221)) ([0f9851c](https://github.com/psyao/kami/commit/0f9851c78e716b54a97006ddc7962cc4acb1a2a4))
* add manual order for Content Types ([#206](https://github.com/psyao/kami/issues/206)) ([d903c57](https://github.com/psyao/kami/commit/d903c572ab30f96ba0a344cebb2250c18ae5754f))
* add References and the Entries Field ([#202](https://github.com/psyao/kami/issues/202)) ([ec6050a](https://github.com/psyao/kami/commit/ec6050a3fb7e39b63c5e5d2d858b5edf5222bae6))
* add Repeater and Group Fields ([#199](https://github.com/psyao/kami/issues/199)) ([88f3b24](https://github.com/psyao/kami/commit/88f3b24b27fcdca0ce0b0605cf2300e0f9689fa4))
* add Tab and Section layout and declarative Field conditions ([#196](https://github.com/psyao/kami/issues/196)) ([faa6e2d](https://github.com/psyao/kami/commit/faa6e2d0a94b734c68794156efb313260798595f))
* add Taxonomies, the Terms Field and $model ([#203](https://github.com/psyao/kami/issues/203)) ([60aa0cf](https://github.com/psyao/kami/commit/60aa0cf966007b1bfb7228d1c96dd46b55eaa06b))
* add the Boolean Field and the fieldComponents() registry ([#187](https://github.com/psyao/kami/issues/187)) ([f00e9b1](https://github.com/psyao/kami/commit/f00e9b1e382a3e9724df92fe5c1f53461fd39261))
* add the Url, Email, Number, Date, DateTime and Select Fields and indexed columns ([#189](https://github.com/psyao/kami/issues/189)) ([57a80ae](https://github.com/psyao/kami/commit/57a80aee0ff6d5eaaa4c6f6f8625ca2674d25ea3))
* add types without URLs and Singletons ([#200](https://github.com/psyao/kami/issues/200)) ([39b7c19](https://github.com/psyao/kami/commit/39b7c1970ebe92ebfbf15e59024aec1f6f676f68))
* fail kami:install on content model drift, report it in kami:doctor ([#214](https://github.com/psyao/kami/issues/214)) ([78314ee](https://github.com/psyao/kami/commit/78314ee2b530a752fad1ae6e95527aee62430ebc))
* keep the level on the Edit and Create pages ([#210](https://github.com/psyao/kami/issues/210)) ([b8dfb84](https://github.com/psyao/kami/commit/b8dfb8428b298d4d51564b147b4e45c9a141d0f9))


### Bug Fixes

* correct the core defects found by the first code review ([#193](https://github.com/psyao/kami/issues/193)) ([97dfd89](https://github.com/psyao/kami/commit/97dfd89fe50c86f3ecab1d96c958347f2b20e984))
* finish renameType under a prefix and after a stop, hold required Shared Fields, search titles in any case ([#228](https://github.com/psyao/kami/issues/228)) ([92deee0](https://github.com/psyao/kami/commit/92deee016ea4eb2dce333e3148ffe63fd4401940))
* keep Permission-held values from a save, whatever the form ([#218](https://github.com/psyao/kami/issues/218)) ([689fbe3](https://github.com/psyao/kami/commit/689fbe3fbe71ebb724e5fe6afdfa2b32c43eff00))
* name a generated type table's unique index and foreign key within every engine's limit ([#195](https://github.com/psyao/kami/issues/195)) ([8df1c48](https://github.com/psyao/kami/commit/8df1c48cc3dc7302d16c9d5d521833806355a830))
* name sub-Fields in errors, hold focus on keyboard reorder, fill guide gaps ([#239](https://github.com/psyao/kami/issues/239)) ([c6513f3](https://github.com/psyao/kami/commit/c6513f38c68297cc2c11e78dd908ba12e7201fca))
* refuse a column retype no cast answers on every engine ([#192](https://github.com/psyao/kami/issues/192)) ([b4bf93b](https://github.com/psyao/kami/commit/b4bf93bee093ab80c2efe407b2911e0980517621))
* write no Shared value a save did not ask for ([#219](https://github.com/psyao/kami/issues/219)) ([eca468b](https://github.com/psyao/kami/commit/eca468b48ba3eea613f45ef9e48cdcc5fc51d36e))


### Miscellaneous Chores

* add composer serve and the Workbench seeder ([#198](https://github.com/psyao/kami/issues/198)) ([8a78e29](https://github.com/psyao/kami/commit/8a78e291e8281cb93fdce3dddbba6d38bdc5f8ae))
* close M3's debt ledger and release it as 0.3.0 ([#229](https://github.com/psyao/kami/issues/229)) ([0065803](https://github.com/psyao/kami/commit/0065803a51838f7ff129d218f38a5d1b7189c183)), closes [#169](https://github.com/psyao/kami/issues/169)

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
