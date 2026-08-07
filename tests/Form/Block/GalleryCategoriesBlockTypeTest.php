<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Form\Block;

use c975L\GalleryBundle\Form\Block\GalleryCategoriesBlockType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GalleryCategoriesBlockTypeTest extends TestCase
{
    public function testBuildFormAddsAnOptionalMaxField(): void
    {
        $added = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $type = null, array $options = []) use (&$added, $builder) {
            $added[$name] = ['type' => $type, 'options' => $options];

            return $builder;
        });

        (new GalleryCategoriesBlockType())->buildForm($builder, []);

        $this->assertSame(IntegerType::class, $added['max']['type']);
        $this->assertFalse($added['max']['options']['required']);
    }

    // BlockType translates the embedded data form in the "ui" domain, where this label doesn't exist
    public function testConfigureOptionsSetsTheGalleryTranslationDomain(): void
    {
        $resolver = new OptionsResolver();
        (new GalleryCategoriesBlockType())->configureOptions($resolver);

        $this->assertSame('gallery', $resolver->resolve()['translation_domain']);
    }
}
