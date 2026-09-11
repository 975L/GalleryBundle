<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Service\SiteLocales;
use c975L\GalleryBundle\Service\GalleryTranslatedLocales;
use PHPUnit\Framework\TestCase;

class GalleryTranslatedLocalesTest extends TestCase
{
    // "/en" is the language the gallery is read in, not a claim about each row: every screen answers in every language the site declares, the writing one first
    public function testEveryLanguageTheSiteDeclaresIsAnswered(): void
    {
        $this->assertSame(['fr', 'en', 'es'], new GalleryTranslatedLocales(new SiteLocales(['fr', 'en', 'es'], 'fr'))->all());
    }

    // A site declaring a single language answers in that one alone, which is what leaves the localised routes matching nothing
    public function testASingleLanguageSiteAnswersInItsOwnLanguageAlone(): void
    {
        $this->assertSame(['fr'], new GalleryTranslatedLocales(new SiteLocales([], 'fr'))->all());
    }
}
