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
use c975L\GalleryBundle\Management\GalleryGuidedProjectProvider;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;

class GalleryGuidedProjectProviderTest extends TestCase
{
    private function createAdminUrlGenerator(array &$controllers = []): AdminUrlGeneratorInterface
    {
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('unsetAll')->willReturnSelf();
        $generator->method('setController')->willReturnCallback(function (string $controller) use ($generator, &$controllers) {
            $controllers[] = $controller;

            return $generator;
        });
        $generator->method('setAction')->willReturnSelf();
        $generator->method('generateUrl')->willReturn('/management/gallery');

        return $generator;
    }

    private function createProvider(array &$controllers = []): GalleryGuidedProjectProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_EDITOR');

        return new GalleryGuidedProjectProvider($this->createAdminUrlGenerator($controllers), $configService);
    }

    // The 5000 block GuidedProjectProviderInterface reserves this bundle, at the step of 10 it states
    public function testGetGuidedProjectsContinuesTheOrderSequence(): void
    {
        $projects = $this->createProvider()->getGuidedProjects();

        $this->assertSame(
            ['gallery-creation', 'gallery-medias-arrangement', 'gallery-medias-move', 'gallery-media-detail', 'gallery-trash', 'gallery-medias-recovery', 'gallery-latest'],
            array_column($projects, 'slug')
        );
        $this->assertSame([5010, 5020, 5025, 5030, 5040, 5050, 5060], array_column($projects, 'order'));
    }

    public function testEverySlugIsPrefixedWithTheBundleName(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertStringStartsWith('gallery-', $project['slug'], 'A slug is unique across every bundle contributing projects');
        }
    }

    public function testEveryProjectCarriesTheGalleryTranslationDomainAndSteps(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertSame('gallery', $project['translation_domain']);
            $this->assertNotEmpty($project['steps']);
        }
    }

    // Every gallery screen sits behind the site's editor role, so a parcours walking them is dropped for anybody else
    public function testEveryProjectCarriesTheEditorRole(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertSame('ROLE_EDITOR', $project['role']);
        }
    }

    public function testNoStepSetsBothUrlAndHighlight(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $index => $step) {
                $this->assertFalse(
                    isset($step['url']) && isset($step['highlight']),
                    sprintf('Step %d of "%s" sets both url and highlight', $index, $project['slug'])
                );
            }
        }
    }

    // Only the opening step leaves the screen, everything after it walking the one the user has been sent to
    public function testOnlyTheFirstStepOfEachProjectCarriesAnUrl(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $steps = $project['steps'];

            $this->assertArrayHasKey('url', $steps[0], sprintf('Project "%s" does not open on a screen', $project['slug']));

            foreach (array_slice($steps, 1) as $index => $step) {
                $this->assertArrayNotHasKey('url', $step, sprintf('Step %d of "%s" leaves the screen again', $index + 1, $project['slug']));
            }
        }
    }

    // The categories are the single sidebar entry of the whole feature, so every parcours opens on them
    public function testEveryProjectOpensOnTheCategoryCrudIndex(): void
    {
        $controllers = [];
        $this->createProvider($controllers)->getGuidedProjects();

        $this->assertSame(
            array_fill(0, 7, 'GalleryCategoryCrudController'),
            array_map(static fn (string $fqcn): string => basename(str_replace('\\', '/', $fqcn)), $controllers)
        );
    }

    // EasyAdmin renders the form's save button as action-saveAndReturn, .action-save matching nothing and leaving the step highlighting an empty selection
    public function testEverySaveStepHighlightsTheEasyAdminSaveButton(): void
    {
        $saveSteps = [];

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $step) {
                if (str_ends_with($step['label'], '_save')) {
                    $saveSteps[] = $step;
                }
            }
        }

        $this->assertCount(2, $saveSteps, 'The creation and the media parcours each walk the user to the save button once');

        foreach ($saveSteps as $step) {
            $this->assertSame('.action-saveAndReturn', $step['highlight']);
        }
    }

    // Every action a step points at is one the editor role opens - the permanent deletion sits at the admin's, so a step highlighting it would show nothing to the very users the parcours is offered to (see GalleryCategoryCrudController::configureActions)
    public function testNoStepHighlightsAnActionHeldAboveTheEditorRole(): void
    {
        $highlights = [];

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $step) {
                $highlights[] = $step['highlight'] ?? '';
            }
        }

        $this->assertNotContains('.action-deletePermanently', $highlights);
    }

    // A marker renamed in the template would leave its step highlighting nothing at all, the panel going on showing itself
    public function testEveryDataAttributeHighlightedExistsInTheTemplates(): void
    {
        $templates = '';
        foreach (glob(\dirname(__DIR__, 2) . '/templates/management/*.twig') as $template) {
            $templates .= file_get_contents($template);
        }

        $attributes = [];
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $step) {
                if (isset($step['highlight']) && preg_match('/^\[(data-[a-z-]+)/', $step['highlight'], $matches)) {
                    $attributes[] = $matches[1];
                }
            }
        }

        $this->assertNotEmpty($attributes);

        foreach ($attributes as $attribute) {
            $this->assertStringContainsString($attribute, $templates, sprintf('No management template carries "%s" anymore', $attribute));
        }
    }

    // A label or description with no translation reads as its own key in the panel, in whichever locale it is missing from
    public function testEveryLabelAndDescriptionIsTranslatedInEveryLocale(): void
    {
        foreach (['en', 'fr', 'es'] as $locale) {
            $translated = $this->translatedKeys($locale);

            foreach ($this->createProvider()->getGuidedProjects() as $project) {
                foreach ([$project, ...$project['steps']] as $item) {
                    $this->assertContains($item['label'], $translated, sprintf('"%s" is missing from the %s catalogue', $item['label'], $locale));
                    if (isset($item['description'])) {
                        $this->assertContains($item['description'], $translated, sprintf('"%s" is missing from the %s catalogue', $item['description'], $locale));
                    }
                }
            }
        }
    }

    private function translatedKeys(string $locale): array
    {
        $xliff = new \DOMDocument();
        $xliff->load(\dirname(__DIR__, 2) . '/translations/gallery.' . $locale . '.xlf');

        $keys = [];
        foreach ($xliff->getElementsByTagName('source') as $source) {
            $keys[] = $source->textContent;
        }

        return $keys;
    }
}
