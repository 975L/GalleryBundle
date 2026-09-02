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
use c975L\GalleryBundle\Controller\Management\GalleryPrintFormatCrudController;
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
        $projects = [
            $this->galleryCreationProject(),
            $this->mediasArrangementProject(),
            $this->mediasMoveProject(),
            $this->mediaDetailProject(),
            $this->trashProject(),
            $this->mediasRecoveryProject(),
            $this->latestGalleryProject(),
        ];

        // Offered only where the screens it walks are, the print ones being hidden from the menu on a site that does not sell prints (see MenuProvider)
        if (true === $this->configService->get('gallery-print-enabled')) {
            $projects[] = $this->printSetupProject();
        }

        return $projects;
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
                    'narration' => 'narration.guided_step_gallery_creation_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_new',
                    'description' => 'description.guided_step_gallery_creation_new',
                    'narration' => 'narration.guided_step_gallery_creation_new',
                    'highlight' => '.action-new',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_title',
                    'description' => 'description.guided_step_gallery_creation_title',
                    'narration' => 'narration.guided_step_gallery_creation_title',
                    'highlight' => '#GalleryCategory_title',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_files',
                    'description' => 'description.guided_step_gallery_creation_files',
                    'narration' => 'narration.guided_step_gallery_creation_files',
                    'highlight' => '#GalleryCategory_files',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_title_root',
                    'description' => 'description.guided_step_gallery_creation_title_root',
                    'narration' => 'narration.guided_step_gallery_creation_title_root',
                    'highlight' => '#GalleryCategory_titleRoot',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_save',
                    'narration' => 'narration.guided_step_gallery_creation_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    'label' => 'label.guided_step_gallery_creation_done',
                    'description' => 'description.guided_step_gallery_creation_done',
                    'narration' => 'narration.guided_step_gallery_creation_done',
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
                    'narration' => 'narration.guided_step_gallery_medias_arrangement_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_edit',
                    'description' => 'description.guided_step_gallery_medias_arrangement_edit',
                    'narration' => 'narration.guided_step_gallery_medias_arrangement_edit',
                    // The gallery that can be filled and not simply the first row: the automatic one carries neither the upload zone nor the handles the next steps point at, and is told apart by the very action it does not offer (see GalleryCategoryCrudController's "uploadMedias")
                    'highlight' => 'tr:has(.action-uploadMedias) .action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_upload',
                    'description' => 'description.guided_step_gallery_medias_arrangement_upload',
                    'narration' => 'narration.guided_step_gallery_medias_arrangement_upload',
                    'highlight' => '[data-gallery-upload-medias]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_sort',
                    'description' => 'description.guided_step_gallery_medias_arrangement_sort',
                    'narration' => 'narration.guided_step_gallery_medias_arrangement_sort',
                    'highlight' => '[data-gallery-media-sort-handle]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_cover',
                    'description' => 'description.guided_step_gallery_medias_arrangement_cover',
                    'narration' => 'narration.guided_step_gallery_medias_arrangement_cover',
                    'highlight' => '[data-gallery-cover-radio]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_selection',
                    'description' => 'description.guided_step_gallery_medias_arrangement_selection',
                    'narration' => 'narration.guided_step_gallery_medias_arrangement_selection',
                    'highlight' => '[data-gallery-media-selection-target="toggle"]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_arrangement_done',
                    'description' => 'description.guided_step_gallery_medias_arrangement_done',
                    'narration' => 'narration.guided_step_gallery_medias_arrangement_done',
                ],
            ],
        ];
    }

    // A gallery filled too fast is sorted out afterwards: the selection leaves for another gallery with its files, and the toolbar carrying it only shows once a second gallery exists to receive them
    private function mediasMoveProject(): array
    {
        return [
            'slug' => 'gallery-medias-move',
            'label' => 'label.guided_project_gallery_medias_move',
            'description' => 'description.guided_project_gallery_medias_move',
            'translation_domain' => 'gallery',
            'order' => 5025,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_gallery_medias_move_open',
                    'description' => 'description.guided_step_gallery_medias_move_open',
                    'narration' => 'narration.guided_step_gallery_medias_move_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_move_edit',
                    'description' => 'description.guided_step_gallery_medias_move_edit',
                    'narration' => 'narration.guided_step_gallery_medias_move_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_move_select',
                    'description' => 'description.guided_step_gallery_medias_move_select',
                    'narration' => 'narration.guided_step_gallery_medias_move_select',
                    'highlight' => '[data-gallery-media-selection-target="toggle"]',
                ],
                // The group is the only stable hook the toolbar offers, its controls being reached from it (see gallery_category_edit.html.twig)
                [
                    'label' => 'label.guided_step_gallery_medias_move_target',
                    'description' => 'description.guided_step_gallery_medias_move_target',
                    'narration' => 'narration.guided_step_gallery_medias_move_target',
                    'highlight' => '[data-gallery-move-medias] select',
                ],
                // Named rather than taken as "the input of the group", which the box naming a gallery created on the spot now comes before
                [
                    'label' => 'label.guided_step_gallery_medias_move_title_root',
                    'description' => 'description.guided_step_gallery_medias_move_title_root',
                    'narration' => 'narration.guided_step_gallery_medias_move_title_root',
                    'highlight' => '[data-gallery-move-medias] input[name="titleRoot"]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_move_move',
                    'description' => 'description.guided_step_gallery_medias_move_move',
                    'narration' => 'narration.guided_step_gallery_medias_move_move',
                    'highlight' => '[data-gallery-move-medias] button',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_move_done',
                    'description' => 'description.guided_step_gallery_medias_move_done',
                    'narration' => 'narration.guided_step_gallery_medias_move_done',
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
                    'narration' => 'narration.guided_step_gallery_media_detail_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_edit',
                    'description' => 'description.guided_step_gallery_media_detail_edit',
                    'narration' => 'narration.guided_step_gallery_media_detail_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_thumbnail',
                    'description' => 'description.guided_step_gallery_media_detail_thumbnail',
                    'narration' => 'narration.guided_step_gallery_media_detail_thumbnail',
                    'highlight' => '.management-media-grid__item',
                ],
                // The second way a media changes gallery, the selection of the categories screen being the first (see gallery-medias-move above and GalleryMediaCrudController::updateEntity)
                [
                    'label' => 'label.guided_step_gallery_media_detail_category',
                    'description' => 'description.guided_step_gallery_media_detail_category',
                    'narration' => 'narration.guided_step_gallery_media_detail_category',
                    'highlight' => '#GalleryMedia_category',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_title',
                    'description' => 'description.guided_step_gallery_media_detail_title',
                    'narration' => 'narration.guided_step_gallery_media_detail_title',
                    'highlight' => '#GalleryMedia_title',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_credits',
                    'description' => 'description.guided_step_gallery_media_detail_credits',
                    'narration' => 'narration.guided_step_gallery_media_detail_credits',
                    'highlight' => '#GalleryMedia_credits',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_video',
                    'description' => 'description.guided_step_gallery_media_detail_video',
                    'narration' => 'narration.guided_step_gallery_media_detail_video',
                    'highlight' => '#GalleryMedia_externalUrl',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_save',
                    'narration' => 'narration.guided_step_gallery_media_detail_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    'label' => 'label.guided_step_gallery_media_detail_done',
                    'description' => 'description.guided_step_gallery_media_detail_done',
                    'narration' => 'narration.guided_step_gallery_media_detail_done',
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
                    'narration' => 'narration.guided_step_gallery_trash_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_trash_delete',
                    'description' => 'description.guided_step_gallery_trash_delete',
                    'narration' => 'narration.guided_step_gallery_trash_delete',
                    'highlight' => '.action-delete',
                ],
                [
                    'label' => 'label.guided_step_gallery_trash_switch',
                    'description' => 'description.guided_step_gallery_trash_switch',
                    'narration' => 'narration.guided_step_gallery_trash_switch',
                    'highlight' => '.action-trash',
                ],
                [
                    'label' => 'label.guided_step_gallery_trash_restore',
                    'description' => 'description.guided_step_gallery_trash_restore',
                    'narration' => 'narration.guided_step_gallery_trash_restore',
                    'highlight' => '.action-restore',
                ],
                [
                    'label' => 'label.guided_step_gallery_trash_done',
                    'description' => 'description.guided_step_gallery_trash_done',
                    'narration' => 'narration.guided_step_gallery_trash_done',
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
                    'narration' => 'narration.guided_step_gallery_medias_recovery_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_recovery_edit',
                    'description' => 'description.guided_step_gallery_medias_recovery_edit',
                    'narration' => 'narration.guided_step_gallery_medias_recovery_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_recovery_select',
                    'description' => 'description.guided_step_gallery_medias_recovery_select',
                    'narration' => 'narration.guided_step_gallery_medias_recovery_select',
                    'highlight' => '[data-gallery-media-selection-target="toggle"]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_recovery_download',
                    'description' => 'description.guided_step_gallery_medias_recovery_download',
                    'narration' => 'narration.guided_step_gallery_medias_recovery_download',
                    'highlight' => '[data-gallery-download-medias]',
                ],
                [
                    'label' => 'label.guided_step_gallery_medias_recovery_trash',
                    'description' => 'description.guided_step_gallery_medias_recovery_trash',
                    'narration' => 'narration.guided_step_gallery_medias_recovery_trash',
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
                    'narration' => 'narration.guided_step_gallery_latest_open',
                    'url' => $this->indexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_latest_edit',
                    'description' => 'description.guided_step_gallery_latest_edit',
                    'narration' => 'narration.guided_step_gallery_latest_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_latest_days',
                    'description' => 'description.guided_step_gallery_latest_days',
                    'narration' => 'narration.guided_step_gallery_latest_days',
                    'highlight' => '.management-media-grid',
                ],
                [
                    'label' => 'label.guided_step_gallery_latest_selection',
                    'description' => 'description.guided_step_gallery_latest_selection',
                    'narration' => 'narration.guided_step_gallery_latest_selection',
                    'highlight' => '[data-gallery-media-selection-target="toggle"]',
                ],
                [
                    'label' => 'label.guided_step_gallery_latest_done',
                    'description' => 'description.guided_step_gallery_latest_done',
                    'narration' => 'narration.guided_step_gallery_latest_done',
                ],
            ],
        ];
    }

    // The only parcours not opening on the galleries: what is for sale is decided on the formats screen, and a photograph is only ticked "printable" once a format exists to sell it in
    private function printSetupProject(): array
    {
        return [
            'slug' => 'gallery-print-setup',
            'label' => 'label.guided_project_gallery_print_setup',
            'description' => 'description.guided_project_gallery_print_setup',
            'translation_domain' => 'gallery',
            'order' => 5070,
            'role' => $this->roleNeeded(),
            'steps' => [
                [
                    'label' => 'label.guided_step_gallery_print_setup_open',
                    'description' => 'description.guided_step_gallery_print_setup_open',
                    'narration' => 'narration.guided_step_gallery_print_setup_open',
                    'url' => $this->printFormatIndexUrl(),
                ],
                [
                    'label' => 'label.guided_step_gallery_print_setup_import',
                    'description' => 'description.guided_step_gallery_print_setup_import',
                    'narration' => 'narration.guided_step_gallery_print_setup_import',
                    // Only there where the lab publishes its range (see GalleryPrintFormatCrudController::configureActions); the step reads as well without it, telling a shop printing by hand to write its formats itself
                    'highlight' => '.action-importPrintCatalogue',
                ],
                [
                    'label' => 'label.guided_step_gallery_print_setup_edit',
                    'description' => 'description.guided_step_gallery_print_setup_edit',
                    'narration' => 'narration.guided_step_gallery_print_setup_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    'label' => 'label.guided_step_gallery_print_setup_price',
                    'description' => 'description.guided_step_gallery_print_setup_price',
                    'narration' => 'narration.guided_step_gallery_print_setup_price',
                    'highlight' => '#GalleryPrintFormat_price',
                ],
                [
                    'label' => 'label.guided_step_gallery_print_setup_published',
                    'description' => 'description.guided_step_gallery_print_setup_published',
                    'narration' => 'narration.guided_step_gallery_print_setup_published',
                    'highlight' => '#GalleryPrintFormat_published',
                ],
                [
                    'label' => 'label.guided_step_gallery_print_setup_done',
                    'description' => 'description.guided_step_gallery_print_setup_done',
                    'narration' => 'narration.guided_step_gallery_print_setup_done',
                ],
            ],
        ];
    }

    // The role every gallery management screen sits behind, the same ConfigBundle entry its controllers read (see GalleryCategoryCrudController) - a parcours walking screens the user can't open reads as a broken one
    private function roleNeeded(): string
    {
        return (string) $this->configService->get('site-role-editor');
    }

    // Every project about the galleries opens on the categories, the single sidebar entry of the whole feature (see MenuProvider)
    private function indexUrl(): string
    {
        return $this->crudIndexUrl(GalleryCategoryCrudController::class);
    }

    // Where the print parcours opens instead: the formats are what a shop writes first, an order screen having nothing to show until something has been sold
    private function printFormatIndexUrl(): string
    {
        return $this->crudIndexUrl(GalleryPrintFormatCrudController::class);
    }

    private function crudIndexUrl(string $controller): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController($controller)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }
}
