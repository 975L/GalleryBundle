ChangeLog

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