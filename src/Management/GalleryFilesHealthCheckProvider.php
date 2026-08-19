<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryMediaCrudController;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\UiBundle\Management\AbstractDeclaredFilesHealthCheckProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

// The files this bundle's own rows declare: each media's image, and the video of the ones hosting their own. Only the file the row names is looked for, never the sizes derived from it (see GalleryMedia::getThumbnailFilename()): those are rebuilt from the stored image, where a file a row names is one nothing can bring back
class GalleryFilesHealthCheckProvider extends AbstractDeclaredFilesHealthCheckProvider
{
    // Named here rather than restated as a literal wherever a row of this kind is picked out
    public const string KIND = 'files-gallery';

    public function __construct(
        private readonly GalleryMediaRepository $galleryMediaRepository,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        ConfigServiceInterface $configService,
        TranslatorInterface $translator,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ) {
        parent::__construct($configService, $translator, $projectDir);
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    protected function declaredFiles(): iterable
    {
        foreach ($this->galleryMediaRepository->findWithFilename() as $media) {
            $editUrl = $this->editUrl($media);

            yield [
                'filename' => (string) $media->getFilename(),
                'label' => $this->label($media),
                'editUrl' => $editUrl,
            ];

            // Two rows for one media, so the dashboard says which of its two files went missing rather than which media is incomplete
            yield [
                'filename' => (string) $media->getVideoFilename(),
                'label' => $this->label($media),
                'editUrl' => $editUrl,
            ];
        }
    }

    // The category the media belongs to is part of what names it: a gallery holds thousands of them, and a title alone ("IMG 1234") tells two apart in no useful way
    private function label(GalleryMedia $media): string
    {
        $category = $media->getCategory()?->getSlug();
        $title = (string) ($media->getTitle() ?: $media->getSlug());

        return null === $category ? $title : $category . ' / ' . $title;
    }

    private function editUrl(GalleryMedia $media): ?string
    {
        return null === $media->getId() ? null : $this->adminUrlGenerator
            ->unsetAll()
            ->setController(GalleryMediaCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($media->getId())
            ->generateUrl();
    }
}
