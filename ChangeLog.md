# ChangeLog

## v1.8.0

A gallery gathers the latest additions of all the others

- Added `GalleryCategory::$automatic`, a gallery holding no media of its own (19/08/2026) [Needs db update]
- Added an index on `gallery_media.created_at` (19/08/2026) [Needs db update]
- Added `Service\GalleryLatestProvider`, the one place answering what that gallery shows (19/08/2026)
- Added `GalleryMediaRepository::findLatest()`, the medias of the last days, all galleries taken together (19/08/2026)
- It falls back on the last day carrying an addition when the window catches nothing (19/08/2026)
- Added `GalleryCategoryRepository::findOrCreateAutomatic()`, writing it on the first listing (19/08/2026)
- A trashed automatic gallery is left in the trash, unlike the catch-all (19/08/2026)
- Added `GalleryCategoryRepository::freeSlug()`, suffixing a slug already taken (19/08/2026)
- Added `gallery-latest-days` and `gallery-latest-max` to the **gallery** configuration group (19/08/2026)
- `GalleryCategory::getCoverOrRandomMedia()` returns the newest photo on that gallery (19/08/2026)
- A grid links each media under its own category, with `?from=` naming the gallery being walked (19/08/2026)
- The media page browses the last additions when that parameter names them (19/08/2026)
- The **Galerie - médias** block shows the last additions when pointed at that gallery (19/08/2026)
- The medias count of the automatic gallery is rendered by its own template (19/08/2026)
- Its edit screen lists the medias of every gallery, cut into one section per day (19/08/2026)
- Each tile there names the gallery the media belongs to, and the selection acts on it (19/08/2026)
- The screen offers no upload, no reordering, no cover and no trash view (19/08/2026)
- Extracted the medias grid tile into `_gallery_media_tile.html.twig` (19/08/2026)
- The category picker of a media leaves out the automatic and trashed galleries (19/08/2026)
- The export carries the automatic flag, and an import gives it to one category at most (19/08/2026)
- The README documents the automatic gallery, UPGRADE its migration (19/08/2026)
- The shipped skill names `GalleryLatestProvider`, the two entries and the `?from=` parameter (19/08/2026)
- Added `GalleryLatestProviderTest` and cases to nine existing test classes (19/08/2026)

## v1.7.0

A gallery hands its files back, and reports the ones it lost

- Added `Management\GalleryFilesHealthCheckProvider` (kind `files-gallery`), reporting the medias whose file is gone from the server (19/08/2026)
- A media hosting its own video gets one row per file (19/08/2026)
- Added `GalleryMediaRepository::findWithFilename()`, the trash left out (19/08/2026)
- `GalleryMediaFactory` no longer counts the trash when placing a new batch (19/08/2026)
- A media taken out of the trash comes back at the end of the gallery (19/08/2026)
- `c975l/core-bundle` is required from `^1.12.5` (19/08/2026)
- Added `GalleryFilesHealthCheckProviderTest` and two cases to `GalleryMediaFactoryTest` (19/08/2026)
- Added `Service\GalleryMediaArchiver` and `GalleryCategoryCrudController::downloadMedias()`, handing a selection's files back as one zip (19/08/2026)
- **Download high resolution** and **Download originals** on the medias toolbar of a category (19/08/2026)
- The two downloads are offered in the medias trash as well (19/08/2026)
- Each archive entry is named after its media's slug (19/08/2026)
- Entries are stored rather than deflated (19/08/2026)
- A selection past 1 GB is refused with its size stated (19/08/2026)
- A selection holding no file says so rather than downloading an empty archive (19/08/2026)
- An archive that could not be written says so on its own flash (19/08/2026)
- `ext-zip` is now required (19/08/2026)
- Added `GalleryMediaArchiverTest` and eight cases to `GalleryCategoryCrudControllerTest` (19/08/2026)
- The category's own **Move to trash** leaves the page toolbar for the foot of the page (19/08/2026)
- The README documents the new check, the two downloads and where the delete button now sits (19/08/2026)
- The shipped skill names the files check, `findWithFilename()` and `GalleryMediaArchiver` (19/08/2026)

## v1.6.1

The CI caches Composer's archives from one run to the next

- Composer's archive cache is carried from one CI run to the next (17/08/2026)
- The CI runs on a push to main and on pull requests only (17/08/2026)
- Concurrent CI runs on the same ref are cancelled (17/08/2026)
- The CI workflow's `GITHUB_TOKEN` is pinned to `contents: read` (17/08/2026)

## v1.6.0

A gallery is never lost in one click

- `GalleryCategory` and `GalleryMedia` carry UiBundle's `TrashableTrait`, each with a trash of its own (17/08/2026) [Needs db update]
- Deleting a category now only moves it to the trash (17/08/2026)
- No cascade and no file removed at the move to trash (17/08/2026)
- The category index switches between the galleries and the trash (17/08/2026)
- **Restore** and **Delete permanently** on each trashed category (17/08/2026)
- A category's own edit screen carries the same trash for its medias (17/08/2026)
- Permanent deletion is held at `site-role-admin` (17/08/2026)
- A trashed category or media answers 410 (17/08/2026)
- The "gone" `Redirect` tree moved from the deletion to the permanent one (17/08/2026)
- Restoring releases the "gone" rows left under the url (17/08/2026)
- `findAllOrdered()` filters the trash out (17/08/2026)
- Added `countVisible()`, which counts the categories the site shows (17/08/2026)
- `findOneBySlug()` stays unfiltered, the front-office answering 410 from the row (17/08/2026)
- `getCoverOrRandomMedia()` and `getMediasCount()` skip the trash (17/08/2026)
- The previous/next navigation and the random media skip trashed medias (17/08/2026)
- The archive carries the flag both ways (17/08/2026)
- `findOrCreateUncategorized()` lifts the flag off the catch-all category (17/08/2026)
- Added the trash labels and confirmations in the three locales (17/08/2026)
- `restore()` and `deletePermanently()` check a csrf token carried in their url (17/08/2026)
- The medias' trash renders neither the drag handles nor the cover radios (17/08/2026)
- `saveMediasLayout()` skips trashed medias (17/08/2026)
- The selection actions only reach medias of the screen they belong to (17/08/2026)
- Trashing a single media releases the cover it was (17/08/2026)
- Dropped the unused `findByCategoryIncludingTrashed()` (17/08/2026)
- The upload screen carries UiBundle's progress bar (17/08/2026)
- `GalleryMediaBatchUploadType` arms it over the ceilings it already declares (17/08/2026)
- `GalleryMediaUploadController` hands the arrival url back to the bar (17/08/2026)
- Requires `c975l/core-bundle` v1.11.7 (17/08/2026)
- `skills/c975l-gallery/SKILL.md` ships in the package, for the coding agents of the sites installing it (17/08/2026)
- `SkillsTest` checks every path, route, config slug, command, class member, Twig function, block kind and component the skill quotes (17/08/2026)
- README documents the two-step deletion and the trash screens (17/08/2026)
- README documents what the upload screen shows while the batch goes up (17/08/2026)
- README documents where an agent reads the skill from (17/08/2026)

## v1.5.4

A category's edit screen links the sharing debugger

- The category edit screen carries ConfigBundle's sharing debugger note, on the category's own url (14/08/2026)
- Requires `c975l/core-bundle` v1.11.4 (14/08/2026)
- README documents the note under the share image (14/08/2026)

## v1.5.3

Every gallery page carries a photo of its own as og:image

- Added `GalleryCategory::getCoverOrRandomMedia()`, the cover or one of the medias at random (14/08/2026)
- The public category component and the admin thumbnail read it instead of composing the fallback themselves (14/08/2026)
- A media page sets its `ogImage` from its stored (medium) file (14/08/2026)
- A category page sets its `ogImage` from its cover (14/08/2026)
- The gallery index sets its `ogImage` from one of the categories' covers, unless its url metadata carries one (14/08/2026)
- README documents the image a shared page carries (14/08/2026)

## v1.5.2

The gallery index is listed in "Descriptions d'urls"

- Added `GalleryUrlMetadataProvider`, declaring the gallery index to `c975l:url-metadata:sync` (14/08/2026)
- The declared path follows the configured route prefix, read at sync time (14/08/2026)
- README documents the declared url and links it from the category summary (14/08/2026)

## v1.5.1

The dev-only files stay out of the Composer archive, and the CI audits the dependencies

- Added `.gitattributes`, marking the dev-only paths `export-ignore` (14/08/2026)
- Added the `audit-deps` script, run by `qa` and by the CI ahead of the other checks (14/08/2026)
- Added `.github/FUNDING.yml` (14/08/2026)
- ConfigBundle's guided projects are placed at 10-40, in the README and in `GalleryGuidedProjectProvider` (14/08/2026)

## v1.5.0

A category names its summary as a page does, and every gallery page carries one

- Renamed `GalleryCategory::$description` to `$summarySocialNetwork`, with its getter and setter (13/08/2026)
- The category form labels it from ConfigBundle's `label.summary_social_network` (13/08/2026)
- The export carries it as `summarySocialNetwork` (13/08/2026)
- The import falls back on `description`, from an archive predating the rename (13/08/2026)
- Requires `c975l/core-bundle` v1.10 (13/08/2026)
- The media page composes its `summarySocialNetwork` from the site, the category, the title and the credits (13/08/2026)
- A media with no title takes its category's as the alt of its thumbnail and of its lightbox (13/08/2026)
- Added a "view on site" action to the category index and edit screens (13/08/2026)
- Added `action.view_on_site` and `label.gallery_summary_social_network_help` (13/08/2026)
- Dropped `label.gallery_description` and its help (13/08/2026)
- Added `GalleryGuidedProjectProvider`, contributing three guided projects to the dashboard (13/08/2026)
- The gallery menu entry carries a description, reused by the onboarding tour (13/08/2026)
- Added `data-gallery-upload-medias` and `data-gallery-cover-radio`, read by the guided projects (13/08/2026)
- Added the guided projects' labels and descriptions in the three locales (13/08/2026)
- README documents the category's summary and the guided projects (14/08/2026)
- README documents the "view on site" action and a media's alt fallback (14/08/2026)
- UPGRADE documents the renamed field and its migration (14/08/2026)

## v1.4.3

The bundle carries its own stylelint config

- Added `.stylelintrc.json` (13/08/2026)

## v1.4.2

An imported gallery keeps the names its files were exported under

- The export archives a media's thumbnail, high resolution and stored file alike (13/08/2026)
- The export carries each file's name and the media's `updatedAt` (13/08/2026)
- The import lays the archived files back under their exported names (13/08/2026)
- An archived name is only honoured under `GalleryMedia::MEDIA_DIRECTORY` (13/08/2026)
- Added `GalleryMedia::MEDIA_DIRECTORY`, used by `getVichMediaPath()` and `GalleryBackupPathProvider` (13/08/2026)
- The Twig extensions declare their functions with `#[AsTwigFunction]` (13/08/2026)
- Dropped `AbstractExtension` from the Twig extensions (13/08/2026)
- Added `symfony/twig-bundle` and `twig/twig` to the requirements (13/08/2026)
- Rector binds its Symfony and Doctrine rules to the installed versions through `withComposerBased()` (13/08/2026)
- The `rector` composer script clears its cache (13/08/2026)
- `bin/ci.sh` installs the quality tools in their latest release, as the CI does (13/08/2026)
- `bin/ci.sh` prints the version of each quality tool it ran (13/08/2026)
- `bin/ci.sh` runs Rector on a private `TMPDIR` (13/08/2026)

## v1.4.1

- The lightbox closes on any click inside, the close button being removed

- Removed the lightbox close button, a click anywhere inside closing it (11/08/2026)
- Removed the `theme-color-gallery-lightbox-close` and `theme-color-gallery-lightbox-close-background` configs (11/08/2026)

## v1.4.0

Rector runs over the bundle, with a site's own sets

- Added `rector.php`, carrying the sets of a site's Symfony migration (11/08/2026)
- Added the `rector` composer script, joined to `qa` (11/08/2026)
- The CI runs Rector over `src/`, `tests/` and `scaffold/` (11/08/2026)
- Modernised `src/` and `tests/`: typed constants, `#[\Override]`, first-class callables, `readonly` properties (11/08/2026)
- `GalleryCategory` implements `\Stringable` (11/08/2026)

## v1.3.1

The lightbox frames the high resolution too

- The passe-partout frames the high resolution in the lightbox as well as the media on the page (11/08/2026)
- The lightbox image is `border-box`, the mount being taken off the dialog's measure rather than added to it (11/08/2026)

## v1.3.0

A gallery is laid on a ground of its own

- Added the `gallery-style` choice config, offering the `light` and `dark` styles (11/08/2026)
- A style retunes UiBundle's `--background`, `--text`, `--black`, `--white`, `--primary` and `--link-color` for the gallery's pages (11/08/2026)
- A style outranks SiteBundle's own dark mode (11/08/2026)
- `dark` also retunes SiteBundle's `--footer-background` and `--footer-text`, `light` leaving the site's band alone (11/08/2026)
- Added the `gallery-frame` choice config, picking the passe-partout between `none`, `thin` and `wide` (11/08/2026)
- The passe-partout takes `--text` instead of `--white`, inverting against the ground it is laid on (11/08/2026)
- The three pages of the viewer fill SiteBundle's `bodyClass` block with `gallery-page` (11/08/2026)
- Added `Twig\Extension\GalleryStyleExtension` and its `gallery_body_class()`, dropping an unknown value (11/08/2026)
- The arrows, the lightbox and the video badge default to literal black and white instead of `var(--black)` / `var(--white)` (11/08/2026)
- `dark` sets SiteBundle's `--title-color`, the headings reading a brand color at barely 1:1 on its ground (11/08/2026)
- `dark` drops the footer link's hover wash and takes the site name to the titles' ink (11/08/2026)
- Requires a `c975l/site-bundle` whose layout offers the `bodyClass` block and whose headings read `--title-color` (11/08/2026)
- Requires `c975l/core-bundle` v1.8 for `RedirectRepository::findByFromPathPrefix()` (11/08/2026)
- Added the `GalleryStyleTest` and `GalleryStyleExtensionTest` cases (11/08/2026)
- Seven `theme-color-gallery-*` configs ship with the color their fallback paints (11/08/2026)
- The four whose fallback is an expression stay empty, having no fixed color to declare (11/08/2026)
- Added the `ThemeColorDefaultTest` case, pinning each declared value on its sass fallback (11/08/2026)
- A deleted media leaves its url answering 410 Gone (11/08/2026)
- A deleted category covers its whole tree with a single wildcard row (11/08/2026)
- The rows pointing at a deleted url answer the same 410 directly (11/08/2026)
- A row leaving the deleted tree keeps its redirect (11/08/2026)
- Added `GalleryUrlRedirector::release()`, lifting the 410 of a slug created again (11/08/2026)
- `record()` clears the gone flag of the row it reuses (11/08/2026)
- The breadcrumb's home level prints the number of categories, as a category prints its medias (11/08/2026)
- The index counts the list it reads, a category and a media page count without listing (11/08/2026)
- The `Gallery:Navigation` component prints no count when none is passed (11/08/2026)
- The previous/next arrows are revealed on hover and on keyboard focus (11/08/2026)
- A touch screen keeps them on, having no hover to reveal them with (11/08/2026)
- Added `--gallery-thumb-label-gap`, parting a category's title from its cover (11/08/2026)

## v1.2.3

Blocks share one read of the category list per request

- `GalleryBlockExtension` reads the ordered categories once per request, resolving each block's slug in PHP (10/08/2026)
- `GalleryBlockExtension` implements `ResetInterface`, dropping the list between two requests (10/08/2026)
- Added the `GalleryBlockExtensionTest` cases covering the shared read and the reset (10/08/2026)

## v1.2.2

Categories are listed without a query per cover

- `findAllOrdered()` joins and selects `coverMedia`, dropping the query per category (10/08/2026)
- README links the live demo and the block gallery (10/08/2026)

## v1.2.1

A gallery's uploads are declared to the backup, and its news to the dashboard

- Added `Management\WhatsNewProvider` and `config/whatsnew.json`, feeding the dashboard's What's new (08/08/2026)
- README documents the What's new file (08/08/2026)
- Added the `WhatsNewProviderTest` cases (08/08/2026)
- Added `GalleryBackupPathProvider`, mirroring the two upload roots (08/08/2026)
- README documents the declared backup paths (08/08/2026)
- README no longer names the removed `backup_exclude.cnf` (08/08/2026)
- Added the `GalleryBackupPathProviderTest` cases (08/08/2026)
- Added the `GalleryImportProviderTest` cases covering the video import (08/08/2026)

## v1.2

A video is an url now, self-hosted or from any declared platform

- A media carries `externalUrl` instead of a `mediaType`/`externalId` pair (08/08/2026) [BC-Break] [Needs db update]
- Removed `GalleryMedia::setMediaType()`, the type being derived from the url (08/08/2026) [BC-Break]
- Replaced `GalleryMedia::MEDIA_TYPES` with `mediaTypes()`, built from UiBundle's registry (08/08/2026) [BC-Break]
- Added Vimeo and Dailymotion support (08/08/2026)
- An undeclared platform is stored as pasted, under the `embed` type (08/08/2026)
- A pasted url is stored as its platform's privacy-first embed url (08/08/2026)
- Players render through UiBundle's `Video:Iframe`, behind the site's consent gate (08/08/2026) [BC-Break]
- The media edit screen asks for an url instead of a type and an id (08/08/2026)
- Export carries `externalUrl`, the import rebuilding it from an older archive's id (08/08/2026)
- Added the `--gallery-video-ratio-default`, `…-vimeo` and `…-dailymotion` theme tokens (08/08/2026)
- Added the `label.gallery_external_url(_help)` and `label.gallery_media_type_*` translations (08/08/2026)
- Removed the `label.gallery_external_id(_help)` translations (08/08/2026)
- A media can carry an uploaded video (mp4/webm/ogg), played by the browser (08/08/2026) [Needs db update]
- A self-hosted video wins over a pasted url, which stays as its fallback (08/08/2026)
- The video field is capped by php's `upload_max_filesize`, not the bundle's photo ceiling (08/08/2026)
- Added `UploadLimits::getMaxVideoFileSize()` (08/08/2026)
- Export and import carry the self-hosted video file (08/08/2026)
- Added the `label.gallery_video_file(_help)` and `label.gallery_media_type_video` translations (08/08/2026)
- Capped a portrait player on the viewport's height (08/08/2026)
- The previous/next arrows follow a player narrower than the container (08/08/2026)
- Added the `--gallery-video-portrait-max-width` theme token (08/08/2026)
- README and UPGRADE document the url, the consent gate and the CSP parameter (08/08/2026)
- Added a rich-text `description` on a category, edited with Trix (08/08/2026) [Needs db update]
- The description prints above the grid (08/08/2026)
- The description feeds the page's `description`/`og:description` metas (08/08/2026)
- Export and import carry the category's description (08/08/2026)
- The description is centered under a short rule, its measure and alignment being tokens (08/08/2026)
- Added the `--gallery-category-description-*` and `…-rule-*` theme tokens (08/08/2026)
- Added the `label.gallery_description(_help)` translations (08/08/2026)
- Added `Management\LinkableRouteProvider`, offering the gallery index and each category as SiteBundle menu targets (08/08/2026)
- A category target is keyed on its id, so renaming it leaves no menu item behind (08/08/2026)
- A category is listed as "Galerie - Paysages" in the target select, the navbar item reading "Paysages" (08/08/2026)
- README documents linking a gallery from a menu (08/08/2026)
- A media's slug is edited behind EasyAdmin's padlock (08/08/2026)
- An `externalUrl` is only kept if it is http(s) (08/08/2026)
- An `externalUrl` longer than its column comes back as a form error (08/08/2026)
- The derived media type is shown read-only on the media's edit screen (08/08/2026)
- Requires `c975l/core-bundle` ^1.4 (08/08/2026)
- Requires `symfony/validator` ^8.0 (08/08/2026)

## v1.1

Stop storing the watermark on a media, asking it at upload instead

- The watermark is no longer stored on a media, only asked when a file is uploaded (07/08/2026) [BC-Break]
- `GalleryMedia::setWatermarked()` is now `setWatermark()` (07/08/2026) [BC-Break]
- The media edit screen asks for the watermark, applied to a replaced file (07/08/2026)
- Added the `label.gallery_media_watermark_help` translation (07/08/2026)
- README states nothing about the watermark is stored on a media (07/08/2026)
- UPGRADE documents the dropped columns and the renamed setter (07/08/2026)

## v1.0

First production release

- Export carries a category's blocks, the import replacing them (07/08/2026)
- Export carries a media's kept original, the import putting it back under `private/` (07/08/2026)
- Removed the export's unread `originalFilename` key, `originalFile` replacing it (07/08/2026)
- README states what an export carries of a media's original and watermark (07/08/2026)
- README states an export carries a category's blocks (07/08/2026)
- The media page shows an edit button over the media (07/08/2026)
- A category page shows one over its grid, opening the category itself (07/08/2026)
- Added `Twig\Extension\GalleryEditUrlExtension`, with `gallery_category_edit_url()` and `gallery_media_edit_url()` (07/08/2026)
- The gallery back-office moved from `ROLE_ADMIN` to the `site-role-editor` config (07/08/2026) [BC-Break]
- README describes editing from the public pages (07/08/2026)
- A category's edit screen carries its own delete button, hidden for "Uncategorized" (07/08/2026)
- `GalleryMediaDerivativeCleanupListener` removes a media directory left empty, in `public/` and `private/` (07/08/2026)
- The watermark position's placeholder carries its own translation domain (07/08/2026)
- The categories screen says the watermark exists and where its three settings are (07/08/2026)
- Added the `label.info_gallery_watermark` translation (07/08/2026)
- README describes watermarking a batch and the `ui-watermark-*` configs (07/08/2026)
- README describes deleting a gallery (07/08/2026)
- Thumbnails bounce on hover, and the `--gallery-thumb-hover-animation` token sets or removes it (07/08/2026)
- Thumbnails hold the whole photo, both grids squaring them in CSS (07/08/2026) [BC-Break]
- Requires the `c975l/core-bundle` version generating inset thumbnails (07/08/2026)
- `GalleryMedia::THUMBNAIL_SIZE` raised to 600, the cropped display keeping only the shortest side (07/08/2026)
- Added the `gallery-thumbnail-whole` config, off by default, and the `--gallery-thumb-background` token (07/08/2026)
- Added the `label.gallery_thumbnail_whole` and `description.gallery_thumbnail_whole` translations (07/08/2026)
- Added `c975l:gallery:rebuild-thumbnails`, with `--dry-run`, and `Service\GalleryThumbnailRebuilder` (07/08/2026)
- `imagine/imagine` is now required explicitly, in `^1.5` (07/08/2026)
- README describes the thumbnail framing setting and the rebuild command (07/08/2026)
- `GalleryMedia::$alt` became `$title`, serving as name, alt text and slug source (07/08/2026)
- Added `GalleryMedia::$slug`, unique per category (07/08/2026)
- The media page is reached by slug, `/{prefix}/{category}/{slug}` (07/08/2026)
- The media edit form shows its slug, editable, warning before it is changed (07/08/2026)
- Added the `label.gallery_media_slug_help` translation (07/08/2026)
- Stored files are named after the media's slug (07/08/2026)
- Added `Service\GalleryMediaSlugger`, suffixing a slug already taken in the category (07/08/2026)
- Added `Service\GalleryUrlRedirector`, shared by the category and media CRUDs (07/08/2026)
- Added `GalleryMediaCrudController::updateEntity()`, redirecting a re-slugged or moved media's old url (07/08/2026)
- A renamed category redirects the media urls under it, wildcarded (07/08/2026)
- Added `c975l:gallery:fill-slugs`, with `--dry-run` (07/08/2026)
- Export carries `title` and `slug`, import reads the legacy `alt` (07/08/2026)
- Removed the `label.alt_text` translation, added `label.gallery_media_title_help` and `confirm.media_slug_change` (07/08/2026)
- Renamed the `label.gallery_showcase_media_alt` translation to `label.gallery_showcase_media_title` (07/08/2026)
- README describes renaming a media (07/08/2026)
- A media's slug no longer follows its title, so retitling moves no url (07/08/2026) [BC-Break]
- The slug field is editable, carrying the confirmation the title used to (07/08/2026)
- An emptied slug is rebuilt from the title (07/08/2026)
- Added the batch title root, numbering every title from where the category leaves off (07/08/2026)
- A rooted batch takes its slug from a 6-character hash of the photo's EXIF capture date (07/08/2026)
- Added `Model\GalleryMediaBatch`, replacing `GalleryMediaFactory::createFromUploads()`'s trailing arguments (07/08/2026) [BC-Break]
- Added the batch "keep the originals" option, `GalleryMedia` implementing `VichOriginalKeepableInterface` (07/08/2026)
- Added `GalleryMedia::$originalFilename` and `ORIGINAL_DIRECTORY`, the original living under `private/` (07/08/2026)
- `GalleryMediaDerivativeCleanupListener` removes a kept original along with the derivatives (07/08/2026)
- Added the `label.gallery_title_root`, `label.gallery_keep_originals`, `label.gallery_batch_title_root_help` and `label.gallery_batch_keep_originals_help` translations (07/08/2026)
- Suggests `ext-exif` (07/08/2026)
- Requires the `c975l/core-bundle` version providing `VichOriginalKeepableInterface` (07/08/2026)
- README describes uploading a batch and keeping the originals (07/08/2026)
- A category's medias are reordered by dragging their thumbnails (07/08/2026)
- Added `assets/js/gallery-media-sort.js`, registered in `controllers-admin.js` (07/08/2026)
- The drag is UiBundle's `addSortGesture()`, reordering by finger as by mouse (07/08/2026)
- Each tile carries a move handle, the tile itself staying mouse-only (07/08/2026)
- Added the `action.move` translation (07/08/2026)
- Needs the `@c975l/ui-bundle/pointer-sort.js` importmap entry in the consuming app (07/08/2026)
- The cover is picked among the medias, or left random (07/08/2026)
- The order and the cover save themselves as they are changed (07/08/2026)
- Added `GalleryCategoryCrudController::saveMediasLayout()`, csrf token read from the `X-CSRF-Token` header (07/08/2026)
- Added the `label.gallery_cover`, `label.gallery_cover_random`, `label.gallery_cover_select` and `label.gallery_medias_layout_failed` translations (07/08/2026)
- Rewrote `label.info_gallery_category_medias` (07/08/2026)
- README describes ordering the medias and picking a cover (07/08/2026)
- README describes the medias list's toolbar and its "Add medias" button (05/08/2026)
- README's import section no longer says a colliding slug is suffixed (05/08/2026)
- The "Add medias" button moved from the edit toolbar to the medias' own list, before the "Select all" box (05/08/2026)
- A category with no media shows the button and says so (05/08/2026)
- Added the `label.info_gallery_category_medias_empty` translation (05/08/2026)
- Documented the CSP directives a site's videos need (05/08/2026)
- A renamed category has its slug rebuilt from its new title (05/08/2026)
- The title field asks to confirm first, through UiBundle's `title-confirm` controller (05/08/2026)
- Added `GalleryCategoryCrudController::updateEntity()`, redirecting the category's old url to its new one (05/08/2026)
- The slug field gained its help text, and `label.slug_help` was rewritten (05/08/2026)
- Added the `confirm.title_change` translation (05/08/2026)
- Documented renaming a category in the readme (05/08/2026)
- The gallery's ten colors are admin-editable, as `theme-color-gallery-*` configs in the gallery group (05/08/2026)
- Each color token reads its `--c975l-color-gallery-*` first, keeping the bundle's default as fallback (05/08/2026)
- Requires `c975l/core-bundle` ^1.3 (05/08/2026)
- Added the `--gallery-nav-hover-color` token, used by the arrows' hover and focus rule (05/08/2026)
- Added the `label.theme_color_gallery_*` and `description.theme_color_gallery_*` translations (05/08/2026)
- Each color's description names the default applying while it is empty, and the syntax expected (05/08/2026)
- The category creation form takes a batch of medias, added with the category (05/08/2026)
- Added `Service\GalleryMediaFactory`, shared by the creation form and the upload screen (05/08/2026)
- Added `UploadLimits::isTruncatedRequest()`, used by both screens (05/08/2026)
- The upload screen's flashes are translated in this bundle's domain (05/08/2026)
- Each media of a category's edit screen carries a checkbox, with a "Select all" box (05/08/2026)
- Added `GalleryCategoryCrudController::deleteMedias()`, deleting the checked medias in one go (05/08/2026)
- The deletion is confirmed through EasyAdmin's own action confirmation modal (05/08/2026)
- Added `assets/js/gallery-media-selection.js`, registered in `controllers-admin.js` (05/08/2026)
- Added the `action.select`, `action.select_all`, `action.delete_selection`, `label.gallery_select_media`, `label.gallery_medias_delete_confirm` and `label.gallery_medias_deleted` translations (05/08/2026)
- A category whose slug is already used is refused with a form error instead of being suffixed (05/08/2026) [BC-Break]
- Removed `GalleryCategoryRepository::makeSlugUnique()` (05/08/2026) [BC-Break]
- The submitted slug is slugified before validation, `persistEntity()`/`updateEntity()` no longer doing it (05/08/2026)
- The medias block offers every category, two sharing a title being told apart by their slug (05/08/2026)
- The EasyAdmin menu entry opens the categories, on `/management/gallery` (05/08/2026) [BC-Break]
- The category listing gained a media count column, with `GalleryCategory::getMediasCount()` (05/08/2026)
- A category's medias are listed under its edit form, each thumbnail opening that media's edit screen (05/08/2026)
- Removed the all-medias listing, `GalleryMediaCrudController::index()` redirecting to the category instead (05/08/2026) [BC-Break]
- Removed the media CRUD's category filter, its `manageCategories` action and `management/gallery_photo_index.html.twig` (05/08/2026) [BC-Break]
- The media edit screen carries its own delete button, there being no listing left to offer one (05/08/2026)
- The upload screen moves to `/management/gallery-upload` and returns to the category filled (05/08/2026) [BC-Break]
- Added the `label.info_gallery_category_medias` translations, `label.gallery_manage_categories` being removed (05/08/2026)
- The public routes move from `/photos` to `/gallery` (05/08/2026) [BC-Break]
- Added `config/configs.json` and its `gallery-route-prefix` entry, in a `gallery` group of its own (05/08/2026)
- Added `Routing\GalleryRoutePrefix`, the routes' condition matching that entry at each request (05/08/2026)
- Added `Listener\GalleryRoutePrefixListener`, carrying the prefix to the url generator (05/08/2026)
- Added the `site_config` and `config` translations of that entry and its group (05/08/2026)
- `symfony/expression-language` is now required, in `^8.0`, route conditions needing it (05/08/2026)
- The EasyAdmin menu entry is labelled `label.gallery`, with the new translation (05/08/2026)
- Removed the `Gallery` entity and its repository, the category becoming the top-level unit (05/08/2026) [BC-Break]
- Photo files move from `medias/gallery/{gallery}/{category}/` to `medias/gallery/{category}/` (05/08/2026) [BC-Break]
- Photos are added from a category's row action and edit screen, the photo index losing its upload button (05/08/2026) [BC-Break]
- The upload screen takes every file at once, the category being read-only and the per-row fields gone (05/08/2026) [BC-Break]
- The uploaded filename seeds each photo's alt text (05/08/2026)
- The category index shows its row actions inlined (05/08/2026)
- Added `Service\UploadLimits`, reading php's `max_file_uploads`/`upload_max_filesize`/`post_max_size` (05/08/2026)
- `UploadLimits` caps a batch at 100 files and 20M per file of its own, whichever is smaller applying (05/08/2026)
- The upload screen states those ceilings and refuses a batch before sending it (05/08/2026)
- Added `assets/js/gallery-upload-limits.js` and `assets/controllers-admin.js` (05/08/2026)
- `ScriptProvider` implements `BundleScriptAdminProviderInterface`, and `ImportmapProvider` declares the admin entrypoint (05/08/2026)
- A batch php emptied past `post_max_size` is reported (05/08/2026)
- The batch upload accepts a file up to 20M instead of 10M, stated in bytes (05/08/2026)
- README documents the PHP ceilings a bulk upload meets (05/08/2026)
- Removed `c975l:gallery:import-legacy` and its command class (05/08/2026) [BC-Break]
- README replaces it with the back-office walkthrough for bringing an existing gallery in (05/08/2026)
- The video embed keeps its ratio, UiBundle's global `iframe` rule being reset (05/08/2026)
- The high resolution opens in a lightbox, the `gallery_photo_hr` route and its page being removed (05/08/2026) [BC-Break]
- Added `assets/js/gallery-lightbox.js` and its `--gallery-lightbox-*` tokens (05/08/2026)
- Added `assets/js/gallery-photo-protect.js`, blocking right click and drag on the grids and the photo page (05/08/2026)
- The breadcrumb's `resolution` parameter is now `mention`, only carrying a video entry (05/08/2026) [BC-Break]
- The photo previous/next arrows are shaped as buttons, with three new `--gallery-nav-*` tokens (05/08/2026)
- Added the `gallery_categories` and `gallery_photos` block kinds, with `Twig\Extension\GalleryBlockExtension` (05/08/2026)
- `GalleryCategory` implements `HasBlocksInterface`, its blocks rendering above the category grid (05/08/2026)
- Added `Management\GalleryBlockOwnerResolver` and the blocks field of the category CRUD (05/08/2026)
- Added `Service\GalleryShowcaseProvider`, showing both block kinds in the block showcase (05/08/2026)
- `GallerySitemapProvider` titles the index and the categories, feeding `llms.txt` (05/08/2026)
- README restructured on SiteBundle's outline, and its requirements now name `c975l/core-bundle` (05/08/2026)
- Added the `qa` Composer script and its steps, which the CI workflow now calls (03/08/2026)
- Added `bin/ci.sh`, replaying the CI checks on dependencies freshly resolved from Packagist (03/08/2026)
- The `gallery_medias` block gained a "draw them at random" option, applied before its maximum (05/08/2026)
- `GalleryPhoto` is renamed `GalleryMedia`, the `gallery_photo` table becoming `gallery_media` (05/08/2026) [BC-Break]
- The repository, both controllers, both forms and both listeners follow that name (05/08/2026) [BC-Break]
- `GalleryCategory`'s photo collection, cover and count are now `medias`/`coverMedia`/`mediasCount` (05/08/2026) [BC-Break]
- The `gallery_photo` route is now `gallery_media`, and the `gallery_photos` block kind `gallery_medias` (05/08/2026) [BC-Break]
- The `Photo`/`Photos` components and the `gallery/photo`, `blocks/Photos` and `management/gallery_photo_*` templates follow (05/08/2026) [BC-Break]
- The `photo` translation keys are renamed, their wording moving to "media" in the three locales (05/08/2026) [BC-Break]
- The export writes `medias`/`coverMediaIndex`, the import still reading the former keys (05/08/2026) [BC-Break]
- New uploads are named `media-*` instead of `photo-*`, stored files being untouched (05/08/2026)
- The `--gallery-photo-*` tokens are now `--gallery-media-*`, in the sass, the compiled css and the scaffolded theme (05/08/2026) [BC-Break]
- `.photo-container`/`.photo-display`/`.photo-zoom`/`.gallery-photo-nav` become `.gallery-media-*` (05/08/2026) [BC-Break]
- The `gallery-photo-preload`/`gallery-photo-protect` controllers and their files are renamed `gallery-media-*` (05/08/2026) [BC-Break]
- UPGRADE documents the rename, the table rename and the `site_block.kind` update it needs (05/08/2026)

## v0.2

Add a public gallery viewer, video entries and category export/import

- `ImportmapProvider` declares its entrypoint relative to this bundle, `ImportmapRegistry` resolving where it sits under `vendor/` (03/08/2026) [BC-Break]
- The high-resolution page's previous/next arrows now fall back to a video neighbour's medium view (03/08/2026)
- `GalleryCategory` gained a unique constraint on `(gallery, slug)` (03/08/2026) [BC-Break]
- Added `GalleryCategoryRepository::makeSlugUnique()`, suffixing a colliding category slug (03/08/2026)
- An import no longer creates a second default `Gallery` (03/08/2026)
- `GalleryPhotoDerivativeCleanupListener` now removes the `-thumb`/`-highres` files on `preRemove` too (03/08/2026)
- Removed `GalleryPhotoCrudController::deleteEntity()`, that cleanup now being the listener's (03/08/2026)
- `GalleryPhoto` gained `mediaType` (`image`/`youtube`/`tiktok`) and `externalId` (02/08/2026)
- A video entry keeps its own uploaded still and opens on a cookie-free embed instead of the high-resolution page (02/08/2026)
- `/photos/{category}/{id}/hr` now returns a 404 for a video (02/08/2026)
- Added `Gallery/Video.html.twig`, and a badge marking a video entry in the grids (02/08/2026)
- The batch upload picks the type per batch and the video id per row (02/08/2026)
- `GalleryExportProvider`/`GalleryImportProvider` carry both new fields (02/08/2026)
- `c975l:gallery:import-legacy` gained `--category`, landing a flat source in a named category (02/08/2026)
- Added `sass/` and the compiled `public/css/styles.min.css`, replacing `_photo_navigation_style.html.twig` (02/08/2026) [BC-Break]
- Added `Service\StylesheetProvider`, contributing that sheet to UiBundle's registry (02/08/2026)
- Added `scaffold/assets/styles/themes/gallery.css`, this bundle's own theme file (02/08/2026)
- Colors and fonts are read from UiBundle's admin-editable tokens, only shapes being themable here (02/08/2026)
- The grids now carry `gallery-grid`/`gallery-thumb` instead of `photo` (02/08/2026) [BC-Break]
- `Gallery::$default` is now stored in the `is_default` column (02/08/2026) [BC-Break]
- `c975l/config-bundle` is now required in `^6`, `c975l/ui-bundle` in `^1.18` (02/08/2026) [BC-Break]
- `GallerySitemapProvider` now carries `#[AsHealthCheck(frequency: monthly)]`, its urls being health-checked too (31/07/2026)
- `php` is now required in `>=8.4` instead of `>=8.0` (30/07/2026) [BC-Break]
- The `symfony/*` requirements are now constrained to `^8.0` instead of `*` (30/07/2026) [BC-Break]
- The third-party requirements left in `*` are now bounded on their installed version (30/07/2026)
- The `c975l/*` requirements are now bounded on their major (30/07/2026)
- `GalleryPhoto::$user` is now typed `c975L\ConfigBundle\Contract\UserInterface` instead of `App\Entity\User` (30/07/2026) [BC-Break]
- `GalleryPhotoUserListener` now assigns the logged-in user only when it implements `c975L\ConfigBundle\Contract\UserInterface` (30/07/2026)
- Added the `GalleryPhotoUserListenerTest` cases covering the logged-in branches (30/07/2026)
- Removed the `vendor-dir` composer setting pointing at `.vendor` (30/07/2026)
- Added `.codacy.yaml`, `phpcs.xml.dist` and `eslint.config.mjs` (30/07/2026)
- Applied PSR-12 to the codebase (30/07/2026)
- Added `.php-cs-fixer.dist.php`, applying the Symfony coding standards (30/07/2026)
- Added `phpstan.dist.neon`, running the static analysis at level 5 (30/07/2026)
- Added the `CI` GitHub Actions workflow, running PSR-12, the static analysis, the tests and the coverage upload (30/07/2026)
- The local Codacy CLI now runs `eslint@9.39.5` (30/07/2026)
- Added `GalleryExportProvider`/`GalleryImportProvider`, plugging category export/import into ConfigBundle's dashboard (24/07/2026)
- Added `ImportmapProvider`, declaring `controllers.js`'s importmap.php entry (24/07/2026)
- The photo detail page's navigation is now a text breadcrumb instead of full buttons (24/07/2026)
- Previous/next are now semi-transparent overlay arrows on the photo (24/07/2026)
- The front-office gallery templates are now translated via the `gallery` domain instead of hardcoded French (24/07/2026)
- Fixed the public gallery index never showing a category with no `coverPhoto` set (24/07/2026)
- Added a public front-office gallery viewer (`GalleryController`: index/category/photo/photo_hr) (24/07/2026)
- Added previous/next image preloading via a Stimulus controller (24/07/2026)
- Added the `c975l:gallery:import-legacy` command to migrate a Finder-based flat-file gallery (24/07/2026)
- Added missing test coverage for the bundle class, entities, forms, `GalleryPhotoCrudController`, `GalleryPhotoUploadController` and repositories (24/07/2026)
- Fixed `c975l:gallery:import-legacy` deleting each original source photo after import (24/07/2026)
- Fixed `GalleryImportProvider` creating duplicate galleries when two imported categories share a new gallery slug (24/07/2026)
- Fixed `c975l:gallery:import-legacy`'s file matching being case-sensitive, missing `.JPG`/`.JPEG` files (24/07/2026)
- Fixed `c975l:gallery:import-legacy` using subdirectory names as category slugs verbatim instead of slugifying them (24/07/2026)
- Fixed `c975l:gallery:import-legacy --credits=0` being stored as no credits (24/07/2026)
- Fixed `c975l:gallery:import-legacy --dry-run` previewing a hardcoded French title for the "Uncategorized" category instead of the translated one (24/07/2026)
- Fixed the admin category thumbnail ignoring `coverPhoto`, always picking a random photo instead (24/07/2026)
- Fixed a photo's alt text of "0" incorrectly falling back to the category title (24/07/2026)
- Added a CSP nonce to the photo navigation's inline stylesheet (24/07/2026)
- Optimized previous/next photo lookup to two indexed queries instead of loading the whole category (24/07/2026)
- Expanded the explanatory text on the Gallery photo/category index and edit screens (22/07/2026)
- Removed the detail/view page on Gallery photo and Gallery category (22/07/2026)
- Added a Cancel action on every create/edit screen (22/07/2026)
- Index-page inline row actions now show icon-only, via ConfigBundle's `EasyAdminActionHelper::toIconOnly()` (16/07/2026)
- Added the Codacy grade badge to the README (30/07/2026)

## v0.1

- Initial commit (13/07/2026)
