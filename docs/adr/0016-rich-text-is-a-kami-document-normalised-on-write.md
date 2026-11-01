# Rich text is a Kami-owned TipTap document, normalised on write, and everything non-text is a Block

A `RichText` Field stores a TipTap/ProseMirror JSON document in its `json` column (ADR 0006). It is rendered to HTML at read time in the core, through `ueberdosis/tiptap-php` and Kami's own extension set. The node, mark and attribute names are Kami's, not Filament's: the Admin translates between Kami's document and Filament's `RichEditor` state (`->json()`) when it loads and saves the form. `SaveEntry` normalises every document against the Field's allowed set before storing it, whatever its source (form, import, local API). The document holds only formatted text and links. Anything else, images included, is a Block.

Security comes from structure, not from a purifier. A schema decides what can exist and the renderer only knows Kami's extensions, so forged editor state, an import and a direct API call all end up in the same clean document. The document stays the stable content format even when Filament changes (three Admin refreshes in one Velox minor, retrospective §1.1). This keeps Velox ADR 0004 (TipTap JSON rendered at read time) and Velox ADR 0014 (Blocks inside rich text, prose first). It departs from Velox in four ways: names are owned by Kami, documents are normalised on write, images are Blocks rather than nodes, and a Block that is no longer declared is preserved rather than dropped.

## Considered options

- **HTML plus a sanitiser.** Easy to read and to import. But security would rest on a purifier: Filament's own sanitiser lets inline `style` through. Internal links and Blocks would also become `data-*` attributes, parsed again on every render.
- **Markdown.** Portable, but Blocks would become shortcodes, which the glossary avoids, and the editor would no longer be WYSIWYG, a 10/10 MVP feature.
- **A block document of Kami's own.** It would mean writing and maintaining an editor.
- **Filament's stored format and `RichContentRenderer`.** Less code. But Filament would enter the core (ADR 0011), a Filament major could rename stored nodes, and the renderer trusts the HTML that custom blocks return.
- **Images as an `image` node** (Velox). Images could sit inside a paragraph and be edited in place. But that needs a TipTap JS extension and a JS build in the package for the Media picker and the caption. As a Block, an image reuses Block Fields, `kami_references` indexing and a view, with no JS.

## Consequences

- **Allowed set.**
  - By default: paragraph, headings H2 to H4, bold, italic, link, bullet and ordered lists, blockquote, horizontal rule and hard break.
  - `->code()` adds inline code and a code block with an optional `language` attribute. The server does no highlighting: `CodeBlockShiki` runs `node` through `proc_open` and is never registered.
  - `->headings([3, 4])` narrows the levels. There is never an H1, since the title is the page's H1.
  - Never offered: underline, colours, alignment, `style`, `target`, tables. Tables are a V1 door, as an extension or a Block.
- **Normalisation.**
  - An unknown node is unwrapped and its text kept as paragraphs.
  - An unknown mark is removed.
  - Attributes go through an allow-list.
  - A link keeps its `href` only when the scheme is `http`, `https`, `mailto` or `tel`, or when it is relative, an anchor or `kami://`. Otherwise the link is removed and its text stays.
  - Normalisation also wraps bare text in list items and quotes, as tiptap-php's parser leaves it inline (Velox P4).
  - A heading at a level the Field does not allow takes the nearest allowed one, so a pasted H1 stays a heading.
  - A document with no text and no Block is stored as `null`, so `required()` and the Fallback read it as blank. A horizontal rule alone counts as blank.
  - A stored document is rendered as stored: narrowing a Field reaches it at its next save.
- **Internal links** are ordinary `link` marks with `href = kami://entry/<ulid>` or `kami://media/<ulid>`.
  - The scheme is `kami://<kind>/<id>`, and a new kind is additive. A Term is an Entry, so it needs no kind of its own.
  - A link renders to the target's URL in the page's Locale. A target with no published Translation in that Locale, or in the Trash, renders as plain text, never as a broken link.
  - The Admin picks a target through a Filament editor tool backed by a PHP action modal, with no JS extension.
  - Links are indexed into `kami_references` per Field and Locale (ADR 0005).
  - A link pinned to another Locale is a V1 door, as a query parameter.
- **Block node.** `{"type": "block", "attrs": {"handle": "gallery", "data": {...}}}`.
  - `data` is keyed by Field handle, and relation Fields hold ULID lists.
  - Block Field rules run in `SaveEntry`, and `->required()` is checked at publication.
  - `{handle, data}` is the shape a V1 `Blocks` (page builder) Field will store as a list, with the same `Block` classes and views.
  - A `RichText` inside a Block is allowed but takes no `->blocks()`: nesting is a boot error (ADR 0007).
- **No silent loss of Blocks.**
  - A Block whose class is gone, or which its Field no longer offers, stays untouched in the document and renders nothing.
  - The Admin shows it as an unavailable Block, read-only, which the editor may only delete.
  - `kami:doctor` reports such Blocks by scanning documents from the CLI, in batches.
  - `ContentMigrator` gains `renameBlock`, `renameBlockField` and `dropBlock`.
- **Not inside a document, for now.** A `RichText` in a `Repeater` or a `Group` is a boot error, since `SaveEntry` normalises top-level Fields only. It is added when a site needs it.
- **Until M10.** M4 keeps Block nodes and `kami://` hrefs in stored documents, and offers neither `Block` classes, the link picker nor `kami_references` indexing. A `kami://` link renders as its plain text and a Block renders nothing.
- **A Block in the editor.** TipTap replaces a whole document it cannot read with an empty one, and a save would then erase it. So the Admin hands a Block to Filament's `RichEditor` as a `customBlock`, the one non-text node its schema always holds, with the Block's `{handle, data}` in `id` and no `config`. Filament then offers delete and no edit, and `SaveEntry` receives the `block` node back. The editor shows it as unavailable until M10 gives it a label and a summary.
- **Admin preview.** A Block's front view is never shown in the Admin, since the front's CSS is not loaded there. The preview shows the Block's label and its optional `summary(array $data): string`. The `Image` Block adds a thumbnail Conversion. Kami builds and escapes that HTML, since Filament trusts it.
- **Kami's Blocks**, each opt-in:
  - `Image`: a Media and a caption. Alt text comes from the Media (ADR 0017).
  - `Video`: YouTube and Vimeo, from the URL alone, with no network call on save or render. It renders a click-to-load facade: no request reaches the provider before the click, a link stands in without JS, and the iframe uses `youtube-nocookie.com` or `player.vimeo.com` with `dnt=1`. It trades a click for privacy (principle 2 over simplicity). A generic oEmbed `Embed` Block is a V1 door.
  - No direct upload from the editor (`attachFiles`): every file goes through the Media library.
- **Read side.** The cast returns `Psyao\Kami\Content\RichText\Document`, an `Htmlable`, even for a null value (Velox G56). It offers:
  - `toHtml()`;
  - `text()`, from text nodes only, with Blocks skipped;
  - `excerpt(int $chars = 160)`, cut at a word boundary;
  - `isEmpty()`;
  - `blocks(?string $handle)`.

  The Fallback's `lang` attribute (ADR 0013) goes on the wrapper. The core imposes no excerpt Field.
- **HTML import.** `Document::fromHtml(string $html, RichText $field)` is public. It parses, then normalises as above. It recognises `<kami-block handle="..." data='{json}'>` markers, `<pre><code class="language-x">` and `kami://` links. wpautop, shortcodes, Media creation and URL rewriting belong to the importer ("Replayable imports with legacy ids") or to site code, which emit that HTML.
- **Accessibility.** Saving shows non-blocking reminders for a skipped heading level, an empty heading and a link without text, the same way as missing alt text (ADR 0017).
- **Inline Markdown.** Text and Textarea Fields take `->inlineMarkdown()`. The value is stored as raw text and rendered by CommonMark's inlines-only extension (`html_input: strip`, `allow_unsafe_links: false`), not `Str::inlineMarkdown`, whose GitHub extension adds tables to the phrasing content a hero title needs. Only emphasis, strong, inline code and `http`, `https` or `mailto` links are kept, the last by a listener, as the option alone lets `tel`, `ftp` and relative links through; an image and any other link become their text. A Textarea newline becomes `<br>`. Text extraction strips the syntax. A fallen-back value renders in a `span` carrying `lang`. Such a Field is a boot error inside a `Repeater` or a `Group`, whose values are read as stored, and as a condition's target. The method exists on these two types only, so elsewhere the call is PHP's undefined method.
- **Shared hosting and server.** No difference. Rendering and normalisation are pure PHP, with no process, no network and no queue. `kami:doctor`'s Block scan runs from the CLI.
- **Cost.** Every render walks the document. The Admin maintains a translation layer between Kami's and Filament's node names. Inline images are not possible. This trades simplicity (principle 6) for security (principle 1) and stability (principle 5).
