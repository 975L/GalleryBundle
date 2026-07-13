<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Form;

use c975L\GalleryBundle\Entity\GalleryCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Not bound to any single entity (data_class null): "category"/"credits"/"rightsReserved" are applied
// to every row of "photos" by GalleryPhotoUploadController itself, not mapped here - each row only
// carries what actually varies per photo (file, alt), see GalleryPhotoUploadRowType
class GalleryPhotoBatchUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', EntityType::class, [
                'label' => 'label.gallery_category',
                'class' => GalleryCategory::class,
                'choice_label' => 'title',
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('c')
                    ->where('c.gallery = :gallery')
                    ->setParameter('gallery', $options['gallery'])
                    ->orderBy('c.position', 'ASC'),
                'placeholder' => 'label.gallery_uncategorized',
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
            ->add('photos', CollectionType::class, [
                'label' => 'label.gallery_photos',
                'entry_type' => GalleryPhotoUploadRowType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'gallery',
        ]);
        $resolver->setRequired('gallery');
    }
}
