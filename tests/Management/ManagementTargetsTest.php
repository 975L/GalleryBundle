<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Test\ManagementTargetsTestCase;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Management\GalleryGuidedProjectProvider;
use c975L\GalleryBundle\Management\LinkableRouteProvider;
use c975L\GalleryBundle\Management\MenuProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

// Every CRUD controller and route this bundle's management providers name, checked against what its controllers actually declare - see ConfigBundle's ManagementTargetsTestCase
class ManagementTargetsTest extends ManagementTargetsTestCase
{
    protected function managementProviders(): iterable
    {
        return [
            new MenuProvider(),
            new LinkableRouteProvider($this->categoryRepository(), $this->createStub(TranslatorInterface::class)),
            // The socle's own recorder rather than a bare stub, so the controller each parcours opens on is read back and checked (see ManagementTargetsTestCase)
            new GalleryGuidedProjectProvider($this->adminUrlGenerator(), $this->createStub(ConfigServiceInterface::class)),
        ];
    }

    // One category is enough to have the route its entries name checked too - an empty repository would leave the index route as the only linkable target
    private function categoryRepository(): GalleryCategoryRepository
    {
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('findAllOrdered')->willReturn([new GalleryCategory()->setSlug('landscapes')->setTitle('Landscapes')]);

        return $repository;
    }

    // This bundle's own controllers on top of ConfigBundle's, whose screens its links point to as well
    #[\Override]
    protected function controllerDirectories(): array
    {
        return [...parent::controllerDirectories(), __DIR__ . '/../../src/Controller', __DIR__ . '/../../src/Controller/Management'];
    }
}
