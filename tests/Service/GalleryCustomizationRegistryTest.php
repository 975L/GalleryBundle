<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Contract\GalleryCustomizationProviderInterface;
use c975L\GalleryBundle\Service\GalleryCustomizationRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class GalleryCustomizationRegistryTest extends TestCase
{
    // A site declaring nothing is the ordinary case, and what it gets is no field at all on either screen
    public function testAnEmptyRegistryDeclaresNoFormAtAll(): void
    {
        $registry = new GalleryCustomizationRegistry([]);

        $this->assertNull($registry->getCategoryDataFormType());
        $this->assertNull($registry->getMediaDataFormType());
    }

    public function testTheDeclaredFormTypesAreRead(): void
    {
        $registry = new GalleryCustomizationRegistry([$this->createProvider(FormType::class, TextType::class)]);

        $this->assertSame(FormType::class, $registry->getCategoryDataFormType());
        $this->assertSame(TextType::class, $registry->getMediaDataFormType());
    }

    // A site adding fields to its medias only leaves the categories alone, the two being read apart
    public function testAProviderDeclaringOnlyOneSideLeavesTheOtherWithoutAForm(): void
    {
        $registry = new GalleryCustomizationRegistry([$this->createProvider(null, TextType::class)]);

        $this->assertNull($registry->getCategoryDataFormType());
        $this->assertSame(TextType::class, $registry->getMediaDataFormType());
    }

    // The first provider answering wins, so a site adding a second one never has the first silently replaced
    public function testTheFirstProviderAnsweringWins(): void
    {
        $registry = new GalleryCustomizationRegistry([
            $this->createProvider(null, null),
            $this->createProvider(FormType::class, TextType::class),
            $this->createProvider(TextType::class, FormType::class),
        ]);

        $this->assertSame(FormType::class, $registry->getCategoryDataFormType());
        $this->assertSame(TextType::class, $registry->getMediaDataFormType());
    }

    private function createProvider(?string $categoryFormType, ?string $mediaFormType): GalleryCustomizationProviderInterface
    {
        $provider = $this->createStub(GalleryCustomizationProviderInterface::class);
        $provider->method('getCategoryDataFormType')->willReturn($categoryFormType);
        $provider->method('getMediaDataFormType')->willReturn($mediaFormType);

        return $provider;
    }
}
