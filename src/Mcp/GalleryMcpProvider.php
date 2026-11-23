<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Mcp;

use c975L\ConfigBundle\Mcp\McpTool;
use c975L\ConfigBundle\Mcp\McpToolProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryCategoryUrls;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

// The galleries and their medias on the site's MCP endpoint, for an account that edits them on the back office: listed, a hidden gallery added and the texts written with the back office's own rules, uploading, showing and deleting left to a human
class GalleryMcpProvider implements McpToolProviderInterface
{
    // Galleries or medias a list gives at most
    public const int LIMIT = 50;

    public function __construct(
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryMediaRepository $mediaRepository,
        private readonly GalleryCategoryUrls $categoryUrls,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigServiceInterface $configService,
        private readonly Security $security,
    ) {
    }

    public function getMcpKey(): string
    {
        return 'gallery';
    }

    public function getMcpInstructions(): string
    {
        return 'Galleries hold the medias (photos and videos) of the site. A gallery added by an agent is hidden: a human uploads its medias and shows it from the back office. A media title is also its alt text. An edit of a gallery or a media already shown is on the site at once.';
    }

    public function getMcpTools(): iterable
    {
        yield new McpTool('gallery_list', 'Galleries', 'The galleries whose title holds the search words, every gallery without them, hidden ones included, in alphabetical order.', $this->list(...));
        yield new McpTool('gallery_medias', 'Medias', 'The medias of a gallery, or of every gallery, whose title holds the search words, hidden ones included, the latest first.', $this->medias(...));
        yield new McpTool('gallery_create', 'Add a hidden gallery', 'Adds a hidden gallery with its title and an optional summary, its slug made from the title.', $this->create(...), true);
        yield new McpTool('gallery_edit', 'Write a gallery', 'Changes only the title or summary given of a gallery; a new title rebuilds its slug and redirects the old url, as the back office does; at once on the site when the gallery is shown.', $this->edit(...), true);
        yield new McpTool('gallery_media_edit', 'Write a media', 'Changes only the title (also its alt text), description or credits given of a media, its url kept; at once on the site when it is shown.', $this->editMedia(...), true);
    }

    // The galleries whose title holds the words, every one without them, the trash left aside
    /**
     * @param ?string $search words looked for in the titles
     *
     * @return list<array{id: ?int, title: ?string, slug: ?string, hidden: bool, automatic: ?string, medias: int, summary: ?string}>
     */
    public function list(?string $search = null): array
    {
        $this->guard();
        $builder = $this->categoryRepository->createQueryBuilder('c')
            ->andWhere('c.isDeleted = false')
            ->orderBy('c.title', \SortDirection::Ascending)
            ->setMaxResults(self::LIMIT);
        if ('' !== trim((string) $search)) {
            $builder->andWhere('c.title LIKE :words')->setParameter('words', $this->like($search));
        }
        $categories = $builder->getQuery()->getResult();
        $counts = $this->mediaRepository->summarizeVisibleByCategories($categories);

        return array_map(static fn (GalleryCategory $category): array => [
            'id' => $category->getId(),
            'title' => $category->getTitle(),
            'slug' => $category->getSlug(),
            'hidden' => $category->isHidden(),
            'automatic' => $category->getAutomaticKind(),
            'medias' => $counts[(int) $category->getId()]['count'] ?? 0,
            'summary' => $category->getSummarySocialNetwork(),
        ], $categories);
    }

    // The medias of a gallery or of all, whose title holds the words, the trash left aside
    /**
     * @param ?int    $gallery id of the gallery, every gallery without it
     * @param ?string $search  words looked for in the titles
     *
     * @return list<array{id: ?int, title: ?string, description: ?string, credits: ?string, slug: ?string, type: string, hidden: bool, gallery: ?int, galleryTitle: ?string}>
     */
    public function medias(?int $gallery = null, ?string $search = null): array
    {
        $builder = $this->mediaRepository->createQueryBuilder('m')
            ->innerJoin('m.category', 'c')
            ->addSelect('c')
            ->andWhere('m.isDeleted = false')
            ->andWhere('c.isDeleted = false')
            ->orderBy('m.createdAt', \SortDirection::Descending)
            ->addOrderBy('m.id', \SortDirection::Descending)
            ->setMaxResults(self::LIMIT);
        if (null !== $gallery) {
            $builder->andWhere('c = :gallery')->setParameter('gallery', $this->foundCategory($gallery));
        } else {
            $this->guard();
        }
        if ('' !== trim((string) $search)) {
            $builder->andWhere('m.title LIKE :words')->setParameter('words', $this->like($search));
        }

        return array_map($this->mediaSummary(...), $builder->getQuery()->getResult());
    }

    // A hidden gallery added, its slug built from the title and its freed url released as the back office does, a slug taken refused rather than suffixed
    /**
     * @param string  $title   title of the gallery
     * @param ?string $summary its summary, html allowed
     *
     * @return array{id: ?int, slug: ?string}
     */
    public function create(string $title, ?string $summary = null): array
    {
        $this->guard();
        $title = trim($title);
        $category = new GalleryCategory()
            ->setTitle($title)
            ->setSlug($this->freeSlug($title))
            ->setSummarySocialNetwork($this->text($summary))
            ->setHidden(true);
        $this->categoryUrls->release($this->entityManager, $category);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return ['id' => $category->getId(), 'slug' => $category->getSlug()];
    }

    // A gallery's title or summary changed, only those given, an empty summary clearing it, a new title rebuilding the slug and redirecting the old url as the back office does
    /**
     * @param int     $gallery id of the gallery
     * @param ?string $title   new title
     * @param ?string $summary new summary, html allowed, empty to clear it
     *
     * @return array{id: ?int}
     */
    public function edit(int $gallery, ?string $title = null, ?string $summary = null): array
    {
        $found = $this->foundCategory($gallery);
        $title = trim((string) $title);
        if ('' !== $title && $title !== $found->getTitle()) {
            $oldSlug = (string) $found->getSlug();
            $slug = $this->freeSlug($title, $oldSlug);
            $found->setTitle($title)->setSlug($slug);
            $this->categoryUrls->redirectRename($this->entityManager, $oldSlug, $slug);
        }
        if (null !== $summary) {
            $found->setSummarySocialNetwork($this->text($summary));
        }
        $this->entityManager->flush();

        return ['id' => $found->getId()];
    }

    // A media's title, description or credits changed, only those given, an empty description or credits clearing it, the slug kept as the back office keeps it
    /**
     * @param int     $media       id of the media
     * @param ?string $title       new title, also its alt text
     * @param ?string $description new caption, empty to clear it
     * @param ?string $credits     new credits, empty to clear them
     *
     * @return array{id: ?int}
     */
    public function editMedia(int $media, ?string $title = null, ?string $description = null, ?string $credits = null): array
    {
        $found = $this->foundMedia($media);

        // Every text checked before any is written, so a refused one leaves the media whole for a later flush of the request
        $newTitle = $this->fitting($this->text($title), 255);
        $newCredits = $this->fitting($this->text($credits), 255);
        if (null !== $newTitle) {
            $found->setTitle($newTitle);
        }
        if (null !== $description) {
            $found->setDescription($this->text($description));
        }
        if (null !== $credits) {
            $found->setCredits($newCredits);
        }
        $this->entityManager->flush();

        return ['id' => $found->getId()];
    }

    // The galleries are the editor's business, as on the back office
    private function guard(): void
    {
        if (!$this->security->isGranted((string) $this->configService->get('site-role-editor'))) {
            throw new ToolCallException('This account may not work on the galleries.');
        }
    }

    // A gallery by its id, one in the trash answering as unknown
    private function foundCategory(int $id): GalleryCategory
    {
        $this->guard();
        $category = $this->categoryRepository->find($id);
        if (!$category instanceof GalleryCategory || $category->isDeleted()) {
            throw new ToolCallException(sprintf('No gallery %d.', $id));
        }

        return $category;
    }

    // A media by its id, one in the trash or in a gallery in the trash answering as unknown
    private function foundMedia(int $id): GalleryMedia
    {
        $this->guard();
        $media = $this->mediaRepository->find($id);
        if (!$media instanceof GalleryMedia || $media->isDeleted() || true === $media->getCategory()?->isDeleted()) {
            throw new ToolCallException(sprintf('No media %d.', $id));
        }

        return $media;
    }

    // The slug a title gives, refused as the category form refuses it: empty, longer than its column or held by another gallery, the trash included
    private function freeSlug(string $title, ?string $current = null): string
    {
        $slug = $this->categoryUrls->slug($this->fitting($title, 255) ?? '');
        if ('' === $slug) {
            throw new ToolCallException('The title is empty.');
        }
        $this->fitting($slug, 100);
        if ($slug !== $current && $this->categoryRepository->findOneBySlug($slug) instanceof GalleryCategory) {
            throw new ToolCallException(sprintf('A gallery already holds the slug "%s".', $slug));
        }

        return $slug;
    }

    // A text refused when longer than its column, as the back office's length constraint refuses it
    private function fitting(?string $value, int $max): ?string
    {
        if (mb_strlen((string) $value) > $max) {
            throw new ToolCallException(sprintf('A text is longer than %d characters.', $max));
        }

        return $value;
    }

    // A media in a list
    /** @return array{id: ?int, title: ?string, description: ?string, credits: ?string, slug: ?string, type: string, hidden: bool, gallery: ?int, galleryTitle: ?string} */
    private function mediaSummary(GalleryMedia $media): array
    {
        return [
            'id' => $media->getId(),
            'title' => $media->getTitle(),
            'description' => $media->getDescription(),
            'credits' => $media->getCredits(),
            'slug' => $media->getSlug(),
            'type' => $media->getMediaType(),
            'hidden' => $media->isHidden(),
            'gallery' => $media->getCategory()?->getId(),
            'galleryTitle' => $media->getCategory()?->getTitle(),
        ];
    }

    // A text trimmed, null when empty
    private function text(?string $value): ?string
    {
        return '' === trim((string) $value) ? null : trim((string) $value);
    }

    // The search words as a LIKE pattern, their wildcards escaped
    private function like(?string $search): string
    {
        return '%' . addcslashes(trim((string) $search), '%_') . '%';
    }
}
