<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle;

use c975L\ConfigBundle\DependencyInjection\Compiler\TaggedInterfacePass;
use c975L\GalleryBundle\Contract\AutomaticGalleryInterface;
use c975L\GalleryBundle\Contract\GalleryCustomizationProviderInterface;
use c975L\GalleryBundle\Contract\PrintCatalogueProviderInterface;
use c975L\GalleryBundle\Contract\PrintFulfilmentInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class c975LGalleryBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        $containerConfigurator->import('../config/services.yaml');
    }

    // asset_mapper needs this path so Twig's asset()/importmap can resolve "@c975l/gallery-bundle" to the bundle's own assets/ directory (the front-office media preload controller)
    public function prependExtension(ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        $containerBuilder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    __DIR__ . '/../assets' => '@c975l/gallery-bundle',
                ],
            ],
        ]);
    }

    // Collects what each site declares about its own galleries, so GalleryCustomizationRegistry reads them all without the app having to tag its provider by hand
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new TaggedInterfacePass(GalleryCustomizationProviderInterface::class, 'gallery.customization_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(PrintFulfilmentInterface::class, 'gallery.print_fulfilment'));
        $container->addCompilerPass(new TaggedInterfacePass(AutomaticGalleryInterface::class, 'gallery.automatic_gallery'));
        $container->addCompilerPass(new TaggedInterfacePass(PrintCatalogueProviderInterface::class, 'gallery.print_catalogue_provider'));
    }

    #[\Override]
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
