<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Form;

use c975L\GalleryBundle\Entity\GalleryPhoto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;

// One row of GalleryPhotoBatchUploadType's "photos" collection - credits/category/rightsReserved are shared across the whole batch (set on the parent form, see GalleryPhotoUploadController), only the file itself and its alt text vary row to row
class GalleryPhotoUploadRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', VichImageType::class, [
                'label' => false,
                // Not required: an empty added-then-untouched row must not block submission of the others - GalleryPhotoUploadController skips any row with no file instead
                'required' => false,
                'allow_delete' => false,
                'download_label' => false,
            ])
            ->add('alt', TextType::class, [
                'label' => 'label.alt_text',
                'required' => false,
            ])
            // Ignored while the batch's own type stays "image", which is the whole point of keeping it optional here rather than showing a second screen for videos
            ->add('externalId', TextType::class, [
                'label' => 'label.gallery_external_id',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GalleryPhoto::class,
            'translation_domain' => 'gallery',
        ]);
    }
}
