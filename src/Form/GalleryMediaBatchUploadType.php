<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Form;

use c975L\GalleryBundle\Service\UploadLimits;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

// Not bound to any single entity (data_class null): one GalleryMedia is built per uploaded file by GalleryMediaUploadController, with the credits and rights applied to the whole batch - anything that varies media by media is retouched afterwards in the media edit screen
class GalleryMediaBatchUploadType extends AbstractType
{
    public function __construct(
        private readonly UploadLimits $uploadLimits,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Shown, not picked: medias are only ever added from within a category, which the url carries (see GalleryMediaUploadController) - unmapped and disabled, so nothing it holds is ever submitted
            ->add('category', TextType::class, [
                'label' => 'label.gallery_category',
                'mapped' => false,
                'disabled' => true,
                'data' => $options['category_title'],
            ])
            ->add('files', FileType::class, [
                'label' => 'label.gallery_files',
                // States the three ceilings a batch meets, php's own included - two of them are enforced by truncating the request rather than by refusing it
                'help' => $this->translator->trans('label.gallery_batch_files_help', [
                    '%files%' => $this->uploadLimits->getMaxFiles(),
                    '%size%' => $this->uploadLimits->toMegabytes($this->uploadLimits->getMaxFileSize()),
                    '%total%' => $this->uploadLimits->toMegabytes($this->uploadLimits->getMaxBatchSize()),
                ], 'gallery'),
                'multiple' => true,
                'attr' => [
                    'data-gallery-upload-limits-target' => 'input',
                    'data-action' => 'change->gallery-upload-limits#check',
                ],
                'constraints' => [new All([new Image(maxSize: UploadLimits::MAX_FILE_SIZE)])],
            ])
            // Seeds every title of the batch, numbered from where the category leaves off - the one field that spares retouching fifty medias named IMG_1234, and the only moment it costs nothing, a title typed afterwards being a title typed one media at a time
            ->add('titleRoot', TextType::class, [
                'label' => 'label.gallery_title_root',
                'help' => 'label.gallery_batch_title_root_help',
                'required' => false,
            ])
            ->add('credits', TextType::class, [
                'label' => 'label.credits',
                'help' => 'label.gallery_batch_credits_help',
                'required' => false,
            ])
            ->add('rightsReserved', CheckboxType::class, [
                'label' => 'label.rights_reserved',
                'required' => false,
                'label_attr' => ['class' => 'checkbox-switch'],
            ])
            // Off by default: an original is the whole uploaded file, and a batch of fifty photos is the moment to decide once whether the site is to hold them, not a default that quietly fills a disk
            ->add('keepOriginals', CheckboxType::class, [
                'label' => 'label.gallery_keep_originals',
                'help' => 'label.gallery_batch_keep_originals_help',
                'required' => false,
                'label_attr' => ['class' => 'checkbox-switch'],
            ])
            // Stamped into the pixels of every derivative, so it is answered at upload time or not at all - the help says where the two signatures are uploaded, this screen being where an admin finds out they exist
            ->add('watermark', CheckboxType::class, [
                'label' => 'label.gallery_watermark',
                'help' => 'label.gallery_batch_watermark_help',
                'required' => false,
                'label_attr' => ['class' => 'checkbox-switch'],
            ])
            // Empty takes the corner set site-wide, which is what a batch normally wants - the choice is here for the gallery whose photos all leave the same corner busy
            ->add('watermarkPosition', ChoiceType::class, [
                'label' => 'label.gallery_watermark_position',
                'help' => 'label.gallery_batch_watermark_position_help',
                'required' => false,
                'placeholder' => 'label.gallery_watermark_position_default',
                'choices' => [
                    'label.gallery_watermark_top_left' => VichWatermarkableInterface::POSITION_TOP_LEFT,
                    'label.gallery_watermark_top_right' => VichWatermarkableInterface::POSITION_TOP_RIGHT,
                    'label.gallery_watermark_bottom_right' => VichWatermarkableInterface::POSITION_BOTTOM_RIGHT,
                    'label.gallery_watermark_bottom_left' => VichWatermarkableInterface::POSITION_BOTTOM_LEFT,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'gallery',
            // Declared on the form itself rather than in the template, so an app overriding gallery_media_upload.html.twig keeps the check - the messages are translated here, javascript having no translator of its own
            'attr' => [
                'data-controller' => 'gallery-upload-limits',
                'data-gallery-upload-limits-max-files-value' => $this->uploadLimits->getMaxFiles(),
                'data-gallery-upload-limits-max-file-size-value' => $this->uploadLimits->getMaxFileSize(),
                'data-gallery-upload-limits-max-batch-size-value' => $this->uploadLimits->getMaxBatchSize(),
                'data-gallery-upload-limits-files-message-value' => $this->translator->trans('label.gallery_upload_too_many_files', [], 'gallery'),
                'data-gallery-upload-limits-size-message-value' => $this->translator->trans('label.gallery_upload_file_too_large', [], 'gallery'),
                'data-gallery-upload-limits-batch-message-value' => $this->translator->trans('label.gallery_upload_batch_too_large', [], 'gallery'),
            ],
        ]);
        $resolver->setRequired('category_title');
        $resolver->setAllowedTypes('category_title', 'string');
    }
}
