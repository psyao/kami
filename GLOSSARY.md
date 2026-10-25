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

### Sites and languages

**Site**:
One public website served by an installation, declared in code with its URL, its Locales and its display time zone. An installation serves one Site in the MVP; several later.
_Avoid_: Tenant, domain, channel

**Locale**:
A language a Site publishes in. A Site has one or more Locales and one default Locale; a Locale is never a Site of its own.
_Avoid_: Language site, translation site

**Display time zone**:
The time zone a Site shows dates in. Dates are always kept in UTC.

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
A person who signs in to the admin. Users belong to the host application; Kami does not own them.
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

**Byline**:
The people credited publicly for an Entry. A Byline points at Entries of a Content Type the site declares (for example a Person), not at Users, so a credited person needs no account.
_Avoid_: Author (as a core concept)
