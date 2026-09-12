<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

// One Stimulus application per page. startStimulusApp() does not only start an application: it also registers whatever
// the consuming app's controllers.json enables, "live" and "chart" among them. A page loading several c975L barrels,
// each starting its own, therefore built those controllers once per barrel - a Live Component answered as many
// requests and morphed its results in as many times. The repository has no browser to catch that in
class StimulusAppSharingTest extends TestCase
{
    public function testEveryBarrelJoinsTheSharedApplication(): void
    {
        $barrels = $this->barrels();

        foreach ($barrels as $barrel) {
            $source = (string) file_get_contents($barrel);
            $name = basename($barrel);

            $this->assertStringContainsString(
                'globalThis.c975lStimulusApp ??= startStimulusApp()',
                $source,
                sprintf('"assets/%s" starts an application of its own instead of joining the page\'s.', $name)
            );

            // The guarded line alone says nothing about a second, unguarded start left in the file - which would build the shared application's controllers a second time, exactly what the guard is there to prevent
            $this->assertSame(
                1,
                substr_count($source, 'startStimulusApp()'),
                sprintf('"assets/%s" calls startStimulusApp() more than once.', $name)
            );
        }
    }

    // Read off the disk rather than listed here, a barrel added tomorrow being one nobody would think of adding to a list
    private function barrels(): array
    {
        $barrels = glob(\dirname(__DIR__, 2) . '/assets/controllers*.js');

        $this->assertNotEmpty($barrels, 'No barrel found in "assets/", the test itself is broken.');

        return $barrels;
    }
}
