# UPGRADE

## Unreleased

### A video is an url now, and no longer YouTube or TikTok only

`GalleryMedia` carried a `mediaType` picked from a list of three and an `externalId` typed next to it,
with the embed urls of the two platforms hardcoded in the entity. It carries **one url**, and the
platform reads itself off it — through `c975L\UiBundle\Video\VideoPlatform`, UiBundle's own registry,
which is where a platform is declared once for the whole ecosystem.

| Before | After |
| --- | --- |
| `GalleryMedia::MEDIA_TYPES` | `GalleryMedia::mediaTypes()` |
| `GalleryMedia::MEDIA_TYPE_YOUTUBE`, `…_TIKTOK` | `VideoPlatform::Youtube`, `…::Tiktok` (and `Vimeo`, `Dailymotion`) |
| `getExternalId()/setExternalId()` | `getExternalUrl()/setExternalUrl()` |
| `setMediaType()` | *(gone — the type is derived from the url)* |
| export key `externalId` | `externalUrl` |
| `label.gallery_external_id(_help)` | `label.gallery_external_url(_help)` |

What this buys: **Vimeo and Dailymotion out of the box**, and any player nobody declared — a PeerTube
instance of one's own among them — stored as pasted under the `embed` type rather than refused.

An admin pastes the address of the page the video is watched on; what gets stored is that platform's
own privacy-first embed url (`youtube-nocookie.com`, Vimeo's `dnt=1`), normalized once on the way in.
`setMediaType()` is gone on purpose: a type and an url that could be set apart were a pair free to
contradict each other, and half-declared videos were a case to carry everywhere.

**The urls are rebuilt from the ids, then the column goes.** `doctrine:migrations:diff` writes the
`ADD`/`DROP` pair on its own, but not the `UPDATE`s between them — add those to the generated migration
before running it, or every video becomes a still:

```sql
ALTER TABLE gallery_media ADD external_url VARCHAR(500) DEFAULT NULL;

UPDATE gallery_media SET external_url = CONCAT('https://www.youtube-nocookie.com/embed/', external_id)
    WHERE media_type = 'youtube' AND external_id IS NOT NULL AND external_id <> '';
UPDATE gallery_media SET external_url = CONCAT('https://www.tiktok.com/embed/v2/', external_id)
    WHERE media_type = 'tiktok' AND external_id IS NOT NULL AND external_id <> '';

-- A half-declared video (a type with no id) goes back to being what it already displayed: a still
UPDATE gallery_media SET media_type = 'image' WHERE external_url IS NULL;

ALTER TABLE gallery_media DROP COLUMN external_id;
```

An archive exported before this rework still imports: `GalleryImportProvider` rebuilds the url from the
`mediaType`/`externalId` pair it carries.

### A media can carry a video of the site's own

Next to the url, a `GalleryMedia` now takes an **uploaded video file** (mp4/webm/ogg) — played by the
browser itself through UiBundle's `<twig:c975LUi:Video:Video>`, with the still the entry already carries
as its poster. No third party, so nothing to consent to and no CSP origin to allow, and a video that
outlives whatever a platform decides. What it costs is the storage and the bandwidth.

The file **wins over a pasted url** when a media carries both (`GalleryMedia::refreshMediaType()`): the
copy that outlives the platform is the one to play, and the url stays there to fall back on if the file
is removed. `isSelfHostedVideo()` tells the two apart; `getEmbedUrl()` returns `null` for a self-hosted
one, there being nothing to frame.

The ceiling is **php's own `upload_max_filesize`**, not this bundle's 20 MiB one — that ceiling exists to
keep a batch of photographs from taking a shared host down, and would refuse any video worth uploading
(`UploadLimits::getMaxVideoFileSize()`). On a managed host it is what the hosting sets, and a video over
it has to reach the server some other way.

Three columns, which `doctrine:migrations:diff` writes on its own:

```sql
ALTER TABLE gallery_media
    ADD video_filename VARCHAR(255) DEFAULT NULL,
    ADD video_size INT DEFAULT NULL,
    ADD video_mime_type VARCHAR(100) DEFAULT NULL;
```

Nothing to migrate: no media carried a file of its own until now. Export and import carry it
(`videoFile`), so a round-trip no longer loses the one file nothing could get back from elsewhere.

**One fix in UiBundle comes with it.** `VichImageResizeListener` fires once per Vich field but its
branches answer for the entity as a whole, so a second file next to an image used to be copied aside as
an "original", measured, and handed to a resizer that cannot read it. It now leaves a file that is not
an image alone — read off the file's own bytes, so an SVG bound for an icon role is unaffected.

### Third-party players are now behind the site's consent gate

The bundle framed its `<iframe>` itself, on the strength of YouTube's and TikTok's embeds being
cookie-free until playback. UiBundle has meanwhile put **every** third-party frame behind a consent
placeholder (`<twig:c975LUi:Video:Iframe>`, see its `video-iframe` controller), and the gallery had the
ecosystem's only exception to it. It no longer does — `Gallery:Video` renders that component, so a
gallery's players follow the site's own cookie banner, and are only ever created client-side once the
visitor accepted. **A site carrying no consent banner is unaffected**: the controller renders the player
straight away there, which is the behaviour a gallery had until now.

A site that wants its gallery to keep framing players unconditionally has to say so at the banner level,
there being no per-bundle opt-out — the point of the change being that there is one policy, not two.

### The CSP origins come from the registry

`frame-src` had to name `www.youtube-nocookie.com` and `www.tiktok.com` by hand, copied out of this
README. UiBundle exposes every declared platform's origin as a container parameter, so the policy
follows the registry:

```yaml
# config/packages/nelmio_security.yaml
nelmio_security:
    csp:
        enforce:
            frame-src: ['self', '%c975l_ui.video.embed_origins%']
            # The level 1 fallback, for browsers that don't know frame-src
            child-src: ['self', '%c975l_ui.video.embed_origins%']
```

A `Permissions-Policy` header restricting `fullscreen` still has to name those origins, or the player's
fullscreen button does nothing.

### The player's shape is a token per platform

`--gallery-video-ratio-youtube` and `--gallery-video-ratio-tiktok` are joined by
`--gallery-video-ratio-vimeo`, `--gallery-video-ratio-dailymotion` and `--gallery-video-ratio-default`
(what a player from an undeclared platform is framed in). A site that took the two over in its own
`themes/gallery.css` keeps them; the new ones follow the bundle until it takes them over too.

### Each gallery can be linked from a menu

`Management\LinkableRouteProvider` offers the gallery index **and one entry per category** to SiteBundle's
menus, where nothing of this bundle showed up before. **Nothing to run**: the entries appear in the target
select of **Menus** on their own, and no existing menu item changes.

A category entry names the route and the parameter its url is built from, which needs `c975l/core-bundle`
^1.4 carrying that contract and the SiteBundle release reading it — an older SiteBundle would list the
entry in its picker and then fail to generate its url on the front end.

### The watermark is no longer stored on a media

`GalleryMedia::$watermarked` and `$watermarkPosition` were columns, and answered a question that only
ever comes up while a file is being stored: UiBundle's `VichImageResizeListener` reads them at upload
and burns the signature into the pixels, after which nothing reads them again. They are plain properties
now, like `keepOriginal`, and the media's edit screen asks the pair again so a **replaced file** can be
signed — a replacement being an upload of its own.

`setWatermarked()` is now `setWatermark()`; `wantsWatermark()` and `getWatermarkPosition()/setWatermarkPosition()`
are unchanged.

The two columns are dead weight and can go:

```sql
ALTER TABLE gallery_media DROP COLUMN watermarked, DROP COLUMN watermark_position;
```

Nothing to do about the medias themselves: their files carry whatever signature was stamped into them.

### `GalleryPhoto` is now `GalleryMedia`, and every "photo" with it

An entry has been a photo, a YouTube video or a TikTok since v0.2, while everything naming it still said
*photo*. It is a **media** from now on, front to back — one rename now rather than a wider one once the
video side is really used.

| Before | After |
| --- | --- |
| `Entity\GalleryPhoto` | `Entity\GalleryMedia` |
| `Repository\GalleryPhotoRepository` | `Repository\GalleryMediaRepository` |
| `Controller\Management\GalleryPhotoCrudController` | `Controller\Management\GalleryMediaCrudController` |
| `Controller\Management\GalleryPhotoUploadController` | `Controller\Management\GalleryMediaUploadController` |
| `Form\GalleryPhotoBatchUploadType` | `Form\GalleryMediaBatchUploadType` |
| `Form\Block\GalleryPhotosBlockType` | `Form\Block\GalleryMediasBlockType` |
| `Listener\GalleryPhotoUserListener`, `…DerivativeCleanupListener` | `Listener\GalleryMediaUserListener`, `…DerivativeCleanupListener` |
| `GalleryCategory::getPhotos()/addPhoto()/removePhoto()/getPhotosCount()/getCoverPhoto()/setCoverPhoto()` | `getMedias()/addMedia()/removeMedia()/getMediasCount()/getCoverMedia()/setCoverMedia()` |
| route `gallery_photo` | route `gallery_media` |
| block kind `gallery_photos` | block kind `gallery_medias` |
| `gallery_block_photos()` | `gallery_block_medias()` |
| `<twig:c975LGallery:Gallery:Photo>`, `…:Photos>` | `<twig:c975LGallery:Gallery:Media>`, `…:Medias>` |
| `gallery/photo.html.twig`, `blocks/Photos.html.twig`, `management/gallery_photo_{edit,upload}.html.twig` | `gallery/media.html.twig`, `blocks/Medias.html.twig`, `management/gallery_media_{edit,upload}.html.twig` |
| export keys `photos`, `coverPhotoIndex` | `medias`, `coverMediaIndex` |
| `label.gallery_photo(s)`, `label.block_gallery_photos`, `label.block_max_photos`, `label.gallery_all_photos`, `label.gallery_{next,previous}_photo`, `label.gallery_upload_photos(_to)`, `label.gallery_photos_uploaded`, `label.info_gallery_photo`, `label.info_gallery_category_photos`, `label.gallery_showcase_photos(_description)`, `label.gallery_showcase_photo_alt` | the same keys with `media`/`medias` |

**The table is renamed, not recreated.** `doctrine:migrations:diff` sees a table gone and a table
appeared, and would write a `DROP`/`CREATE` pair that throws the medias away. Write the migration by
hand first, then diff to catch whatever is left:

```sql
RENAME TABLE gallery_photo TO gallery_media;
ALTER TABLE gallery_category CHANGE cover_photo_id cover_media_id INT DEFAULT NULL;
```

**Blocks already placed on a page carry the old kind**, which no longer resolves to anything — they
render as nothing until the stored value follows:

```sql
UPDATE site_block SET kind = 'gallery_medias' WHERE kind = 'gallery_photos';
```

Nothing to do about the files: `GalleryMedia::getVichMediaPath()` names new uploads
`medias/gallery/{category}/media-*.webp` where it named them `photo-*.webp`, and an already stored file
keeps the name its `filename` column points at.

An app calling `path('gallery_photo', …)`, rendering one of the renamed components or overriding one of
the renamed templates has to follow the table above; an archive exported before the rename still imports,
its `photos`/`coverPhotoIndex` keys being read as a fallback.

The stylesheet and the Stimulus controllers follow the same rename:

| Before | After |
| --- | --- |
| `--gallery-photo-max-width` | `--gallery-media-max-width` |
| `--gallery-photo-frame-width`, `--gallery-photo-frame-color` | `--gallery-media-frame-width`, `--gallery-media-frame-color` |
| `.photo-container`, `.photo-display`, `.photo-zoom` | `.gallery-media-container`, `.gallery-media-display`, `.gallery-media-zoom` |
| `.gallery-photo-nav`, `--previous`/`--next` | `.gallery-media-nav`, `--previous`/`--next` |
| `assets/js/gallery-photo-preload.js`, `…-protect.js` | `assets/js/gallery-media-preload.js`, `…-protect.js` |
| controllers `gallery-photo-preload`, `gallery-photo-protect` | `gallery-media-preload`, `gallery-media-protect` |

A site that had taken one of the three tokens over in its own `assets/styles/themes/gallery.css` renames
it there — `c975l:scaffold:install` never touches a file the app owns, so an old name would simply stop
being read. The entrypoints (`controllers.js`, `controllers-admin.js`) don't change, so `importmap.php`
has nothing to update.

### A media has a title and a slug, and its url is built on the slug

`GalleryMedia::$alt` becomes `$title`, and a `$slug` built from it joins it. The title is the media's name,
its `alt` text and what the slug is built from, all at once — one field rather than three an admin would
have to keep in step. The slug replaces the id in the public url, which an image search shows under the
result: `/{prefix}/{category}/col-du-galibier` where it read `/{prefix}/{category}/42`.

| Before | After |
| --- | --- |
| `GalleryMedia::getAlt()/setAlt()` | `getTitle()/setTitle()`, plus `getSlug()/setSlug()` |
| route `gallery_media`, parameter `id` | parameter `slug` |
| export key `alt` | `title`, plus `slug` |
| `label.alt_text` | `label.title` (already shipped), plus `label.gallery_media_title_help` and `label.gallery_media_slug_help` |
| `label.gallery_showcase_media_alt` | `label.gallery_showcase_media_title` |

**The column is renamed, not recreated**, same reason as the table above — `doctrine:migrations:diff`
would write a `DROP`/`ADD` pair and throw every alt text away:

```sql
ALTER TABLE gallery_media CHANGE alt title VARCHAR(255) DEFAULT NULL;
ALTER TABLE gallery_media ADD slug VARCHAR(255) DEFAULT NULL;
CREATE UNIQUE INDEX gallery_media_category_slug ON gallery_media (category_id, slug);
```

**Then fill the slugs** — a media without one has no url at all and simply 404s. Nothing in SQL slugifies,
so a command does it, building each slug from the title and suffixing what collides within its category
(the index tolerates the rows still holding `NULL` in the meantime):

```bash
php bin/console c975l:gallery:fill-slugs --dry-run
php bin/console c975l:gallery:fill-slugs
```

It leaves alone any media that already carries a slug, so running it twice changes nothing.

**Stored files are not renamed.** New uploads are named after the media's slug
(`medias/gallery/{category}/{media}-{uniqid}.webp` where they read `…/media-{uniqid}.webp`), and an
already stored file keeps the name its `filename` column points at — renaming would mean moving three
files each (medium, thumbnail, high resolution) and costing the old urls their place in an image index,
for a signal the `alt` text already carries.

Retitling a media, or moving it to another category, moves its url and writes a permanent redirect to the
new one (ConfigBundle's **Redirections**), as a renamed category already did. A renamed category now also
writes a wildcarded row for the media urls under it (`/{prefix}/{old-slug}/*`), which used to be left to
404. An app rendering `<twig:c975LGallery:Gallery:Media>`, `…:Previous>` or `…:Next>` itself, or calling
`path('gallery_media', …)`, passes `slug` instead of `id`; one reading `media.alt` in an overridden
template reads `media.title`. An archive exported before this change still imports, its `alt` key being
read as a fallback and a slug built from it.

The media edit form gained an editable **Slug** field. What is typed into it is normalized and made
unique within the category; emptied, it is rebuilt from the title. Because the change moves a public url,
it goes through UiBundle's `title-confirm` modal before being saved.

### The `gallery_medias` block can draw its medias at random

The block gained a **Draw them at random** checkbox next to its maximum: ticked, the maximum keeps that
many medias out of the whole category instead of its first ones, drawn again at every render (the block
is uncached). An existing block keeps its stored order, the option defaulting to off.

### The back office opens on the categories, and the all-photos listing is gone

A site's galleries are its categories, so that is what the **Gallery** menu entry now opens —
`GalleryCategoryCrudController`, mounted on `/management/gallery` instead of the
`/management/gallery-category` its class name gave — with a **Medias** column counting what each holds.
The screen that used to be there, `/management/gallery-photo`, listed every photo of every category at
once: useful to no one past a few hundred photos, and it is where a category's photos actually belong.

Each category's photos are now listed under its own edit form, each thumbnail opening the photo it
stands for — that is where a photo's file is replaced, its alt text, credits, type or position edited,
and where its own **Delete** button now sits. `GalleryMediaCrudController` keeps its edit screen and
nothing else: `index()` redirects to the category the photo was reached from (or to the category listing
when reached by hand), its category filter and its `manageCategories` toolbar link are gone with the
listing they served, and `templates/management/gallery_photo_index.html.twig` no longer exists — an app
overriding it can simply drop its copy.

The upload screen moves with them, from `/management/gallery/upload` to `/management/gallery-upload`:
the category CRUD now owns `/management/gallery/{id}`, which would have read `upload` as a category id.
It still takes its `?category=` parameter, and now returns to that category's edit screen — where the
photos just uploaded are listed — instead of the filtered photo listing.

Nothing needs to be run: the admin routes are rebuilt with the container. Bookmarks and hard-coded links
to `/management/gallery-category` or `/management/gallery-photo` need updating, and an app that named
the photo CRUD in a menu of its own should name `GalleryCategoryCrudController` instead.
`label.gallery_manage_categories` is gone with the link it labelled, `label.info_gallery_media` now
introduces the media edit screen rather than the listing, and `label.info_gallery_category_medias` is
new — an app overriding any of them in its own `gallery.<locale>.xlf` should follow.

### The public routes move from `/photos` to `/gallery`

`/photos/{category}` read badly on a site whose categories are themselves named `photos`, `videos` or
`tiktoks` — `/photos/photos`. The first segment now names the feature, and is configurable rather than
hard-coded, so a site can serve it in its own language. It is the new **Gallery url prefix** setting
(`gallery-route-prefix`), in a **Gallery** group of ConfigBundle's **Configuration** screen:

```bash
php bin/console c975l:config:load-all
```

Renaming it there applies on the very next request, nothing to clear: the three routes are declared as
`/{gallery_prefix}/…` and check the segment they were handed against the setting (`Routing\GalleryRoutePrefix`,
the routes' condition), while `Listener\GalleryRoutePrefixListener` feeds the same value to the url
generator through the router's request context. Leading and trailing slashes are ignored, and an empty
value falls back to `gallery` rather than mounting the category route at the site root. To keep the
previous urls, set the value to `photos`.

Two consequences worth knowing. The bundle now requires **`symfony/expression-language`**, which route
conditions are evaluated with — `composer update` pulls it in. And a template passing `{gallery_prefix}`
to `path()` would override the configured value: nothing needs to, the generator fills it on its own, but
url generation outside a request (a command of your own) has an empty context and will ask for it.

The route names don't change here (`gallery_photo` becomes `gallery_media` in the rename section above,
not in this one), so nothing generated through `path()` needs touching for the prefix. Anything pointing at `/photos` by hand does — a hard-coded menu entry, a
link in a page's content — and search engines need a redirect if the urls were indexed. ConfigBundle's
**Redirections** screen takes one, `/photos` → `/gallery`, without touching a file.

`GallerySitemapProvider` follows the configured prefix on its own, so re-running
`php bin/console c975l:sitemaps:create` is all the sitemap needs.

### The EasyAdmin menu entry is labelled "Gallery"

The sidebar entry used `label.gallery_photos` ("Photos"), the photo CRUD's own entity label, while it opens
a screen holding videos as well. It now uses the new `label.gallery` key ("Galerie"/"Gallery"/"Galería"). An
app overriding the entry's wording in its own `gallery.<locale>.xlf` should move that translation over.

### The `Gallery` entity is gone, the category is now the top level

A site's galleries are its categories. The container above them was never exposed in the back office,
served a single row (`main`), and every public route, block and sitemap entry already resolved against
the default one — so it decided nothing while costing a join, a `default` flag to keep singleton, and a
segment in every stored file path.

`GalleryCategory::getGallery()/setGallery()`, `Repository\GalleryRepository` and `Entity\Gallery` no
longer exist. `GalleryCategoryRepository::findOneBySlug()` takes the slug alone, and
`findOrCreateUncategorized()` takes no argument at all. A category slug is now unique site-wide rather
than within its gallery (two titles slugifying identically still get a numeric suffix).

The `gallery` table and `gallery_category.gallery_id` are dropped:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

**Move the photo files before running it.** `GalleryMedia::getVichMediaPath()` no longer carries the
gallery slug, so what used to live under `public/medias/gallery/main/{category}/photo/` is read from
`public/medias/gallery/{category}/photo/` — the directories move up one level, derivatives included:

```bash
mv public/medias/gallery/main/* public/medias/gallery/
rmdir public/medias/gallery/main
```

(`main` is the slug `findOrCreateDefault()` used; check yours against the `gallery` table before
dropping it.) A file left behind isn't lost, but its photo renders broken until it is moved.

An export produced by an earlier version carries `gallerySlug`/`galleryTitle`/`galleryPosition`/
`galleryDefault` on each item: they are simply ignored on import, the category being matched on its own
slug, so old archives still import.

### Photos are added from their category, and the whole batch at once

The photo index's global **Add photos** button is gone: `GalleryMediaCrudController` never had a category
to attach an upload to. The category carries the action instead — on its row in the index, and among the
buttons of its edit form — and
`GalleryMediaUploadController::upload()` now **requires** its `?category=` parameter — reached without
one, it 404s rather than falling back to "Non classé".

The screen itself is a single multiple file input: pick the whole folder at once, credits and
rights-reserved applying to the batch and retouched photo by photo afterwards in the photo edit screen.
`Form\GalleryPhotoUploadRowType` is removed with the per-row fields it held (alt, video id), and
`GalleryMediaBatchUploadType` no longer takes a `gallery` option nor offers a media type — it requires a
`category_title` string, which it shows disabled. Each photo's alt text is seeded from its original
filename.

An app overriding `templates/bundles/c975LGalleryBundle/management/gallery_media_upload.html.twig`
renders `form.photos`, which no longer exists: rebase it on the shipped one (`form.category`,
`form.files`, `form.credits`, `form.rightsReserved`).

### The upload screen checks a batch before sending it

PHP does not refuse an oversized batch, it truncates it: past `max_file_uploads` the extra files are
dropped without a word, past `post_max_size` the request arrives empty. `Service\UploadLimits` reads
those settings from the running PHP and the screen now states them, refuses a selection that exceeds
them, and reports a batch that got through anyway.

That check is this bundle's **first EasyAdmin-side Stimulus controller**, so it needs an
`importmap.php` entry that earlier versions did not:

```bash
composer update c975l/gallery-bundle
php bin/console c975l:config:check-importmap
```

`Management\ImportmapProvider` declares it and the command writes it in; until it has run, the entry is
missing and the check simply never connects — the screen keeps working, PHP keeps truncating. An app
overriding `templates/bundles/c975LGalleryBundle/management/gallery_media_upload.html.twig` keeps the
ceilings (they are declared on the form tag by `GalleryMediaBatchUploadType`) but loses the message and
the disabled button until it adds the two targets:

```twig
<div class="alert alert-danger" data-gallery-upload-limits-target="message" role="alert" hidden></div>
<button type="submit" data-gallery-upload-limits-target="submit">…</button>
```

The per-file ceiling moved from `'10M'` to `UploadLimits::MAX_FILE_SIZE`, 20 MB **stated in bytes**:
Symfony's `File` constraint reads `'20M'` as 20,000,000 where `php.ini` reads it as 20,971,520, and the
screen would have advertised a megabyte the validator refuses.

### `c975l:gallery:import-legacy` is removed

The command migrated a folder tree of photos into this bundle's entities. It could not tell an original
from an old gallery's own derivative — `-small`, `-thumb`, a `thumbs/` subfolder, any convention the
source site happened to use — so it imported blurry duplicates as readily as photos, and no filter fixes
that from one site to the next. Its readme section is replaced by the back-office walkthrough, which is
what it amounted to anyway: create the category, select the whole folder in **Add photos**, repeat.

A site that still has a legacy tree to bring in and wants the old behaviour keeps a copy of the command
in its own `src/Command/` — it is a self-contained `Command` reading `GalleryCategoryRepository` and
`GalleryMediaRepository`, with nothing of this bundle's internals in it. Upload the originals, never the
derivatives: a source narrower than 1024px caps all three generated sizes for good.

### The high-resolution page is gone, replaced by a lightbox

`/photos/{category}/{id}/hr` and the `gallery_photo_hr` route no longer exist, and neither does
`gallery/photo_hr.html.twig`. A visitor now browses a category in the stored (medium) resolution only,
and the high resolution opens in a lightbox over the photo, fetched the first time it is asked for.
Two navigations became one.

Nothing to run, but the removed urls return a 404: **add a redirection** if yours were linked to or
indexed, `/photos/{category}/{id}/hr` → the category — a media is now addressed by its slug, and the old
numeric id is no longer resolvable into one, so the category page is the closest url still reachable. In
an app's own routing:

```yaml
gallery_photo_hr_gone:
    path: /photos/{category}/{id}/hr
    controller: Symfony\Bundle\FrameworkBundle\Controller\RedirectController::redirectAction
    defaults:
        route: gallery_category
        permanent: true
        # Dropped rather than trailed along as a query string, the target knowing nothing of it
        ignoreAttributes: ['id']
```

An app carrying its own `templates/bundles/c975LGalleryBundle/gallery/media.html.twig` keeps rendering
what it did — including its now-dead link to `gallery_photo_hr`, which throws on a missing route. Rebase
it on the shipped one: the component `<twig:c975LGallery:Gallery:Lightbox media="{{ media }}"/>` renders
the photo and its lightbox, the `gallery-media-container` div carrying the `gallery-lightbox` controller.

An overridden `gallery/photo_hr.html.twig` is simply never rendered again and can be deleted.

### The breadcrumb's `resolution` parameter is now `current`

`<twig:c975LGallery:Gallery:Navigation>` took a `resolution`, always filled; it takes an optional
`current` instead, only carrying what the breadcrumb couldn't say on its own — a video entry. With one
resolution to browse in, naming it said nothing. The CSS class follows:
`.gallery-breadcrumb__resolution` → `.gallery-breadcrumb__current`.

### Right click and drag are blocked on the gallery

The grids and the photo page now carry `gallery-media-protect`, which cancels the context menu and the
drag, the touch long-press being neutralized in CSS. It is a deterrent, not a protection — see the
readme. To keep the browser's own behavior, override the two grid components and `gallery/media.html.twig`
without their `data-controller`/`data-action` attributes.

### Theme tokens

`--gallery-photo-highres-max-width` is gone with the page it sized. Six `--gallery-lightbox-*` tokens
replace it (measure, backdrop, close button). A site that had taken the old one over should move its
value to `--gallery-lightbox-max-width`; `php bin/console c975l:scaffold:install` will not touch the
`gallery.css` it owns.

**A category owns UiBundle blocks now, in a new `gallery_category_block` table.** That is the whole
migration: one join table, no column added or changed on `gallery`, `gallery_category` or
`gallery_photo`, and no data to move.

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Until that has run, any screen touching a category fails on the missing table — the EasyAdmin category
form and the public `/photos/{category}` page both read the collection.

### The gallery's colors are admin-editable

Ten `theme-color-gallery-*` configs join the `gallery` group: passe-partout, arrows (color, hover color,
background), lightbox backdrop and close button (color, background), breadcrumb and video badge
(background, color). They ship empty, each token keeping the bundle's own default, so nothing changes
until an admin fills one in.

```bash
php bin/console c975l:config:load-all
```

That is also why this bundle now requires **`c975l/core-bundle` ^1.4**: UiBundle compiles a config into a
`--c975l-*` custom property on its `theme-` slug prefix from that version on, whatever the group it is
displayed in. On an older CoreBundle the ten entries would be editable and have no effect at all.

### What you do not have to do

- **no route to add**: the two new block kinds render inside whatever page already holds them, and the
  category heading inside the category page this bundle already served
- **nothing to register** for the blocks: `Management\GalleryBlockOwnerResolver` and
  `Service\GalleryShowcaseProvider` are picked up by implementing their interface

### If you override the category template

An app carrying its own `templates/bundles/c975LGalleryBundle/gallery/category.html.twig` keeps
rendering exactly what it did — including *not* rendering the new heading. Add the component where the
heading belongs, above the grid:

```twig
<twig:c975LUi:Blocks:Blocks blocks="{{ category.blocks }}"/>
```

### Sitemap urls gained a `title`

`GallerySitemapProvider` now declares a `title` on the gallery index and on each category, which the
sitemap ignores and which ConfigBundle's `SeoFilesWriter` builds `public/llms.txt` from. Photos
deliberately carry none and are skipped there. An app that had subclassed the provider to add its own
urls should leave photo entries untitled too, or `llms.txt` turns into a Markdown sitemap.
