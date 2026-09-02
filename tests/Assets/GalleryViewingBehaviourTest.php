<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Assets;

use PHPUnit\Framework\Attributes\Group;

// The three controllers a media page mounts together: gallery-lightbox, gallery-media-preload and gallery-media-protect
// Each of them is about something a browser does rather than about a value: a native <dialog> really opening, a file really being fetched ahead of time, and a gesture really being refused. Read as text they are three one-liners; the questions worth asking - is the high resolution fetched before it is asked for, and is it fetched twice - are only answerable from what the browser actually requested
#[Group('browser')]
class GalleryViewingBehaviourTest extends JsCase
{
    // Browsing a category stays on the stored files, and the heavy one is only ever fetched for the media the visitor asks to see
    public function testTheHighResolutionIsOnlyFetchedWhenItIsAskedFor(): void
    {
        $opened = $this->media(
            'const before = image().getAttribute("src");
             link().click();

             return { before, after: image().getAttribute("src"), open: dialog().open };'
        );

        $this->assertNull($opened['before'], 'The high resolution is fetched for a media nobody has asked to enlarge, over a page already carrying its medium file.');
        $this->assertStringEndsWith('/haute-definition.jpg', (string) $opened['after'], 'Opening the lightbox does not put the high resolution in it.');
        $this->assertTrue($opened['open'], 'The lightbox was never opened.');
    }

    // The link is a real one, pointing at the file itself, so it still opens the high resolution when this script does not run
    public function testTheLinkIsFollowedByTheLightboxRatherThanByTheBrowser(): void
    {
        $this->assertTrue(
            (bool) $this->media(
                'let prevented = false;
                 link().addEventListener("click", (event) => { prevented = event.defaultPrevented; });
                 link().click();

                 return prevented;'
            ),
            'Opening a media navigates to the file itself, leaving the page and its arrows behind.'
        );
    }

    // Assigned on the first opening only, the browser cache serving the next ones
    public function testTheSourceIsAssignedOnceAndNotOnEveryOpening(): void
    {
        $reopened = $this->media(
            'link().click();
             const first = image().getAttribute("src");
             image().addEventListener("load", () => { window.__loads = (window.__loads ?? 0) + 1; });
             dialog().click();
             link().click();

             return { first, second: image().getAttribute("src"), open: dialog().open };'
        );

        $this->assertSame($reopened['first'], $reopened['second'], 'The source is written again on every opening, which a browser answers with a request of its own.');
        $this->assertTrue($reopened['open'], 'The lightbox cannot be reopened once it has been closed.');
    }

    // Anything clicked inside closes it - the image and the backdrop alike, which is why there is no close button
    public function testClickingAnywhereInTheLightboxClosesIt(): void
    {
        $closed = $this->media('link().click(); const open = dialog().open; image().click(); return { open, after: dialog().open };');

        $this->assertTrue($closed['open']);
        $this->assertFalse($closed['after'], 'Clicking the image leaves the lightbox open, and there is no close button to leave by.');
    }

    // Warmed while the current media is being looked at, so clicking prev or next does not show a blank while it loads
    public function testTheNeighboursAreFetchedWhileTheCurrentMediaIsBeingLookedAt(): void
    {
        $warmed = $this->media('return fetched();');

        $this->assertContains($this->url('assets/js/gallery-media-preload.js') . '?precedent', $warmed, 'The previous media is not warmed, so going back shows a blank while it loads.');
        $this->assertContains($this->url('assets/js/gallery-media-preload.js') . '?suivant', $warmed, 'The next media is not warmed, so going on shows a blank while it loads.');
    }

    // The first and the last media of a gallery have a neighbour on one side only
    public function testAMediaAtTheEndOfAGalleryWarmsNothingItHasNoNeighbourFor(): void
    {
        $warmed = $this->media('return fetched();', false);

        $this->assertSame([], $warmed, 'A media with no neighbour declared fetched something anyway, which is a request for the page itself.');
    }

    // A deterrent, deliberately nothing more - but one that has to cover the whole grid rather than the image alone
    public function testTheTwoGesturesAPhotoLeavesThePageByAreRefused(): void
    {
        $blocked = $this->media(
            'const refused = (type, on) => {
                 const event = new Event(type, { bubbles: true, cancelable: true });
                 on.dispatchEvent(event);

                 return event.defaultPrevented;
             };

             return {
                 menu: refused("contextmenu", image()),
                 drag: refused("dragstart", image()),
                 elsewhere: refused("contextmenu", root.querySelector(".gallery-media-caption")),
             };'
        );

        $this->assertTrue($blocked['menu'], 'The context menu opens over a photo, offering to save it.');
        $this->assertTrue($blocked['drag'], 'A photo can be dragged out of the page and onto the desktop.');
        $this->assertFalse($blocked['elsewhere'], 'The context menu is refused beside the photo too, where there is nothing to protect and a reader may want to copy the caption.');
    }

    private function media(string $probe, bool $neighbours = true): mixed
    {
        $preamble = 'const link = () => root.querySelector("a.gallery-lightbox__link");
             const dialog = () => root.querySelector("[data-gallery-lightbox-target=dialog]");
             const image = () => root.querySelector("[data-gallery-lightbox-target=image]");
             // What the browser really went and asked for, which is the only thing a warmed cache leaves behind
             const fetched = () => performance.getEntriesByType("resource").map((entry) => entry.name).filter((name) => name.includes("?precedent") || name.includes("?suivant")); ';

        return $this->observe(
            $this->page($neighbours),
            $this->controllers(),
            $preamble . $probe,
            // Emptied before anything connects: the page is shared by the whole run, and what a previous scenario warmed is still listed
            ['before' => 'performance.clearResourceTimings();']
        );
    }

    /**
     * @return array<string, string>
     */
    private function controllers(): array
    {
        return [
            'gallery-lightbox' => 'gallery-lightbox',
            'gallery-media-preload' => 'gallery-media-preload',
            'gallery-media-protect' => 'gallery-media-protect',
        ];
    }

    // The media page as gallery/media.html.twig renders it, the neighbours pointing at files this suite's own server answers for
    private function page(bool $neighbours): string
    {
        return sprintf(
            '<div class="gallery-media-container"
                data-controller="gallery-media-preload gallery-lightbox gallery-media-protect"
                data-action="contextmenu->gallery-media-protect#block dragstart->gallery-media-protect#block"
                %s>
                <a class="gallery-lightbox__link" href="/media/haute-definition.jpg" data-action="gallery-lightbox#open"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt=""></a>
                <dialog class="gallery-lightbox" data-gallery-lightbox-target="dialog" data-action="click->gallery-lightbox#close">
                    <img class="gallery-lightbox__image" data-gallery-lightbox-target="image" alt="Une photo">
                </dialog>
            </div>
            <p class="gallery-media-caption">Le lac au petit matin</p>',
            $neighbours
                ? sprintf(
                    'data-previous-url="%s?precedent" data-next-url="%s?suivant"',
                    $this->url('assets/js/gallery-media-preload.js'),
                    $this->url('assets/js/gallery-media-preload.js')
                )
                : ''
        );
    }
}
