<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Form;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Form\GalleryPhotoBatchUploadType;
use c975L\GalleryBundle\Form\GalleryPhotoUploadRowType;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GalleryPhotoBatchUploadTypeTest extends TestCase
{
    private function buildFields(array $options): array
    {
        $added = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $type = null, array $fieldOptions = []) use (&$added, $builder) {
            $added[$name] = ['type' => $type, 'options' => $fieldOptions];

            return $builder;
        });

        (new GalleryPhotoBatchUploadType())->buildForm($builder, $options);

        return $added;
    }

    public function testBuildFormAddsCategoryCreditsRightsReservedAndPhotosFields(): void
    {
        $added = $this->buildFields(['gallery' => null]);

        $this->assertSame(EntityType::class, $added['category']['type']);
        $this->assertSame(GalleryCategory::class, $added['category']['options']['class']);
        $this->assertFalse($added['category']['options']['required']);

        $this->assertFalse($added['rightsReserved']['options']['required']);
        $this->assertSame(CheckboxType::class, $added['rightsReserved']['type']);

        $this->assertSame(CollectionType::class, $added['photos']['type']);
        $this->assertSame(GalleryPhotoUploadRowType::class, $added['photos']['options']['entry_type']);
        $this->assertTrue($added['photos']['options']['allow_add']);
        $this->assertTrue($added['photos']['options']['allow_delete']);
    }

    public function testConfigureOptionsDefaultsToNoDataClassAndGalleryTranslationDomain(): void
    {
        $type = new GalleryPhotoBatchUploadType();
        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        $options = $resolver->resolve(['gallery' => null]);

        $this->assertNull($options['data_class']);
        $this->assertSame('gallery', $options['translation_domain']);
    }

    // "category"'s query_builder filters by gallery (see buildForm) - a caller forgetting to pass it would silently list every gallery's categories
    public function testConfigureOptionsRequiresTheGalleryOption(): void
    {
        $this->expectException(MissingOptionsException::class);

        $type = new GalleryPhotoBatchUploadType();
        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        $resolver->resolve();
    }
}
