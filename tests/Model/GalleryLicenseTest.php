<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Model;

use c975L\GalleryBundle\Model\GalleryLicense;
use PHPUnit\Framework\TestCase;

// What a credit line draws of a licence: the short name Creative Commons asks for, and its icons in their own order
class GalleryLicenseTest extends TestCase
{
    public function testACreativeCommonsLicenceIsNamedAndDrawnAsCreativeCommonsDoes(): void
    {
        $this->assertSame('CC BY-NC-SA 4.0', GalleryLicense::name('cc-by-nc-sa'));
        $this->assertSame(['cc', 'by', 'nc', 'sa'], GalleryLicense::icons('cc-by-nc-sa'));
        $this->assertSame('CC0 1.0', GalleryLicense::name('cc0'));
        $this->assertSame(['cc', 'zero'], GalleryLicense::icons('cc0'));
    }

    // Every icon a licence asks for is one the bundle ships
    public function testEveryIconIsShipped(): void
    {
        foreach (GalleryLicense::all() as $license) {
            foreach (GalleryLicense::icons($license) as $icon) {
                $this->assertFileExists(\dirname(__DIR__, 2) . '/public/icons/cc-' . $icon . '.svg', $license);
            }
        }
    }

    public function testAllRightsReservedHasNeitherNameNorIcon(): void
    {
        $this->assertNull(GalleryLicense::name(GalleryLicense::RESERVED));
        $this->assertSame([], GalleryLicense::icons(GalleryLicense::RESERVED));
        $this->assertSame('label.gallery_license_cc_by_nd', GalleryLicense::label('cc-by-nd'));
    }
}
