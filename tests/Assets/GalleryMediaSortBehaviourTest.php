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

// assets/js/gallery-media-sort.js dragged with a real pointer over a grid the browser really lays out
// Where a dragged tile lands is computed from the pointer against getBoundingClientRect, on a grid that wraps - which is exactly what no emulated DOM can answer, every tile sitting at the same nowhere. The gesture underneath is UiBundle's own (pointer-sort.js), reached here by the bare specifier the importmap resolves, so this also says that borrowing still works
// The failure path is deliberately left out: it reloads the page, which would take the document this suite shares out from under it
#[Group('browser')]
class GalleryMediaSortBehaviourTest extends JsCase
{
    private const string URL = '/management/gallery/12/layout';

    // Three tiles a row, wide enough for a midpoint to mean something
    private const string CSS = '
        [data-gallery-media-sort-target=grid] { display: flex; flex-wrap: wrap; width: 360px; margin: 0; padding: 0; }
        [data-gallery-media-sort-target=item] { display: block; width: 100px; height: 100px; margin: 10px; }
    ';

    public function testATileDroppedBeforeAnotherIsSavedInItsNewPlace(): void
    {
        $dropped = $this->grid('await drag(2, 0); return { order: order(), sent: window.__sent };');

        $this->assertSame(['9', '3', '7'], $dropped['order'], 'The tile was dropped somewhere other than where the pointer left it.');
        $this->assertSame(self::URL, $dropped['sent']['url'], 'The new arrangement was not saved where the screen said to save it.');
        $this->assertSame('mediaOrder%5B%5D=9&mediaOrder%5B%5D=3&mediaOrder%5B%5D=7&coverMediaId=3', $dropped['sent']['body'], 'What was sent is not the order the grid now shows.');
        $this->assertSame('jeton', $dropped['sent']['token'], 'The arrangement is saved without the token the screen was given.');
    }

    // The grid is not part of the edit form above it, so its Save button would leave the arrangement behind without anything saying so
    public function testTheArrangementIsSavedOnTheSpotRatherThanWithTheFormAboveIt(): void
    {
        $this->assertSame(1, $this->grid('await drag(0, 2); return window.__saves;'), 'A tile was dropped and nothing was saved, so the arrangement is lost on the next page load.');
    }

    // A tile put back where it was picked up saves nothing
    public function testATileDroppedWhereItWasPickedUpSavesNothing(): void
    {
        $this->assertSame(0, $this->grid('await drag(1, 1); return window.__saves;'), 'Picking a tile up and putting it back saved an arrangement nobody changed.');
    }

    // A gesture cancelled mid-drag - a phone call, the pointer leaving the window - puts the tile back where it was
    public function testACancelledDragPutsTheTileBackAndSavesNothing(): void
    {
        $cancelled = $this->grid('await drag(0, 2, true); return { order: order(), saves: window.__saves };');

        $this->assertSame(['3', '7', '9'], $cancelled['order'], 'A cancelled drag left the tile where it had been dragged to.');
        $this->assertSame(0, $cancelled['saves'], 'A cancelled drag saved an arrangement the user gave up on.');
    }

    // insertBefore() with a null reference appends, which is where a tile dragged from the last place belongs
    public function testACancelledDragOfTheLastTilePutsItBackAtTheEnd(): void
    {
        $this->assertSame(['3', '7', '9'], $this->grid('await drag(2, 0, true); return order();'), 'The tile that was last came back somewhere other than the end.');
    }

    // Order and cover are one row's two sides server-side, so both go on every call whichever of the two was just changed
    public function testTheCoverGoesWithTheOrderWhicheverOfTheTwoWasChanged(): void
    {
        $sent = $this->grid(
            'const cover = root.querySelector("input[name=coverMediaId][value=\"9\"]");
             cover.checked = true;
             for (const name of ["input", "change"]) { cover.dispatchEvent(new Event(name, { bubbles: true })); }

             return window.__sent.body;'
        );

        $this->assertSame('mediaOrder%5B%5D=3&mediaOrder%5B%5D=7&mediaOrder%5B%5D=9&coverMediaId=9', $sent, 'Picking a cover saved the cover alone, or saved the order without it.');
    }

    // A grid whose cover was never chosen still saves its order rather than nothing at all
    public function testAGridWithNoCoverChosenStillSavesItsOrder(): void
    {
        $this->assertSame(
            'mediaOrder%5B%5D=9&mediaOrder%5B%5D=3&mediaOrder%5B%5D=7&coverMediaId=',
            $this->grid('root.querySelectorAll("input[name=coverMediaId]").forEach((radio) => { radio.checked = false; }); await drag(2, 0); return window.__sent.body;'),
            'A gallery with no cover chosen saves nothing at all when its tiles are arranged.'
        );
    }

    // The tile stays clickable: the thumbnail's link opens the media and the two boxes still tick, only a real drag gesture hijacking them
    public function testAPointerPressedOnACheckboxNeverStartsADrag(): void
    {
        $ticked = $this->grid(
            'const label = root.querySelectorAll("[data-gallery-media-sort-target=item]")[2].querySelector("label");
             await drag(2, 0, false, label);

             return { order: order(), saves: window.__saves };'
        );

        $this->assertSame(['3', '7', '9'], $ticked['order'], 'Pressing a tile\'s checkbox and moving the pointer dragged the tile, so a box can no longer be ticked.');
        $this->assertSame(0, $ticked['saves'], 'A tick was saved as an arrangement.');
    }

    private function grid(string $probe): mixed
    {
        $preamble = 'const items = () => [...root.querySelectorAll("[data-gallery-media-sort-target=item]")];
             const order = () => items().map((item) => item.dataset.mediaId);
             const frame = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
             const point = (el, type, x, y) => el.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, pointerType: "mouse", button: 0, buttons: 1, clientX: x, clientY: y }));
             // A drag as a mouse makes one: pressed on the tile, moved past the threshold and over the place it lands, then let go
             const drag = async (from, to, cancelled, on) => {
                 const item = items()[from];
                 const start = item.getBoundingClientRect();
                 point(on ?? item.querySelector("[data-gallery-media-sort-handle]"), "pointerdown", start.left + 5, start.top + 5);
                 const target = items()[to].getBoundingClientRect();
                 point(document, "pointermove", target.left + 5, target.top + 5);
                 await frame();
                 point(document, cancelled ? "pointercancel" : "pointerup", target.left + 5, target.top + 5);
                 await frame();
             }; ';

        return $this->observe(
            $this->page(),
            ['gallery-media-sort' => 'gallery-media-sort'],
            $preamble . $probe,
            [
                'css' => self::CSS,
                // The layout route answered by the scenario, which is also what keeps this test off the network
                'before' => 'window.__saves = 0;
                    window.__sent = null;
                    window.fetch = (url, options) => {
                        window.__saves += 1;
                        window.__sent = { url, body: String(options.body), token: options.headers["X-CSRF-Token"] };

                        return Promise.resolve({ ok: true });
                    };',
            ]
        );
    }

    // The grid as _gallery_media_tile.html.twig renders it: a handle, a cover radio and a selection checkbox on every tile
    private function page(): string
    {
        $tiles = '';
        foreach (['3', '7', '9'] as $id) {
            $tiles .= sprintf(
                '<li data-gallery-media-sort-target="item" data-media-id="%s">
                    <span data-gallery-media-sort-handle>::</span>
                    <label><input type="radio" name="coverMediaId" value="%s"%s data-action="gallery-media-sort#save"></label>
                    <label><input type="checkbox" name="medias[]" value="%s"></label>
                </li>',
                $id,
                $id,
                '3' === $id ? ' checked' : '',
                $id
            );
        }

        return sprintf(
            '<form data-controller="gallery-media-sort" data-gallery-media-sort-url-value="%s" data-gallery-media-sort-token-value="jeton" data-gallery-media-sort-failed-label-value="Echec">
                <ul data-gallery-media-sort-target="grid">%s</ul>
            </form>',
            self::URL,
            $tiles
        );
    }
}
