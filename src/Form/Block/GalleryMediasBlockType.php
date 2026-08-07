<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Form\Block;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class GalleryMediasBlockType extends AbstractType
{
    public function __construct(
        private readonly GalleryCategoryRepository $categoryRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // The slug is stored, not the id: it is this bundle's natural key everywhere else (public urls, import matching), so a block survives an export/import to another site the same way a category does
            ->add('categorySlug', ChoiceType::class, [
                'label' => 'label.block_category',
                'choices' => $this->categoryChoices(),
                'placeholder' => 'label.block_category_placeholder',
                'required' => true,
                'constraints' => [new NotBlank()],
                'choice_translation_domain' => false,
            ])
            ->add('max', IntegerType::class, [
                'label' => 'label.block_max_medias',
                'required' => false,
                'attr' => ['min' => 1],
            ])
            // Only meaningful together with a maximum: it decides which medias of the category that maximum keeps, the block being uncached so the draw is renewed at every render
            ->add('random', CheckboxType::class, [
                'label' => 'label.block_random',
                'required' => false,
            ])
            ->add('displayMore', CheckboxType::class, [
                'label' => 'label.block_display_more',
                'required' => false,
            ])
        ;
    }

    // A category deleted or renamed since simply drops out of the list, and the block has to be re-pointed before its page can be saved again - better than silently rendering nothing
    /**
     * @return array<string, string>
     */
    private function categoryChoices(): array
    {
        $categories = $this->categoryRepository->findAllOrdered();

        // Choices are keyed by label, so two categories sharing a title would collapse into one entry and silently drop the other from the list: a duplicated title is disambiguated by the slug, which is unique by definition
        $titleCounts = array_count_values(array_map(static fn (GalleryCategory $category): string => (string) $category->getTitle(), $categories));

        $choices = [];
        foreach ($categories as $category) {
            $title = (string) $category->getTitle();
            $label = $titleCounts[$title] > 1 ? $title . ' (' . $category->getSlug() . ')' : $title;
            $choices[$label] = (string) $category->getSlug();
        }

        return $choices;
    }

    // BlockType translates the embedded data form in the "ui" domain: without this, every label below would be looked up there and rendered raw
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'gallery']);
    }
}
