<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\UiBundle\Contract\BlockOwnerResolverInterface;
use c975L\UiBundle\Contract\HasBlocksInterface;

// Lets BlockMoveController relocate a GalleryCategory's Block without depending on the entity itself
class GalleryBlockOwnerResolver implements BlockOwnerResolverInterface
{
    // Shared with GalleryCategoryCrudController's own blockMoveRowAttr() call, so the owner-type string only ever exists in one place
    public const TYPE_CATEGORY = 'gallery_category';

    public function __construct(
        private readonly GalleryCategoryRepository $categoryRepository,
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return self::TYPE_CATEGORY === $ownerType;
    }

    public function find(string $ownerType, int $ownerId): ?HasBlocksInterface
    {
        return self::TYPE_CATEGORY === $ownerType ? $this->categoryRepository->find($ownerId) : null;
    }
}
