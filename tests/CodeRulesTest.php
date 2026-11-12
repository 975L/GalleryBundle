<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests;

use c975L\ConfigBundle\Test\CodeRulesTestCase;

// Replays the rules of the 11/11/2026 audit on the bundle's own code (see ConfigBundle's CodeRulesTestCase)
class CodeRulesTest extends CodeRulesTestCase
{
    // Where the bundle's classes live
    protected function sourceDirectory(): string
    {
        return __DIR__ . '/../src';
    }

    // The namespace those classes are declared under
    protected function sourceNamespace(): string
    {
        return 'c975L\GalleryBundle';
    }

    // Statements that are the lock themselves, on rows no cached fragment reads
    protected function allowed(): array
    {
        return [
            self::BULK_QUERY => [
                'c975L\GalleryBundle\Repository\GalleryPrintCopyRepository::claimNumber' => 'The remaining count of an edition is read live by the sale page, never cached',
                'c975L\GalleryBundle\Repository\GalleryPrintCopyRepository::releaseNumbers' => 'The remaining count of an edition is read live by the sale page, never cached',
                'c975L\GalleryBundle\Repository\GalleryPrintOrderRepository::claim' => 'Print orders are read live by the back-office and the tracker, never cached',
            ],
        ];
    }
}
