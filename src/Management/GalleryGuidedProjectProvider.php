<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\GuidedProjectProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

// This bundle's guided projects, running the 5000 block GuidedProjectProviderInterface reserves them - the same docblock stating every other bundle's, so a range is read there rather than recopied here. Only the opening step of each carries an url: from there the parcours walks the screen the user has been sent to, highlighting the button or the field they are meant to use next - one they click themselves, which brings the panel back on that very step (see ConfigBundle's assets/js/guided-project.js)
class GalleryGuidedProjectProvider implements GuidedProjectProviderInterface
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getGuidedProjects(): array
    {
        return [
            $this->galleryCreationProject(),
            $this->mediasArrangementProject(),
            $this->mediaDetailProject(),
            $this->trashProject(),
            $this->mediasRecoveryProject(),
            $this->latestGalleryProject(),
        ];
    }

    // A gallery and its first photographs in one go: the creation form carries the whole batch, which is the only screen doing both
    private function galleryCreationProject(): array
    {
        return [
            'slug' => 'gallery-creation',
            'label' => 'label.guided_project_gallery_creation',
            'description' => 'description.guided_project_gallery_creation',
            'translation_domain' => 'gallery',
            'order' => 5010,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_gallery_creation_open',
                    'description' => 'description.guided_step_gallery_creation_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_new',
                    'description' => 'description.guided_step_gallery_creation_new',
                    'highlight' => '.action-new',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_title',
                    'description' => 'description.guided_step_gallery_creation_title',
                    'highlight' => '#GalleryCategory_title',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_files',
                    'description' => 'description.guided_step_gallery_creation_files',
                    'highlight' => '#GalleryCategory_files',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_title_root',
                    'description' => 'description.guided_step_gallery_creation_title_root',
                    'highlight' => '#GalleryCategory_titleRoot',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_done',
                    'description' => 'description.guided_step_gallery_creation_done',
                ],
            ],
        ];
    }

    // What a gallery looks like is decided on its own edit screen, where the order, the cover and the batch edits all save as they go
    private function mediasArrangementProject(): array
    {
        return [
            'slug' => 'gallery-medias-arrangement',
            'label' => 'label.guided_project_gallery_medias_arrangement',
            'description' => 'description.guided_project_gallery_medias_arrangement',
            'translation_domain' => 'gallery',
            'order' => 5020,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_open',
                    'description' => 'description.guided_step_gallery_medias_arrangement_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_edit',
                    'description' => 'description.guided_step_gallery_medias_arrangement_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_upload',
                    'description' => 'description.guided_step_gallery_medias_arrangement_upload',
                    'highlight' => '[data-gallery-upload-medias]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_sort',
                    'description' => 'description.guided_step_gallery_medias_arrangement_sort',
                    'highlight' => '[data-gallery-media-sort-handle]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_cover',
                    'description' => 'description.guided_step_gallery_medias_arrangement_cover',
                    'highlight' => '[data-gallery-cover-radio]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_selection',
                    'description' => 'description.guided_step_gallery_medias_arrangement_selection',
                    'highlight' => '[data-gallery-media-selection-target="toggle"]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_done',
                    'description' => 'description.guided_step_gallery_medias_arrangement_done',
                ],
            ],
        ];
    }

    // A media has one screen of its own, reached from the gallery holding it - it is where a caption is written and where a video is attached
    private function mediaDetailProject(): array
    {
        return [
            'slug' => 'gallery-media-detail',
            'label' => 'label.guided_project_gallery_media_detail',
            'description' => 'description.guided_project_gallery_media_detail',
            'translation_domain' => 'gallery',
            'order' => 5030,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_gallery_media_detail_open',
                    'description' => 'description.guided_step_gallery_media_detail_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_edit',
                    'description' => 'description.guided_step_gallery_media_detail_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_thumbnail',
                    'description' => 'description.guided_step_gallery_media_detail_thumbnail',
                    'highlight' => '.management-media-grid__item',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_title',
                    'description' => 'description.guided_step_gallery_media_detail_title',
                    'highlight' => '#GalleryMedia_title',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_credits',
                    'description' => 'description.guided_step_gallery_media_detail_credits',
                    'highlight' => '#GalleryMedia_credits',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_video',
                    'description' => 'description.guided_step_gallery_media_detail_video',
                    'highlight' => '#GalleryMedia_externalUrl',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_done',
                    'description' => 'description.guided_step_gallery_media_detail_done',
                ],
            ],
        ];
    }

    // Nothing is lost in one click any more: a gallery put aside stays whole, and the way back is on the same screen - the parcours stops before the permanent deletion, which is held one role higher and would highlight a button an editor never sees
    private function trashProject(): array
    {
        return [
            'slug' => 'gallery-trash',
            'label' => 'label.guided_project_gallery_trash',
            'description' => 'description.guided_project_gallery_trash',
            'translation_domain' => 'gallery',
            'order' => 5040,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_gallery_trash_open',
                    'description' => 'description.guided_step_gallery_trash_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_trash_delete',
                    'description' => 'description.guided_step_gallery_trash_delete',
                    'highlight' => '.action-delete',
                ],
                [
                    'label' => 'label.guided_step_gallery_trash_switch',
                    'description' => 'description.guided_step_gallery_trash_switch',
                    'highlight' => '.action-trash',
                ],
                [
                    'label' => 'label.guided_step_gallery_trash_restore',
                    'description' => 'description.guided_step_gallery_trash_restore',
                    'highlight' => '.action-restore',
                ],
                [
                    'label' => 'label.guided_step_gallery_trash_done',
                    'description' => 'description.guided_step_gallery_trash_done',
                ],
            ],
        ];
    }

    // The files themselves come back out of the site, as one archive - the photos' own trash is told rather than walked, its way in only showing once something has been put there
    private function mediasRecoveryProject(): array
    {
        return [
            'slug' => 'gallery-medias-recovery',
            'label' => 'label.guided_project_gallery_medias_recovery',
            'description' => 'description.guided_project_gallery_medias_recovery',
            'translation_domain' => 'gallery',
            'order' => 5050,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_gallery_medias_recovery_open',
                    'description' => 'description.guided_step_gallery_medias_recovery_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_recovery_edit',
                    'description' => 'description.guided_step_gallery_medias_recovery_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_recovery_select',
                    'description' => 'description.guided_step_gallery_medias_recovery_select',
                    'highlight' => '[data-gallery-media-selection-target="toggle"]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_recovery_download',
                    'description' => 'description.guided_step_gallery_medias_recovery_download',
                    'highlight' => '[data-gallery-download-medias]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_recovery_trash',
                    'description' => 'description.guided_step_gallery_medias_recovery_trash',
                ],
            ],
        ];
    }

    // The one gallery holding no photo of its own: it is arranged by nobody, so the arrangement parcours stays away from it and this one says what it is instead
    private function latestGalleryProject(): array
    {
        return [
            'slug' => 'gallery-latest',
            'label' => 'label.guided_project_gallery_latest',
            'description' => 'description.guided_project_gallery_latest',
            'translation_domain' => 'gallery',
            'order' => 5060,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_gallery_latest_open',
                    'description' => 'description.guided_step_gallery_latest_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_latest_edit',
                    'description' => 'description.guided_step_gallery_latest_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_latest_days',
                    'description' => 'description.guided_step_gallery_latest_days',
                    'highlight' => '.management-media-grid',
                ],
                [
                    'label' => 'label.guided_step_gallery_latest_selection',
                    'description' => 'description.guided_step_gallery_latest_selection',
                    'highlight' => '[data-gallery-media-selection-target="toggle"]',
                ],
                [
                    'label' => 'label.guided_step_gallery_latest_done',
                    'description' => 'description.guided_step_gallery_latest_done',
                ],
            ],
        ];
    }

    // The role every gallery management screen sits behind, the same ConfigBundle entry its controllers read (see GalleryCategoryCrudController) - a parcours walking screens the user can't open reads as a broken one
    private function roleNeeded(): string
    {
        return (string) $this->configService->get('site-role-editor');
    }

    // Every project opens on the categories, the single sidebar entry of the whole feature (see MenuProvider)
    private function indexUrl(): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(GalleryCategoryCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }
}
