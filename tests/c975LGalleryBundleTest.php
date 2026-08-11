<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests;

use c975L\GalleryBundle\c975LGalleryBundle;
use c975L\GalleryBundle\Management\MenuProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class c975LGalleryBundleTest extends TestCase
{
    // Mirrors how Symfony's own kernel invokes it (BundleExtension::load() builds the ContainerConfigurator and calls loadExtension() for us), so this also validates that config/services.yaml itself parses and wires without error
    public function testLoadExtensionImportsServicesYaml(): void
    {
        $container = new ContainerBuilder();

        new c975LGalleryBundle()->getContainerExtension()->load([], $container);

        $this->assertTrue($container->hasDefinition(MenuProvider::class));
    }

    // Same real-kernel-hook approach as loadExtension() above (BundleExtension::prepend() calls prependExtension() for us) - asset_mapper needs this path so Twig's asset()/importmap can resolve "@c975l/gallery-bundle" to the bundle's own assets/ directory
    public function testPrependExtensionRegistersAssetMapperPathForBundleAssets(): void
    {
        $container = new ContainerBuilder();

        new c975LGalleryBundle()->getContainerExtension()->prepend($container);

        $frameworkConfig = $container->getExtensionConfig('framework');
        $this->assertSame(
            \dirname(__DIR__) . '/src/../assets',
            array_key_first($frameworkConfig[0]['asset_mapper']['paths'])
        );
        $this->assertSame(
            '@c975l/gallery-bundle',
            $frameworkConfig[0]['asset_mapper']['paths'][\dirname(__DIR__) . '/src/../assets']
        );
    }

    public function testGetPathReturnsTheBundleRootDirectory(): void
    {
        $bundle = new c975LGalleryBundle();

        $this->assertSame(\dirname(__DIR__), $bundle->getPath());
    }
}
