<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Form\Block;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Form\Block\GalleryMediasBlockType;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GalleryMediasBlockTypeTest extends TestCase
{
    // The stored value is the slug, this bundle's natural key everywhere else, so a block survives an export/import to another site
    public function testBuildFormOffersEveryCategoryBySlug(): void
    {
        $added = $this->buildForm([(new GalleryCategory())->setTitle('Été 2026')->setSlug('ete-2026')]);

        $this->assertSame(ChoiceType::class, $added['categorySlug']['type']);
        $this->assertSame(['Été 2026' => 'ete-2026'], $added['categorySlug']['options']['choices']);
        $this->assertTrue($added['categorySlug']['options']['required']);
        // Category titles are content, never translation keys
        $this->assertFalse($added['categorySlug']['options']['choice_translation_domain']);
    }

    // Choices are keyed by label: without disambiguation two categories sharing a title would collapse into a single entry, and one of them would simply not be offered
    public function testBuildFormDisambiguatesCategoriesSharingATitleWithTheirSlug(): void
    {
        $added = $this->buildForm([
            (new GalleryCategory())->setTitle('Photos')->setSlug('photos'),
            (new GalleryCategory())->setTitle('Vidéos')->setSlug('videos'),
            (new GalleryCategory())->setTitle('Photos')->setSlug('photos-2026'),
        ]);

        $this->assertSame([
            'Photos (photos)' => 'photos',
            'Vidéos' => 'videos',
            'Photos (photos-2026)' => 'photos-2026',
        ], $added['categorySlug']['options']['choices']);
    }

    public function testBuildFormAddsTheMaxRandomAndDisplayMoreFields(): void
    {
        $added = $this->buildForm([]);

        $this->assertSame(IntegerType::class, $added['max']['type']);
        $this->assertFalse($added['max']['options']['required']);
        $this->assertSame(CheckboxType::class, $added['random']['type']);
        $this->assertFalse($added['random']['options']['required']);
        $this->assertSame(CheckboxType::class, $added['displayMore']['type']);
        $this->assertFalse($added['displayMore']['options']['required']);
    }

    public function testBuildFormOffersNoChoiceWithoutAnyCategory(): void
    {
        $added = $this->buildForm([]);

        $this->assertSame([], $added['categorySlug']['options']['choices']);
    }

    // BlockType translates the embedded data form in the "ui" domain, where none of these labels exist
    public function testConfigureOptionsSetsTheGalleryTranslationDomain(): void
    {
        $resolver = new OptionsResolver();
        $this->type([])->configureOptions($resolver);

        $this->assertSame('gallery', $resolver->resolve()['translation_domain']);
    }

    private function buildForm(array $categories): array
    {
        $added = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $type = null, array $options = []) use (&$added, $builder) {
            $added[$name] = ['type' => $type, 'options' => $options];

            return $builder;
        });

        $this->type($categories)->buildForm($builder, []);

        return $added;
    }

    private function type(array $categories): GalleryMediasBlockType
    {
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('findAllOrdered')->willReturn($categories);

        return new GalleryMediasBlockType($repository);
    }
}
