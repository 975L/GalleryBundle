<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use c975L\GalleryBundle\Service\GalleryAutomaticProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

// Public front-office viewer - the categories are the gallery, so the index lists them all
// The first segment is ConfigBundle's "gallery-route-prefix" entry: it is carried as a route parameter and checked at each request by GalleryRoutePrefix (the routes' condition), so a site can rename it from the dashboard - "galerie", "fotos" - and the change applies straight away, no cache to clear. GalleryRoutePrefixListener feeds the same value to the generator, so nothing has to pass {gallery_prefix} to path()
class GalleryController extends AbstractController
{
    private const string PREFIX_CONDITION = "service('" . GalleryRoutePrefix::ALIAS . "').matches(params['" . GalleryRoutePrefix::PARAMETER . "'])";

    public function __construct(
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryMediaRepository $mediaRepository,
        private readonly GalleryAutomaticProvider $automaticProvider,
    ) {
    }

    // INDEX
    #[Route('/{gallery_prefix}', name: 'gallery_index', methods: ['GET'], condition: self::PREFIX_CONDITION)]
    public function index(): Response
    {
        // The automatic galleries are among them, written on the first render that misses them and handed the lists they show - they hold no media of their own, so their tile and their count come from there (see GalleryAutomaticProvider)
        $categories = $this->automaticProvider->prepare($this->categoryRepository->findAllOrdered());

        // The breadcrumb counts the categories next to its home label, as it counts the medias next to a category - taken from the list already read, so no query of its own
        return $this->render('@c975LGallery/gallery/index.html.twig', [
            'categories' => $categories,
            'categoriesCount' => count($categories),
        ]);
    }

    // CATEGORY
    #[Route('/{gallery_prefix}/{category}', name: 'gallery_category', methods: ['GET'], condition: self::PREFIX_CONDITION)]
    public function category(string $category): Response
    {
        $category = $this->resolveCategory($category);

        // An automatic gallery is rendered by this very template, from this very route: it is a category like the others, only its list is gathered instead of being read from a relation it has none of (see GalleryAutomaticProvider)
        $this->automaticProvider->hydrate([$category]);

        // The breadcrumb's home link carries the same count as on the index, counted here rather than listed, the page having no use for the categories themselves
        return $this->render('@c975LGallery/gallery/category.html.twig', [
            'category' => $category,
            'categoriesCount' => $this->categoryRepository->countVisible(),
            'medias' => $this->automaticProvider->getMedias($category),
        ]);
    }

    // MEDIA - the stored (medium) file, the only media page there is: the high resolution opens over it, in a lightbox, rather than on a page of its own
    // Reached by slug rather than by id: the url is what an image search shows under the result, and a title says there what a number said nothing of (see GalleryMediaSlugger)
    #[Route('/{gallery_prefix}/{category}/{slug}', name: 'gallery_media', methods: ['GET'], condition: self::PREFIX_CONDITION)]
    public function media(string $category, string $slug, Request $request): Response
    {
        [$category, $media] = $this->resolveCategoryAndMedia($category, $slug);

        // Which gallery the visitor is walking through, when it isn't the one holding the photo: a media opened from the last additions belongs to a category of its own, and its neighbours there are the ones just added, not the ones filed next to it (see GalleryAutomaticProvider)
        // The url stays the media's own, the same one an image search shows: where the visitor came from is a parameter over it, not a second path to the same photo
        $browsedFrom = $this->browsedFrom($request);
        $previousNext = $browsedFrom instanceof GalleryCategory ? $this->automaticProvider->findPreviousAndNext($media, $browsedFrom) : null;

        return $this->render('@c975LGallery/gallery/media.html.twig', [
            'category' => $category,
            // Null again when the media has since left the gallery it was opened from: the page is then browsed as its own category's, which is where it will still be tomorrow
            'browsedFrom' => null === $previousNext ? null : $browsedFrom,
            'categoriesCount' => $this->categoryRepository->countVisible(),
            'media' => $media,
            'previousNext' => $previousNext ?? $this->mediaRepository->findPreviousAndNext($media),
        ]);
    }

    // The gallery named by the "from" parameter, and only when it is the automatic one: every other category holds its medias, so browsing one of them is already what the url says
    private function browsedFrom(Request $request): ?GalleryCategory
    {
        $slug = $request->query->getString('from');
        if ('' === $slug) {
            return null;
        }

        $category = $this->categoryRepository->findOneBySlug($slug);
        if (!$category instanceof GalleryCategory || !$category->isAutomatic() || $category->isDeleted() || $category->isHidden()) {
            return null;
        }

        // The breadcrumb prints what it holds, which it only has once handed the list it shows
        $this->automaticProvider->hydrate([$category]);

        return $category;
    }

    // A trashed category answers 410 rather than 404, the same way SiteBundle serves a trashed Page: it says the url held something and no longer does, which a search engine acts on far faster than on a 404 - and it only lasts as long as the category can still be restored, deletePermanently() replacing it with a "gone" Redirect that says it for good (see GalleryCategoryCrudController)
    // A hidden one answers 404, exactly as a hidden media does below and for the same reason: masking is reversible, and a crawler must not be told anything a change of mind would have to be taken back. The medias it holds answer 404 with it, their pages being resolved through here
    private function resolveCategory(string $slug): GalleryCategory
    {
        $category = $this->categoryRepository->findOneBySlug($slug);

        if (null === $category || $category->isHidden()) {
            throw new NotFoundHttpException('Gallery category not found');
        }

        if ($category->isDeleted()) {
            throw new GoneHttpException();
        }

        return $category;
    }

    // Both segments are matched at once, the media's slug only being unique within its category (see GalleryMediaSlugger) - which is also what keeps a media from being browsed under an arbitrary category slug
    private function resolveCategoryAndMedia(string $categorySlug, string $slug): array
    {
        $category = $this->resolveCategory($categorySlug);
        $media = $this->mediaRepository->findOneBySlugInCategory($category, $slug);

        // A hidden media answers 404 and not 410: 410 says "this was here and is gone for good", which is what the trash means. Hiding is reversible, and nothing should be told to a crawler that a change of mind would have to be taken back
        if (!$media instanceof GalleryMedia || $media->isHidden()) {
            throw new NotFoundHttpException('Gallery media not found');
        }

        if ($media->isDeleted()) {
            throw new GoneHttpException();
        }

        return [$category, $media];
    }
}
