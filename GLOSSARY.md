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

**Public API**:
The part of the Kami package a site may rely on, kept stable across minor versions; everything else is internal and may change at any time.
_Avoid_: Public surface (as a second term), SDK

**Extension point**:
A part of the Public API that a site may build on rather than only call: a class it extends, a list it registers into, or a Content event it listens to. Their number is kept small on purpose.
_Avoid_: Plugin API, hook (as the general word)

**Content event**:
A notice sent once a change to content, Media, Redirects, Menus or Submissions is saved, which site code may react to. It never alters or stops the change.
_Avoid_: Hook, filter, signal, observer

**Upgrade floor**:
The oldest Kami version a site must already run before it can update past a release that squashed Kami's migrations.
_Avoid_: Minimum version, baseline

### Sites and languages

**Site**:
One public website served by an installation, declared in code with its URL, its Locales and its display time zone. An installation serves one Site in the MVP; several later.
_Avoid_: Tenant, domain, channel

**Locale**:
A language a Site publishes in. A Site has one or more Locales and one default Locale; a Locale is never a Site of its own.
_Avoid_: Language site, translation site

**Display time zone**:
The time zone a Site shows dates in. Dates are always kept in UTC, except a `Date` Field, a calendar day that no zone converts.

**Admin language**:
The language the admin's interface is shown in to Users. It is not a Locale: a Site may publish in languages the admin is never shown in.
_Avoid_: Admin locale, interface locale

### Content

**Content Type**:
A kind of content declared in code by the developer: its fields, behaviour and whether it is routable, hierarchical or ordered by hand.
_Avoid_: Blueprint, collection, post type

**Entry**:
One piece of content of a Content Type, in all its Locales at once. It is the single thing that URLs, menus, references and revisions point at.
_Avoid_: Post, page, node, content item, record

**Translation**:
The part of an Entry that belongs to one Locale, with its own title, slug and publication. An Entry is visible in a Locale only through a published Translation there. A Translation is never an Entry of its own.
_Avoid_: Localised entry, origin

**Fallback**:
The value a blank Translatable field shows, taken from the Site's default Locale when that Translation is published.
_Avoid_: Default value (a Field's declared default is another thing)

**Field**:
One named value a Content Type declares for its Entries, such as a subtitle, a date or a gallery. Values every Entry has, such as the title, slug or status, are not Fields.
_Avoid_: Attribute, property, custom field

**Field type**:
The class a Field is made from, such as Text, Boolean or Terms. It fixes the Field's column, validation and default scope. Kami ships a set; a site may add its own.
_Avoid_: Field kind, widget

**Block**:
A reusable piece of non-text content, such as an image, a gallery or a video, that editors insert inside a rich text Field. Declared in code with its own Fields and view. Anything in rich text that is not formatted text or a link is a Block.
_Avoid_: Component, shortcode, widget

**Summary**:
A short plain text that stands for an Entry in listings and feeds. Each Content Type decides where it comes from; by default it is the start of the Entry's rich text, with Blocks left out.
_Avoid_: Excerpt (the field name some sites use), teaser, abstract

**Shared field**:
A Field with one value for the whole Entry, whatever the Locale.
_Avoid_: Untranslatable field, global field

**Translatable field**:
A Field with one value per Locale, held in each Translation.
_Avoid_: Localised field

**Content model drift**:
A mismatch between the Content Types and Fields declared in code and what the database stores. A deploy with drift fails until a migration resolves it.
_Avoid_: Schema drift (when meaning only this)

**Parent**:
The Entry another Entry sits under, within the same Content Type and Site.

**Position**:
An Entry's hand-set place among its siblings, for Content Types ordered by hand.
_Avoid_: Sort, weight, order

**Taxonomy**:
A Content Type whose Entries classify other Entries, such as categories or tags.
_Avoid_: Vocabulary

**Term**:
An Entry of a Taxonomy.
_Avoid_: Tag (as the general word), category (as the general word)

**Singleton**:
A Content Type that has exactly one Entry per Site, such as a home page.
_Avoid_: Single, page type

**Global Set**:
A Singleton with no URL, holding values shown across the Site, such as footer details. The Site's own editable settings are a Global Set.
_Avoid_: Globals, settings page, options

**Reference**:
A link from an Entry to another Entry or to a Media item, made through a field or inside rich text. References answer "what uses this?".
_Avoid_: Relation (for the stored link), pick, attachment

### URLs

**Path**:
The address of an Entry within a Site and one Locale, built from its Content Type's Prefix, its ancestors' slugs and its own slug. A Path that has been public keeps answering after it changes, as a Redirect.
_Avoid_: Permalink, route, slug (when meaning the whole address)

**Prefix**:
The fixed start, declared in code, of every Path of a Content Type, such as `articles`. A Singleton's Path is its Prefix.
_Avoid_: Base path, slug prefix

**Archive**:
The page listing the published Entries of a Content Type at its Prefix, or the Entries that reference a Term at that Term's Path.
_Avoid_: Index, listing, collection page

**Feed**:
An RSS address listing the latest published Entries of the Content Types that offer one, for the whole Site or for one Content Type, in one Locale.
_Avoid_: RSS (as the domain term), syndication

**Redirect**:
An address that sends visitors to an Entry or to another address, or tells them the content is gone for good. A Redirect to an Entry always leads to that Entry's current Path.
_Avoid_: Redirection, alias, forward

### Navigation

**Menu**:
A place in a Site's layout, such as the main navigation or the footer, declared in code by the developer with how deep it may nest, and filled by editors. One Menu holds one tree for all the Site's Locales.
_Avoid_: Navigation (as the domain term), menu location (as a second term)

**Menu item**:
One link in a Menu, to an Entry, an Archive or an address, possibly with Menu items under it. It is shown in a Locale only while what it leads to is visible there.
_Avoid_: Link, nav item, menu entry

**Trail**:
The Menu items leading to the current page: the item for the page itself, the items above it, and an item whose address the current page sits under.
_Avoid_: Active (as the general word), breadcrumb (which is a separate component)

### Search engines

**Meta title**:
The title search engines and shared links show for a Translation, when it should differ from the Translation's title.
_Avoid_: SEO title, page title

**Meta description**:
The short text search engines and shared links show for a Translation. Left blank, it is the Entry's Summary.
_Avoid_: SEO description, snippet

**Social image**:
The picture a shared link to an Entry shows, the same in every Locale.
_Avoid_: OG image, share image, featured image

**Hidden from search engines**:
Said of a Translation that search engines are asked not to list, though visitors still see it everywhere on the Site.
_Avoid_: Noindex (as the domain term), private

**Indexable**:
Said of an installation whose pages search engines may list. A staging copy or a site not yet launched is not indexable.
_Avoid_: Production (as a synonym), public

**Sitemap**:
The list of every address of a Site that search engines may list, with each one's addresses in the other Locales.
_Avoid_: Site map (as a page for visitors)

### Media

**Media**:
A file in a Site's library, with its descriptive metadata. A picture that needs its own page is an Entry that references a Media item, not a Media item alone.
_Avoid_: Asset, attachment, upload, file (as the domain term)

**Media Folder**:
A folder that groups Media in the library. A Media item sits in at most one folder, and moving it never changes its URL.

**Image Preset**:
A named way to show images, declared in code by the developer: an optional aspect ratio, the widths offered, and the default display sizes.
_Avoid_: Variant, size, style, transformation

**Conversion**:
One file derived from an image Media item for one Image Preset, at one width and in one format. Visitors only ever receive Conversions, never the original image.
_Avoid_: Variant, thumbnail (as the general word), derivative

**Focal point**:
The point of an image that must stay visible when an Image Preset crops it. It belongs to the Media item, whatever the Locale.
_Avoid_: Crop, hotspot

**Alt text**:
The text that replaces an image for people who cannot see it, written per Locale on the Media item. A decorative image intentionally has none.
_Avoid_: Alt tag, description, caption

### People

**User**:
A person who signs in to the admin. Users belong to the host application; Kami does not own them. People who only sign in to the host's own pages, such as a community's members, are not Users to Kami unless they hold a Role.
_Avoid_: Account, member

**Role**:
A named bundle of Permissions, declared in code by the developer and given to Users in the admin. A User may hold several Roles.
_Avoid_: Group, user type

**Permission**:
The right to perform one action, such as publishing Entries of one Content Type. Access is always checked against a Permission, never against a Role.
_Avoid_: Capability, ability (as the domain term)

**Creator** / **Last modifier**:
The User who created an Entry, and the User who last changed it. They record who did what; they are not shown to visitors.
_Avoid_: Owner, author (for this meaning)

**Audit log**:
The record of security-sensitive actions on an installation, such as sign-ins, Role changes, publications and deletions: who did what, and when. It is kept for a limited time and is not a history of content changes.
_Avoid_: Activity log, history, revisions

**Byline**:
The people credited publicly for an Entry. A Byline points at Entries of a Content Type the site declares (for example a Person), not at Users, so a credited person needs no account.
_Avoid_: Author (as a core concept)

### Scheduling

**Scheduled task**:
A piece of housekeeping that runs on a schedule, such as pruning stale Conversions. Nothing visitors see ever waits on one.
_Avoid_: Cron job, job (a job is a unit of queued work)

**Overdue**:
Said of a Scheduled task when one of its due times has passed since it last ran. Several missed due times make it overdue once, not several times.
_Avoid_: Due (which suggests the current minute must match)

**Tick**:
One pass of the scheduler: it runs every overdue Scheduled task, then works through queued jobs, within a time budget.
_Avoid_: Cron run, heartbeat, scheduler run

**Tick trigger**:
What starts a Tick: the server's cron, the hosting provider's call to a secret URL, or a visit to the site or the admin.
_Avoid_: Scheduler mode

### Publication

**Draft**:
A Translation that visitors cannot see, whatever its publication date. Unpublishing turns a Translation back into a Draft and keeps its date.
_Avoid_: Unpublished (as a state), hidden, private

**Published**:
Said of a Translation that visitors can see: an editor published it and its publication date has passed.
_Avoid_: Live, online, active

**Scheduled**:
Said of a Translation an editor published with a publication date still to come. It becomes Published at that moment by itself, with nothing run.
_Avoid_: Pending, queued

**Publication date**:
The moment a Translation becomes visible, also shown to visitors as its date.
_Avoid_: Post date, go-live date (as a second date)

**Preview**:
A Translation shown as visitors would see it, including changes not yet saved, to a User of the admin. Only the User who opened it can see it.
_Avoid_: Live preview (Kami's preview refreshes on demand)

**Preview link**:
A secret address, valid for a limited time and revocable, that shows the saved state of one Translation, Drafts and Scheduled ones included, to someone without an account.
_Avoid_: Share link, draft link

**Trash**:
Where a deleted Entry waits, in all its Locales, until someone restores it or deletes it for good. Visitors and reads see it as absent. The Trash is never emptied automatically.
_Avoid_: Bin, recycle bin, archive

### Forms

**Form**:
A set of inputs that visitors fill in and send to the site, such as a contact form, declared by the developer in code. Its markup belongs to the site's design.
_Avoid_: Form builder, web form

**Submission**:
What a visitor sent through a Form, kept by the site.
_Avoid_: Message, entry (an Entry is content), response

### Performance

**Page cache**:
Stored copies of public pages, served to visitors without building the page again. A copy is never served once something it might show has changed or become visible.
_Avoid_: Static cache, response cache, full-page cache

**Content version**:
The marker of the state of everything visitors might see, across all Sites of an installation. It changes on every edit, deploy and scheduled visibility change, and every cached page or fragment belongs to one version.
_Avoid_: Cache generation, cache key version, revision (a revision is a saved state of one Entry)

### Imports

**Import**:
A repeatable copy of another system's content into a Site. Running it again brings the Site in line with the source; until a site switches over, the source is the truth.
_Avoid_: Migration (a migration changes the database schema), sync

**Legacy id**:
The identifier an Entry or Media item had in the system it was imported from, always named together with its source.
_Avoid_: External id, import id, old id

**Switch-over**:
The moment a Site's public address starts being served by Kami instead of the system it was imported from. From then on Kami is the Site's source of truth and its Import is never run again.
_Avoid_: Go-live, cutover, launch
