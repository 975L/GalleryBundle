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
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Service\GalleryTranslator;
use c975L\UiBundle\Service\ContentTranslator;
use PHPUnit\Framework\TestCase;

class GalleryTranslatorTest extends TestCase
{
    // A site declaring a single language reads nothing at all: the row answers what it was written with
    public function testARowIsLeftAloneWhenNothingIsTranslated(): void
    {
        $media = $this->createMedia();

        $this->translator(active: false)->apply([$media]);

        $this->assertSame('Lac au matin', $media->getTitle());
        $this->assertSame(['fr'], $this->translator(active: false)->translatedLocales($media));
    }

    // What the language being read says, laid over the row for this render - an untranslated field falling back on the text the row was written with
    public function testTheTranslatedTextsAreTheOnesRead(): void
    {
        $media = $this->createMedia();

        $this->translator(translated: ['title' => 'Lake in the morning'])->apply([$media]);

        $this->assertSame('Lake in the morning', $media->getTitle());
        $this->assertSame('Brume sur le lac.', $media->getDescription());
        $this->assertSame('Lac au matin', $media->getUntranslated('title'));
    }

    // A gallery of forty photographs costs one query per kind of row rather than one per row
    public function testApplyReadsAheadEachKindOnce(): void
    {
        $preloaded = [];
        $contentTranslator = $this->createMock(ContentTranslator::class);
        $contentTranslator->method('isActive')->willReturn(true);
        $contentTranslator->method('translate')->willReturn([]);
        $contentTranslator->expects($this->exactly(2))->method('preload')->willReturnCallback(
            static function (string $owner, array $ids) use (&$preloaded): void {
                $preloaded[$owner] = $ids;
            }
        );

        new GalleryTranslator($contentTranslator, new SiteLocales(['fr', 'en'], 'fr'))->apply([$this->createMedia(12), $this->createMedia(13), $this->createCategory()]);

        $this->assertSame([GalleryTranslator::OWNER_MEDIA => [12, 13], GalleryTranslator::OWNER_CATEGORY => [4]], $preloaded);
    }

    // A row says something in a language once its own name is written there, its own language always included: what a "hreflang" group may name
    public function testARowSaysSomethingInTheLanguagesItsOwnNameWasWrittenIn(): void
    {
        $media = $this->createMedia();

        $this->assertSame(['fr', 'en'], $this->translator(values: ['en' => ['title' => 'Lake in the morning']])->translatedLocales($media));
        $this->assertSame(['fr'], $this->translator(values: ['en' => ['description' => 'Mist over the lake.']])->translatedLocales($media));
    }

    // A language screen offers what that language already says, or the source text between brackets where it says nothing yet
    public function testALanguageScreenOffersTheSourceBetweenBracketsWhereNothingIsWritten(): void
    {
        $contentTranslator = $this->createStub(ContentTranslator::class);
        $contentTranslator->method('all')->willReturn(['en' => ['title' => 'Lake in the morning']]);

        $values = new GalleryTranslator($contentTranslator, new SiteLocales(['fr', 'en'], 'fr'))->promptValues($this->createMedia(), 'en');

        $this->assertSame(['title' => 'Lake in the morning', 'description' => '[Brume sur le lac.]'], $values);
    }

    // A field handed back still holding its bracketed source was not translated, and is staged as nothing written
    public function testAFieldLeftUntouchedIsStagedAsNothingWritten(): void
    {
        $contentTranslator = $this->createMock(ContentTranslator::class);
        $contentTranslator->expects($this->once())->method('stage')->with(
            GalleryTranslator::OWNER_MEDIA,
            12,
            'en',
            ['title' => 'Lake in the morning', 'description' => null],
        );

        new GalleryTranslator($contentTranslator, new SiteLocales(['fr', 'en'], 'fr'))->stage($this->createMedia(), 'en', [
            'title' => 'Lake in the morning',
            'description' => '[Brume sur le lac.]',
        ]);
    }

    // Each kind is named apart, so a gallery 12 and a photograph 12 never read each other's words, and each translates its own texts
    public function testEachKindIsNamedApartWithItsOwnFields(): void
    {
        $translator = $this->translator();

        $this->assertSame(GalleryTranslator::OWNER_CATEGORY, $translator->owner(new GalleryCategory()));
        $this->assertSame(GalleryTranslator::OWNER_MEDIA, $translator->owner(new GalleryMedia()));
        $this->assertSame(GalleryTranslator::OWNER_PRINT_FORMAT, $translator->owner(new GalleryPrintFormat()));
        $this->assertSame(['label', 'paper', 'paperDescription'], $translator->fields(new GalleryPrintFormat()));
    }

    // A row never saved has no id: nothing to hang a translation on, so nothing is read and nothing staged
    public function testARowWithNoIdReadsAndStagesNothing(): void
    {
        $contentTranslator = $this->createMock(ContentTranslator::class);
        $contentTranslator->expects($this->never())->method('all');
        $contentTranslator->expects($this->never())->method('stage');

        $translator = new GalleryTranslator($contentTranslator, new SiteLocales(['fr', 'en'], 'fr'));

        $this->assertSame([], $translator->all(new GalleryMedia()));
        $translator->stage(new GalleryMedia(), 'en', ['title' => 'Lake in the morning']);
    }

    private function createMedia(int $id = 12): GalleryMedia
    {
        $media = new GalleryMedia();
        $media->setTitle('Lac au matin')->setDescription('Brume sur le lac.');
        new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, $id);

        return $media;
    }

    private function createCategory(): GalleryCategory
    {
        $category = new GalleryCategory()->setTitle('Voyages');
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($category, 4);

        return $category;
    }

    /**
     * @param array<string, string|null>                $translated what the language being read says
     * @param array<string, array<string, string|null>> $values     locale => field => value, what each language says
     */
    private function translator(bool $active = true, array $translated = [], array $values = []): GalleryTranslator
    {
        $contentTranslator = $this->createStub(ContentTranslator::class);
        $contentTranslator->method('isActive')->willReturn($active);
        $contentTranslator->method('getTranslatableLocales')->willReturn($active ? ['en'] : []);
        $contentTranslator->method('translate')->willReturn($translated);
        $contentTranslator->method('values')->willReturnCallback(
            static fn (string $owner, int $id, string $locale): array => $values[$locale] ?? []
        );

        return new GalleryTranslator($contentTranslator, new SiteLocales(['fr', 'en'], 'fr'));
    }
}
