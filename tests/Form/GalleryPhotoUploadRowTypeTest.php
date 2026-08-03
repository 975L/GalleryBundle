<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Form;

use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Form\GalleryPhotoUploadRowType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;

class GalleryPhotoUploadRowTypeTest extends TestCase
{
    public function testBuildFormAddsFileAsOptionalAndAltFields(): void
    {
        $added = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $type = null, array $options = []) use (&$added, $builder) {
            $added[$name] = ['type' => $type, 'options' => $options];

            return $builder;
        });

        (new GalleryPhotoUploadRowType())->buildForm($builder, []);

        $this->assertSame(VichImageType::class, $added['file']['type']);
        $this->assertFalse($added['file']['options']['required']);
        $this->assertArrayHasKey('alt', $added);
        $this->assertFalse($added['alt']['options']['required']);
    }

    public function testConfigureOptionsDefaultsToGalleryPhotoDataClassAndGalleryTranslationDomain(): void
    {
        $type = new GalleryPhotoUploadRowType();
        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        $options = $resolver->resolve();

        $this->assertSame(GalleryPhoto::class, $options['data_class']);
        $this->assertSame('gallery', $options['translation_domain']);
    }
}
