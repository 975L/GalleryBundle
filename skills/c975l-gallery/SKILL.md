---
name: c975l-gallery
description: "Use this skill when working with photo galleries in a Symfony application built on the c975L ecosystem with c975l/gallery-bundle. Covers categories and medias, the admin-renamable url prefix, the two-step trash, batch upload and the three image derivatives, videos and embeds, the two gallery blocks, theming, and every extension point the bundle offers. Triggers on: GalleryCategory, GalleryMedia, gallery-route-prefix, gallery_index, gallery_category, gallery_media, gallery_categories, gallery_medias, c975l:gallery:rebuild-thumbnails, c975l:gallery:fill-slugs, photo gallery, thumbnail, lightbox, batch upload, upload progress, passe-partout, trash, restore, delete permanently, 410 Gone, download highres, download originals, GalleryMediaArchiver, move medias, move selection, GalleryMediaMover, moveMedias, files-gallery, health check, automatic gallery, latest additions, GalleryLatestProvider, findOrCreateAutomatic, findLatest, gallery-latest-days, gallery-latest-max, gallery-rating, likes, like a photo, heart, rating, findVisibleByCategories, setLoadedMedias, media caption, media description, GalleryCustomizationProviderInterface, gallery.customization_provider, GalleryDataField, getDataValue, gallery-video-self-hosted-max-height, self-hosted video, portrait video., GallerySampleCatalog, GalleryDemoFixtureProvider, DemoFixtureProviderInterface, demo gallery, ReplacingFile, hidden, hide a gallery, masking, sell prints, print shop, limited edition, editionSize, printable, certificate of authenticity, gallery_print_certificate, gallery_print_file, gallery-print-enabled, gallery-printable-max, gallery-print-provider, gallery-print-sandbox, gallery-print-signature, GalleryPrintFormat, GalleryPrintOrder, GalleryPrintCopy, PrintCopySnapshot, PrintFulfilmentInterface, ProdigiFulfilment, ManualFulfilment, gallery.print_fulfilment, AutomaticGalleryInterface, gallery.automatic_gallery, GalleryAutomaticProvider, GalleryPrintableProvider, automaticKind, qr code"
---

# c975L GalleryBundle

> Photo and video galleries on the c975L core — `GalleryCategory` → `GalleryMedia`, batch upload, automatic thumb/medium/highres derivatives, a public viewer, and two blocks so a gallery can be placed on any composed page.

**Package:** `c975l/gallery-bundle` · **Namespace:** `c975L\GalleryBundle\` · **Twig namespace:** `@c975LGallery` · **Translation domain:** `gallery`

**Key source paths** (relative to the package root):
`src/Controller/GalleryController.php`, `src/Contract/`, `src/Entity/`, `src/Field/`, `src/Model/`, `src/Repository/`, `src/Routing/GalleryRoutePrefix.php`, `src/Service/`, `src/Service/Fulfilment/`, `src/Twig/Extension/`, `src/Management/`, `src/Form/Block/`, `templates/gallery/`, `templates/print/`, `templates/components/Gallery/`, `templates/blocks/`, `config/configs.json`, `config/services.yaml`

**Related documentation:** this package's `README.md` is the exhaustive reference — every section named below is an anchor in it. The ecosystem's own rules (database-backed configuration, blocks, media library, management contributions) live in `c975l/core-bundle`.

## Quick start

```bash
composer require c975l/gallery-bundle
php bin/console doctrine:migrations:diff && php bin/console doctrine:migrations:migrate
php bin/console c975l:config:load-all      # seeds config/configs.json into the back office
php bin/console assets:install --symlink
php bin/console c975l:scaffold:install     # copies assets/styles/themes/gallery.css into the app
```

```yaml
# config/routes.yaml
c975_l_gallery:
    resource: "@c975LGalleryBundle/src/Controller/"
    type: attribute
    prefix: /
```

Nothing else is registered by hand. The Stimulus entrypoints (`assets/controllers.js` for the public
pages, `assets/controllers-admin.js` for the back office) are picked up by UiBundle's script registry,
and their `importmap.php` entries are written by `Management\ImportmapProvider` on the next
`composer update`.

`GalleryMedia::$user` is typed against `c975L\ConfigBundle\Contract\UserInterface`: the app's
`App\Entity\User` must implement it. The scaffolded `User` already does.

## The data model

There is **no gallery entity**. A site's galleries *are* its categories — `GalleryCategory` is the
top-level unit, and each holds its `GalleryMedia`.

- `GalleryCategory` — `slug` (the url segment), `title`, `summarySocialNetwork` (rich text, printed
  above the grid and reused as the page's description metas), `position`, `coverMedia`,
  `uncategorized` (the lazily-created catch-all), `automaticKind` (which automatic gallery it is, null
  for an ordinary one, see below), `hidden` (kept out of every public page without being deleted, see
  *Masking is not deleting*), `medias`. Implements `HasBlocksInterface` (its own editorial heading) and
  `TrashableInterface`.
- A site adds fields of its own to a category or a media by implementing
  `GalleryCustomizationProviderInterface` (`getCategoryDataFormType()`, `getMediaDataFormType()`),
  collected on sight of the interface through `gallery.customization_provider` — nothing to tag. They
  live in the `data` JSON column of each, are rendered by `GalleryDataField` on the edit screen, read
  back with `getDataValue()`, and travel through the export/import. Declaring nothing means no field.
- `GalleryMedia` — belongs to one category, keyed publicly by `slug` which is **unique within its
  category only**. Carries `title`, `data` (the site's own fields, see below), `description` (the caption printed under the photograph and reused
  as the page's description metas when set), `credits`, `rightsReserved`, `position`, `mediaType`
  (`image` / a platform name / `embed`, always derived, never set), `externalUrl`, an optional
  uploaded video file, and the Vich fields. Implements `TrashableInterface`,
  `VichMultiSizeImageInterface`, `VichMediaNamableInterface`, `VichOriginalKeepableInterface`,
  `VichWatermarkableInterface`.

## Deleting takes two steps

Both entities carry UiBundle's `TrashableTrait`, and **the delete button removes nothing**. It flags the
row: the category leaves the site, its medias, its heading blocks and every one of their files stay
exactly where they are — the cascade on the medias and `GalleryMediaDerivativeCleanupListener` are
simply never reached.

- The category index switches to what the trash holds with a `trash` query parameter, a category's edit
  screen does the same for its medias with `mediasTrash`. A media has a trash of its own, so a photo is
  taken off a gallery that stays online, and restoring a category gives back exactly the medias that
  were showing when it left.
- Only `GalleryCategoryCrudController::deletePermanently()` (and `deleteMediasPermanently()` for a
  selection) ever removes a row and reaches its files. Those two sit behind the `site-role-admin`
  setting; the rest of the gallery back office sits behind `site-role-editor`.
- A trashed category or media answers **410 Gone** on its public url, not 404, straight from the row.
  That 410 lasts only as long as the entity can be restored: the permanent deletion is what writes the
  "gone" `Redirect` rows that outlive it, and restoring releases any such row left under the url.
- Every listing query leaves the trash out — `findAllOrdered()`, `countVisible()`,
  `GalleryMediaRepository::findByCategory()`, `GalleryCategory::getMediasCount()` and
  `getCoverOrRandomMedia()`. `findOneBySlug()` and `findOneBySlugInCategory()` are deliberately
  unfiltered, the front-office needing the row in hand to answer 410.
- The export/import carries the flag both ways: a category archived out of the trash comes back to it.
- **The likes go with the permanent deletion, never with the trash.** A rating names its owner
  (`gallery_media` + id) rather than relating to it, so no foreign key cascades it: `deletePermanently()`,
  `deleteMediasPermanently()` and `GalleryImportProvider::import()` (which replaces a category's whole
  collection) each call `RatingRepository::deleteForOwners()` themselves, once for the whole set and only
  after the flush that actually removed the rows. A trashed media keeps its likes — it can come back.

## Masking is not deleting

Both `GalleryCategory` and `GalleryMedia` carry a `hidden` flag, the answer to what is worth keeping and
not worth showing — a gallery being prepared, one taken down for a season, a photograph withheld.

- A masked row stays whole in the back office: the category listing shows it with a switch saving on the
  spot, and its own edit screen fills, arranges and trashes its medias as usual.
- It answers **404**, never the trash's 410: masking is reversible, and nothing a change of mind would
  have to take back should be told to a crawler. A masked category takes its medias' pages down with it,
  those being resolved through their category (`GalleryController::resolveCategory()`).
- `findAllOrdered()` and `countVisible()` drop it, which is what takes a masked gallery off the index,
  out of the blocks, out of the sitemap and out of the menu targets — and off the back office's move
  targets and block pickers with them, a public page must not be composed on a url answering 404.
- Its photographs leave the automatic galleries too (`c.hidden = false` in `latestMedias()` and
  `findPrintable()`) and are not sold as prints (`GalleryPrintService::isPrintable()`, the basket being
  reached without ever going through the page that would have said so).
- Masking a category marks none of its medias, exactly as trashing one marks none of them — so showing
  it again gives back precisely what was showing before.
- The export/import carries both flags: a gallery archived masked comes back masked.

## Public routes and the renamable prefix

| Route name | Url | Renders |
| --- | --- | --- |
| `gallery_index` | `/{prefix}` | one thumbnail per category |
| `gallery_category` | `/{prefix}/{category}` | the category grid, photos and videos alike |
| `gallery_media` | `/{prefix}/{category}/{slug}` | the medium-resolution photo, or the video player |
| `gallery_print_certificate` | `/certificate/{certificate}` | the public check page of one numbered print |
| `gallery_print_file` | `/gallery-print-file/{copy}` | the print file, fetched by the lab through a signed url |

The last two sit **outside** the renamable prefix: one is printed on paper and has to outlive a rename,
the other is never read by a visitor.

The first segment is the `gallery-route-prefix` setting, renamed from the dashboard (`galerie`,
`fotos`) with **no cache to clear**. A route path is compiled into the router cache, so the prefix
cannot *be* the path: the three routes are declared as `/{gallery_prefix}/…` and each carries a
`condition` asking `Routing\GalleryRoutePrefix` whether the segment it was handed is the configured
one. `Listener\GalleryRoutePrefixListener` puts the same value in the router's request context, which
is where the generator takes the missing parameter from.

**Generate urls, never write them.** The route parameter is filled for you:

```twig
{{ path('gallery_index') }}
{{ path('gallery_category', { category: category.slug }) }}
{{ path('gallery_media', { category: category.slug, slug: media.slug }) }}
```

Route *names* never change, whatever the prefix. Renaming the prefix breaks the previously indexed
urls — declare a redirect in ConfigBundle's **Redirections** screen. Renaming a *category* is handled
for you: `GalleryCategoryCrudController::updateEntity()` writes the permanent redirect, plus a
wildcarded row for the medias underneath.

## The automatic galleries

A category carrying an `automaticKind` **holds no media of its own**: it is handed a list at read time.
There are two kinds out of the box — `GalleryCategory::AUTOMATIC_LATEST` (the last additions) and
`AUTOMATIC_PRINTABLE` (the photographs on sale as prints, only on a site that sells them). A kind is
written by `GalleryCategoryRepository::findOrCreateAutomatic()` the first time the galleries are listed
(the back-office listing, the public index or a categories block, whichever comes first) and is a normal
category from then on — renamed, described, given a heading, arranged, linked from a menu, declared in
the sitemap. **Nobody creates one, and it is never an option ticked on one of the site's own galleries.**
Moving it to the trash is how a site says it does not want it: unlike the catch-all, a trashed one is
left exactly where it was put.

**The plumbing is written once, the kinds only answer what they gather.** `Service\GalleryAutomaticProvider`
is the one place every screen asks (`ResetInterface`, so it reads once per request); each kind is an
`AutomaticGalleryInterface` — `getKind()`, `isAvailable()`, `getMedias()` — collected through
`gallery.automatic_gallery`. `Service\GalleryLatestProvider` and `Service\GalleryPrintableProvider` are the
two shipped. A kind answering `false` to `isAvailable()` never gets a row written, so a feature nobody
turned on leaves nothing behind.

`GalleryLatestProvider`'s list comes from `GalleryMediaRepository::findLatest($days, $max)`: a rolling
window of calendar days, today included, falling back on the last day that carries an addition when the
window catches nothing, so the gallery is never empty as long as the site holds a photo. The
`gallery_media_created_at` index on `created_at` is what keeps that read off a full scan.

`GalleryAutomaticProvider`'s own API:

- `getMedias(GalleryCategory $category)` — what that category's kind gathers.
- `ensureCategory(string $kind)` / `ensureCategories()` — the rows written for the kinds available.
- `findPreviousAndNext(GalleryMedia $media, GalleryCategory $category)` — the neighbours **within** the
  automatic gallery being browsed, which are not the ones the media's own category files it next to.
- `prepare(array $categories)` — hands back the listed categories with the automatic ones among them,
  holding their medias. For a caller reading the list itself (the public index, the categories block).
- `hydrate(iterable $categories)` — hands the automatic ones their lists, the others left alone. For a
  caller holding entities it did not read (the back-office listing, whose rows EasyAdmin paginates).
- Both also hand **every other listed category** the medias its tile and its media count read, in one
  `GalleryMediaRepository::findVisibleByCategories()` query for the whole list, posed through
  `GalleryCategory::setLoadedMedias()` — the relation is lazy, so a listing otherwise reads it category by
  category. A list of one is left alone, the lazy relation running the one query this would.
`GalleryLatestProvider::getMediasByDay()` cuts that same list into the days its medias were added on —
the back-office screen alone reads it, an upload session being what an admin credits or downloads in one go.

`GalleryCategory::getMediasCount()` and `getCoverOrRandomMedia()` read that handed list rather than the
relation, so a tile, a count and an `og:image` work without knowing which kind of category they hold —
the automatic one showing its **newest** photo rather than one at random.

**Urls stay canonical.** A thumbnail of the automatic gallery links its photo under **its own**
category, never a second path, with `?from=<automatic slug>` over it. `GalleryController::media()`
reads it: previous and next are then the medias added just before and just after, whatever category
they sit in, and the breadcrumb leads back to the last additions. A photo that has left the window is
browsed as its own category's again.

**Its back-office edit screen is the cross-gallery selection screen**: the same grid
(`templates/management/_gallery_media_tile.html.twig`) cut into one section per day, each thumbnail
naming the gallery the photo belongs to. Credits, rights, downloads and move to trash act on the photo
where it really sits, cover included. What the owning category alone carries is left out: no upload
button, no drag to reorder, no cover radio, no trash view. The media CRUD's category picker leaves out
the automatic and the trashed galleries.

## Showing a gallery outside its own routes

Two block kinds are contributed to UiBundle (declared in `config/services.yaml`), so a gallery goes on
any page composed in the back office:

| Kind | Shows | Options |
| --- | --- | --- |
| `gallery_categories` | every category, one thumbnail each | optional maximum |
| `gallery_medias` | one category's medias | category, optional maximum, random draw, link to the full category |

Both are `cacheable: false` and resolve their content live through
`Twig\Extension\GalleryBlockExtension`, so a block never goes stale against the media library. What a
block stores is *what* to show — a category **slug**, a maximum — never the medias themselves.

In a template of your own, the same Twig functions and components are available:

```twig
{# categories, optionally capped #}
{% for category in gallery_block_categories(6) %}…{% endfor %}

{# one category's medias: slug, max, random #}
{% set medias = gallery_block_medias('landscapes', 12, true) %}

{# the grids themselves #}
<twig:c975LGallery:Gallery:Categories categories="{{ categories }}"/>
<twig:c975LGallery:Gallery:Medias category="{{ category }}" medias="{{ medias }}" displayTitle="{{ true }}"/>
```

Anonymous components under `templates/components/Gallery/`: `Categories`, `Category`, `Medias`,
`Media`, `Navigation`, `Previous`, `Next`, `Lightbox`, `Video`, `Credits`.

## Images: what the bundle generates

Three derivatives per uploaded image, generated automatically by UiBundle's `VichImageResizeListener`
through the `VichMultiSizeImageInterface` contract — this bundle only declares the sizes:

| Derivative | Constant | Size | Used by |
| --- | --- | --- | --- |
| thumbnail | `GalleryMedia::THUMBNAIL_SIZE` | 600px longest side | both grids |
| medium | `GalleryMedia::MEDIUM_WIDTH` | 1024px | the media page, and the `og:image` a share carries |
| highres | `GalleryMedia::HIGHRES_WIDTH` | 2048px | the lightbox, fetched only on demand |

**The thumbnail file always holds the whole photo** — it is square only for a square photo. What the
grid does with it inside its square tile is the `gallery-thumbnail-whole` setting (`cover` off,
`contain` on): one CSS class, applied on the next request, reversible, nothing regenerated.

Originals can optionally be kept outside the document root (`GalleryMedia::ORIGINAL_DIRECTORY`).
Media files live under `GalleryMedia::MEDIA_DIRECTORY` (`medias/gallery`).

`Service\GalleryMediaArchiver` is what hands those two back: the medias checked on a category's edit
screen are downloaded as one zip, either their highres derivatives or the kept originals
(`GalleryCategoryCrudController::downloadMedias()`, `site-role-editor` like the rest of the screen).
Entries are named after each media's slug and stored rather than deflated, a selection past
`MAX_TOTAL_BYTES` is refused with its size rather than truncated, and a selection with no file at all
gives a message rather than an empty archive. **Do not write a file download of your own** for a
gallery's medias, and do not expose the original directory over http.

`Service\GalleryMediaMover` is the single place a media changes gallery, both ways going through it:
the **Move selection** button of the same toolbar (`GalleryCategoryCrudController::moveMedias()`) and
the category field of a media's own edit form (`GalleryMediaCrudController::updateEntity()`). It moves
the stored file, its `-thumb`/`-highres` siblings, the kept original and the media's own video into
`medias/gallery/{arrival gallery}/`, leaves the old media page redirecting to the new one, suffixes a
slug the arrival gallery already holds, appends the medias to its ranks while closing the gap behind
them, releases a cover pointing at a media that has left, and renumbers the titles when a title root is
given. The destination is chosen explicitly — nothing is preselected in that list, and its last entry
creates the gallery on the spot under the name typed beside it (a slug already taken is refused, never
suffixed, a category's slug being its natural key). **Only the directory moves** — the filename itself
carries the slug the media had at upload and never changes. The files are renamed in `postFlush` so a failed save leaves them where the rows still
point at them; the media is deliberately **left in the gallery's own collection**, that relation being
`orphanRemoval` and removing it there deleting the very row being moved. **Do not reassign
`GalleryMedia::$category` by hand** — the files would stay behind in the gallery the media left.

Both downloads are offered in the medias trash as well, and are the one selection action that does not
filter on `isDeleted` - the state is what keeps a selection posted from the grid away from the permanent
deletion, where reading a file is the same act either way. The category's own **Move to trash** is
rendered at the **foot of the edit screen**, not in the page toolbar (`page_actions` is overridden to
leave it out): next to Save, one row above the photographs being checked, it was clicked for a selection
and took the whole gallery off the site.

```bash
php bin/console c975l:gallery:rebuild-thumbnails [--dry-run]   # rewrites every -thumb.webp from the highres
php bin/console c975l:gallery:fill-slugs                       # backfills slugs on medias predating them
```

**Batch upload** ceilings are checked client-side before the request is sent, because PHP truncates an
oversized batch silently rather than refusing it: `Service\UploadLimits::MAX_FILES` (100) and
`MAX_FILE_SIZE` (20 MiB), each capped in turn by the running PHP's `max_file_uploads`,
`upload_max_filesize` and `post_max_size`. Both constants are there to be raised by an app that knows
its server takes more. The batch screen only ever creates images.

A batch is minutes of transfer then of processing, so the form is armed with UiBundle's
`UploadProgress`: it posts over `XMLHttpRequest`, counts the megabytes as they leave, then says the
files are being processed, the submit button taken away for the whole wait. The controller answers with
the arrival url instead of redirecting, so the "medias added" flash reaches the screen that follows.

## Videos

A media becomes a video by carrying **the url of the page the video is watched on** — nothing to
extract by hand. Whatever it carries, an entry always has its own uploaded still, which is what the
grids show, so one category holds photos and videos alike.

- **Which platforms is UiBundle's question**, not this bundle's: `c975L\UiBundle\Video\VideoPlatform`
  is where one is declared. YouTube, TikTok, Vimeo and Dailymotion ship declared. What gets stored is
  always the platform's privacy-first embed url (`youtube-nocookie.com`, `dnt=1`).
- An url belonging to no declared platform is stored as pasted, typed `embed`, framed 16/9. There is
  deliberately **no "paste your embed code" field** — third-party HTML in the database is an XSS and a
  CSP hole.
- A media can also carry an **uploaded video file** (mp4, webm, ogg), played by the browser with the
  still as its poster. A media carrying both plays its own copy. It is the one player whose shape
  nothing declares — the browser reads it off the file — so it is capped by
  `--gallery-video-self-hosted-max-height` (70vh), a height where a framed player's cap
  (`--gallery-video-portrait-max-width`) is a width.
- Players render through `<twig:c975LUi:Video:Iframe>`, created client-side only once the visitor has
  accepted the cookie banner.
- CSP: UiBundle exposes every declared origin as `%c975l_ui.video.embed_origins%`, for `frame-src`,
  `child-src` and any `Permissions-Policy` naming `fullscreen`.

## Likes on a photo

`gallery-rating`, on out of the box, prints UiBundle's rating widget under the media page's photo, asked
for one icon and one only:

```twig
<twig:c975LUi:Rating:Rating ownerType="gallery_media" ownerId="{{ media.id }}" scale="1" icon="heart"/>
```

A photo is liked or it is not, so there is no average to print — the line under it says how many people
liked it, and clicking the heart again takes the like back. `scale` and `icon` are stated here rather than
left to the site's own `ui-rating-icon` / `ui-rating-scale`, which serve the scales elsewhere. No login is
asked for: an authenticated visitor is keyed on their account, anyone else on a token their own browser
mints. The `rating` table comes with `c975l/core-bundle` `^1.13.1` — an app updating to it generates and
plays a migration, or every media page fails on an unknown table. See UiBundle's *Visitor ratings*.

## Configuration

Everything is in the database, declared in `config/configs.json`, group **gallery**, edited in
EasyAdmin — **never in `.env`, `parameters:` or a Configuration/TreeBuilder class**. Read it with
`ConfigServiceInterface::get('slug')` in PHP or `config('slug')` in Twig.

| Slug | Kind | What it settles |
| --- | --- | --- |
| `gallery-route-prefix` | text | the first url segment (empty falls back to `gallery`) |
| `gallery-thumbnail-whole` | bool | grid tiles `contain` instead of `cover` |
| `gallery-rating` | bool | the heart under a photo, on out of the box (see *Likes on a photo*) |
| `gallery-latest-days` | int | how many days back the automatic gallery reaches (empty or 0 falls back to 7) |
| `gallery-latest-max` | int | how many medias it stops at (empty or 0 falls back to 200) |
| `gallery-printable-max` | int | how many photographs the prints gallery stops at (empty or 0 falls back to 200) |
| `gallery-print-enabled` | bool | the print shop; off out of the box, and off it hides its two back-office screens and its tile (see *Selling prints*) |
| `gallery-print-provider` | text | which lab fulfils the orders (`prodigi` out of the box, `manual` to fulfil them by hand) |
| `gallery-print-api-key` | text, sensitive | the lab's api key |
| `gallery-print-sandbox` | bool | the lab's test mode, on out of the box - toggled from the dashboard tile |
| `gallery-print-signature` | text | the signature laid on print files, a path under `public/`; empty leaves prints unsigned |
| `gallery-style` | choice `light`/`dark` | the ground a photo is shown against; empty keeps the site's own colors |
| `gallery-frame` | choice `none`/`thin`/`wide` | the passe-partout around a displayed media |
| `theme-color-gallery-frame` | text | passe-partout color |
| `theme-color-gallery-nav` | text | arrows |
| `theme-color-gallery-nav-hover` | text | arrows, hovered |
| `theme-color-gallery-nav-background` | text | arrow buttons |
| `theme-color-gallery-backdrop` | text | lightbox backdrop |
| `theme-color-gallery-breadcrumb` | text | breadcrumb |
| `theme-color-gallery-credits` | text | credits line |
| `theme-color-gallery-badge` | text | video badge |
| `theme-color-gallery-badge-text` | text | video badge label |

A `theme-` slug is compiled into a `--c975l-color-gallery-*` custom property by UiBundle's
`ThemeVariablesCssListener`. Four of them are deliberately left empty, their fallback being an
expression that follows a light or a dark gallery rather than a fixed color.

The gallery back office sits behind ConfigBundle's `site-role-editor` setting, except the two permanent
deletions, held at `site-role-admin` (see *Deleting takes two steps*). The sidebar entry states that same
bar (`MenuProvider`, `'role'` key), so an editor reaches the galleries instead of seeing no entry at all —
read by `c975l/core-bundle` `^1.14.0` and up, earlier ones giving every entry the admin bar.

## Selling prints

Off unless `gallery-print-enabled` is on. Requires `c975l/payment-bundle`: a print is sold through the
one basket, this bundle plugging in as a `BasketItemProviderInterface` of kind `gallery_print`.

Three entities. `GalleryPrintFormat` is the catalogue — a size, its dpi, its price, and the **sku** the
lab knows it by (distinct from the slug; only the sku is ever sent to a lab). `GalleryPrintOrder` is one
consignment. `GalleryPrintCopy` is **the register**: one row per print.

`GalleryMedia` carries `printable` (on offer), `hidden` (kept out of every public page without being
deleted, filtered in the repository and 404 in the controller) and `editionSize` (null for an open
edition). Announcing an edition writes its rows at once through `GalleryPrintCopyRepository::openEdition()`,
and `GalleryMediaCrudController::settleEdition()` refuses every later change to the number — raising an
announced edition is a forgery. Selling one claims the lowest free row with a single
`UPDATE … WHERE order_id IS NULL`, so two checkouts on the last copy cannot both win.

A format is only offered for a photograph whose original actually has the pixels and the proportions
(±3 %, `GalleryPrintService::getOffers()`) — nothing is ever cropped to fit a size.

Everything a certificate states is frozen onto the copy at the sale (`PrintCopySnapshot`: format, label,
sku, price, title, credits, issuer). **Read nothing live when drawing a certificate** — the sheet is
signed by hand and posted, so a retitled photograph or a renamed site must not contradict it. The
certificate is a pdf carrying a qr code to its public page, `/certificate/{token}`, which sits outside
the renamable gallery prefix because it is printed on paper.

An open edition goes straight to the lab through Messenger. A limited one stops and waits: two e-mails
go out (the buyer's numbers, and the admin's "sign this"), and the admin releases it from the orders
screen.

A lab is a `PrintFulfilmentInterface`; every implementation is tagged on sight by `TaggedInterfacePass`,
so a site adding its own printer wires nothing. `ProdigiFulfilment` ships, `ManualFulfilment` throws on
purpose so the order stays pending in the back office rather than claiming it was sent.

The print file is composed from the untouched original, never from the web derivative, and the signature
is restamped at print resolution (`GalleryPrintFileBuilder`). The lab fetches it through a `UriSigner`
url expiring at seven days; an unsigned request gets 404, not 403.

## Extending and overriding

- **Templates** — every one of them is overridable from `templates/bundles/c975LGalleryBundle/`,
  mirroring the path under `templates/`. Public templates `{% extends 'layout.html.twig' %}` (no `@`),
  which the app's own file resolves.
- **Theme** — `assets/styles/themes/gallery.css` is copied into the app by `c975l:scaffold:install`
  and owned by it from then on. Every token ships commented out at the bundle's default: uncomment a
  line to take it over. Fonts and site colors are deliberately absent — the gallery reads UiBundle's
  `--text` / `--background` / `--font-family-body`, so it looks like the site it is installed on.
- **Blocks on a category** — `GalleryCategory` implements `HasBlocksInterface`; render them with
  `<twig:c975LUi:Blocks:Blocks blocks="{{ category.blocks }}"/>`.
- **Fields of the site's own** — implement `GalleryCustomizationProviderInterface` and return a form
  type from `getCategoryDataFormType()` / `getMediaDataFormType()`. Nothing to tag: the interface is
  collected through `gallery.customization_provider`, read by `GalleryCustomizationRegistry` (first
  provider answering wins) and rendered by `GalleryDataField` on the edit screen. The values land in
  the `data` JSON column, read back with `getDataValue()`.
- **An automatic gallery of the site's own** — implement `AutomaticGalleryInterface` (`getKind()`,
  `isAvailable()`, `getMedias()`). Nothing to tag: `TaggedInterfacePass` collects every implementation
  through `gallery.automatic_gallery`, and the category, its place on the index, its menu target and its
  sitemap line follow from `GalleryAutomaticProvider` alone.
- **A lab of the site's own** — implement `PrintFulfilmentInterface`, collected the same way through
  `gallery.print_fulfilment` and picked by the `gallery-print-provider` setting.
- **Repositories** — `GalleryCategoryRepository::findAllOrdered()`, `findOneBySlug()`,
  `countVisible()`, `findOrCreateUncategorized()`, `findOrCreateAutomatic()` (both suffixing a slug
  already taken); `GalleryMediaRepository::findByCategory()`, `findOneBySlugInCategory()`,
  `findPreviousAndNext()`, `findLatest()` (what the automatic gallery shows), `findVisibleByCategories()`
  (the same list for several categories at once, grouped by category id), `findWithFilename()`
  (the rows naming a file, for the files health check).
  `findAllOrdered()` is memoized for the request (`ResetInterface`), its callers knowing nothing of
  each other.

What the bundle already contributes to the dashboard, so you do not have to: `MenuProvider`,
`LinkableRouteProvider` (the index and each category offered as a SiteBundle menu target),
`GallerySitemapProvider`, `GalleryUrlMetadataProvider`, `GalleryFilesHealthCheckProvider` (kind
`files-gallery`, reporting every media whose image or self-hosted video is gone from the server),
`GalleryExportProvider` /
`GalleryImportProvider` (categories as a zip, files included), `GalleryBackupPathProvider`,
`GalleryBlockOwnerResolver`, `GalleryGuidedProjectProvider`, `WhatsNewProvider`, `ImportmapProvider`,
`Service\ScriptProvider`, `Service\StylesheetProvider`, `Service\GalleryShowcaseProvider`,
`GalleryShortcutProvider` (the tile toggling the lab's test mode), `GalleryDemoFixtureProvider`,
`Service\GalleryPrintBasketItemProvider` (PaymentBundle's basket, kind `gallery_print`),
`Email\GalleryEmailTemplateProvider`.

## Seeding a demo gallery

`GallerySampleCatalog` holds a made-up gallery once as plain data — three categories of four named
photographs. `GalleryShowcaseProvider` builds the arrays the block showcase renders from it,
`GalleryDemoFixtureProvider` (UiBundle's `DemoFixtureProviderInterface`) hands the lot over to whichever
demo site loads it - this bundle ships no command that writes to a database. Enriching the catalog shows
up in both, and everything a visitor reads is a key of the `gallery` domain.

The photographs come from `PlaceholderMediaProviderInterface`, rotated over the catalog and taken as a
temporary copy — an upload moves the file it is handed. A site declaring none is seeded with nothing at
all: a gallery is its photographs. A media's slug is set before the flush, being half of where its file
lands (see `GalleryMedia::getVichMediaPath()`).

Only the categories are yielded, their medias following through the cascade so Vich takes their files
and derivatives off the disk with them.

## Do not

- **Do not hardcode `/gallery`** in a template, a link or a test. The prefix is admin-editable; use
  `path('gallery_category', {category: slug})`.
- **Do not add a `.env` variable, a container parameter or a bundle Configuration class** for a
  gallery setting. It goes in `config/configs.json` and is read through `ConfigServiceInterface`.
- **Do not write an image resizer, a thumbnail command or a Vich naming rule.** Sizes are declared on
  the entity, the work belongs to UiBundle's `VichImageResizeListener`.
- **Do not read an automatic category's `medias` relation** — it is empty by definition. Ask
  `GalleryAutomaticProvider::getMedias($category)`, or go through `getMediasCount()` /
  `getCoverOrRandomMedia()`, which already do. And do not create a second category of a kind, nor turn
  one of the site's own galleries into one.
- **Do not put in `data`** anything the database has to filter, sort or join on, nor anything every
  gallery wants — the first stays a real column, the second belongs to the bundle (a caption did, hence
  `GalleryMedia::$description`).
- **Do not query a media by slug alone** — a slug is unique only within its category.
- **Do not raise or lower an announced edition's size.** `settleEdition()` refuses it: the rows are
  already written and a certificate already says "3 / 10". Open a new edition instead.
- **Do not read a photograph, a format or the site's name when drawing a certificate.** Everything the
  sheet states is frozen on the copy at the sale (`PrintCopySnapshot`) — a signed sheet cannot be allowed
  to disagree with the page checking it.
- **Do not claim a copy with a read-then-write.** `GalleryPrintCopyRepository::claimNumber()` is a single
  conditional `UPDATE` precisely so two checkouts on the last copy cannot both win.
- **Do not send the catalogue slug to a lab, nor the web derivative as a print file.** The frozen sku is
  what a lab knows, and `GalleryPrintFileBuilder` composes from the untouched original.
- **Do not hand VichUploader a plain `File`** when seeding a media — `UploadHandler::hasUploadedFile()`
  ignores it in silence, writing the row with no file name and nothing on the disk. Hand it a
  `ReplacingFile`.
- **Do not have a fixture provider empty a table** — a demo site keeps its own content in those very tables.
- **Do not list categories or medias with `findAll()` / `findBy()`.** Those see the trash; use
  `findAllOrdered()` and `GalleryMediaRepository::findByCategory()`, which do not.
- **Do not remove a media row without dropping its ratings.** Nothing cascades them; go through
  `RatingRepository::deleteForOwners('gallery_media', $ids)` after the flush (see *Deleting takes two steps*).
- **Do not call `remove()` on a category or a media**, and do not write a soft-delete flag of your own.
  Flag it through `TrashableInterface`; the permanent deletion is the back office's own action.
- **Do not add a video platform here.** Declare it in `c975L\UiBundle\Video\VideoPlatform`.
- **Do not render a third-party iframe directly.** Use `<twig:c975LUi:Video:Iframe>`, which honours the
  cookie banner.
- **Do not make the gallery blocks cacheable.** They resolve live on purpose; caching them is what
  makes a "latest photos" section go stale and freezes the random draw.
- **Do not add a page layout to this bundle.** A satellite never ships one; templates extend the app's
  `layout.html.twig`.
- **Do not build an import command for an existing photo folder.** There is no reliable way to tell
  originals from an old gallery's derivatives; photos already on another c975L site move across with
  the export/import instead.
