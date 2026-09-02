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

// assets/js/gallery-media-selection.js run over the toolbar gallery_category_edit.html.twig renders
// Two of the things it does have no markup of their own to be read for: the "select all" box being left indeterminate over a partial selection - a state nothing but a script can set - and Enter in a text box reaching the button of its own group rather than the form's first submit, which is what a browser would do on its own and which would move the medias when the person meant to credit them
#[Group('browser')]
class GalleryMediaSelectionBehaviourTest extends JsCase
{
    // The buttons all act on the checked medias, so with nothing checked there is nothing for any of them to do
    public function testTheActionsWaitForSomethingToBeCheckedOnLoad(): void
    {
        $idle = $this->toolbar('return state();');

        $this->assertSame([true, true], $idle['submits'], 'The action buttons are offered over a selection of nothing.');
        $this->assertTrue($idle['move'], 'The move button is offered over a selection of nothing.');
        $this->assertSame(['checked' => false, 'indeterminate' => false], $idle['toggle']);
    }

    // A browser restoring the checked boxes on a back-navigation would otherwise leave the buttons disabled over a live selection
    public function testASelectionRestoredByTheBrowserIsPickedUpOnArrival(): void
    {
        $restored = $this->toolbar('return state();', ['1']);

        $this->assertSame([false, false], $restored['submits'], 'A selection the browser restored leaves every button disabled, over medias that are visibly ticked.');
        $this->assertTrue($restored['toggle']['indeterminate']);
    }

    // A "select all" box left unchecked over eight medias out of ten reads as "nothing selected"
    public function testThePartialSelectionIsShownAsPartialRatherThanAsNone(): void
    {
        $partial = $this->toolbar('check("1"); check("2"); return state().toggle;');
        $whole = $this->toolbar('check("1"); check("2"); check("3"); return state().toggle;');

        $this->assertSame(['checked' => false, 'indeterminate' => true], $partial, 'Two medias out of three read on the "select all" box as nothing selected at all.');
        $this->assertSame(['checked' => true, 'indeterminate' => false], $whole, 'Every media checked one by one leaves the "select all" box saying otherwise.');
    }

    public function testTheSelectAllBoxTicksAndUnticksEverythingBelowIt(): void
    {
        $all = $this->toolbar(
            'toggle().checked = true;
             fire(toggle());
             const on = { boxes: boxes().map((box) => box.checked), submits: state().submits };
             toggle().checked = false;
             fire(toggle());

             return { on, off: { boxes: boxes().map((box) => box.checked), submits: state().submits } };'
        );

        $this->assertSame([true, true, true], $all['on']['boxes'], 'The "select all" box checks nothing.');
        $this->assertSame([false, false], $all['on']['submits'], 'Everything is checked and the buttons stay disabled.');
        $this->assertSame([false, false, false], $all['off']['boxes'], 'The "select all" box cannot be unticked again.');
        $this->assertSame([true, true], $all['off']['submits'], 'Unchecking everything leaves the buttons offered over nothing.');
    }

    // Nothing is preselected in that list precisely so a gallery standing first is never taken for a choice the admin made
    public function testTheMoveWaitsOnADestinationAsWellAsOnASelection(): void
    {
        $waited = $this->toolbar(
            'check("1");
             const chosen = state().move;
             pick("5");

             return { chosen, after: state().move };'
        );

        $this->assertTrue($waited['chosen'], 'The move is offered with medias checked and no gallery to move them to, which would send them nowhere.');
        $this->assertFalse($waited['after'], 'A selection and a destination together still cannot be moved.');
    }

    // The last entry creates the gallery instead of naming one that exists, so its title is awaited too
    public function testCreatingTheGalleryToMoveIntoRevealsItsTitleAndWaitsForIt(): void
    {
        $creating = $this->toolbar(
            'check("1");
             pick("new");
             const revealed = !title().classList.contains("d-none");
             const waiting = state().move;
             title().value = "  ";
             fire(title(), "input");
             const blank = state().move;
             title().value = "Randonnees";
             fire(title(), "input");

             return { revealed, waiting, blank, named: state().move, hidden: (pick("5"), title().classList.contains("d-none")) };'
        );

        $this->assertTrue($creating['revealed'], 'Choosing to create a gallery offers nowhere to name it.');
        $this->assertTrue($creating['waiting'], 'A gallery is created with no title at all, which names it and builds its url.');
        $this->assertTrue($creating['blank'], 'A title of nothing but spaces is taken for a name.');
        $this->assertFalse($creating['named'], 'A named gallery still cannot be created.');
        $this->assertTrue($creating['hidden'], 'The title box stays open beside a gallery that already exists.');
    }

    // The credits box and the title root of the move sit side by side, and a browser's implicit submission applies whichever button comes first
    public function testEnterInATextBoxReachesTheButtonOfItsOwnGroup(): void
    {
        $pressed = $this->toolbar(
            'check("1");
             pick("5");
             let submitted = null;
             root.querySelectorAll("button[type=submit]").forEach((button) => { button.addEventListener("click", (event) => { event.preventDefault(); submitted = button.dataset.name; }); });
             const event = new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true });
             root.querySelector("#title-root").dispatchEvent(event);

             return { submitted, prevented: event.defaultPrevented };'
        );

        $this->assertSame('move', $pressed['submitted'], 'Enter in the move group reached some other button, so the medias are credited when the person meant to move them.');
        $this->assertTrue($pressed['prevented'], 'The browser was left to submit the form on its own beside the button that was asked for.');
    }

    // A disabled button is one the selection is not ready for, and Enter must not get round that
    public function testEnterReachesNoButtonThatIsNotOfferedYet(): void
    {
        $this->assertNull(
            $this->toolbar(
                'let submitted = null;
                 root.querySelectorAll("button[type=submit]").forEach((button) => { button.addEventListener("click", () => { submitted = button.dataset.name; }); });
                 root.querySelector("#title-root").dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true }));

                 return submitted;'
            ),
            'Enter pressed in a text box submitted an action the selection is not ready for.'
        );
    }

    private function toolbar(string $probe, array $checked = []): mixed
    {
        $preamble = 'const boxes = () => [...root.querySelectorAll("[data-gallery-media-selection-target=checkbox]")];
             const toggle = () => root.querySelector("[data-gallery-media-selection-target=toggle]");
             const title = () => root.querySelector("[data-gallery-media-selection-target=newCategory]");
             // A real tick raises both, and Stimulus listens for "input" on a checkbox where it listens for "change" on a select
             const fire = (el, type) => {
                 for (const name of type ? [type] : ["input", "change"]) { el.dispatchEvent(new Event(name, { bubbles: true })); }
             };
             const check = (value) => { const box = root.querySelector("[value=\"" + value + "\"]"); box.checked = true; fire(box); };
             const pick = (value) => { const select = root.querySelector("[data-gallery-media-selection-target=moveTarget]"); select.value = value; fire(select); };
             const state = () => ({
                 submits: [...root.querySelectorAll("[data-gallery-media-selection-target=submit]")].map((button) => button.disabled),
                 move: root.querySelector("[data-gallery-media-selection-target=moveSubmit]").disabled,
                 toggle: { checked: toggle().checked, indeterminate: toggle().indeterminate },
             }); ';

        return $this->observe($this->page($checked), ['gallery-media-selection' => 'gallery-media-selection'], $preamble . $probe);
    }

    // The toolbar and the tiles under it, as the category screen renders them
    private function page(array $checked): string
    {
        $tiles = '';
        foreach (['1', '2', '3'] as $id) {
            $tiles .= sprintf(
                '<label><input type="checkbox" name="medias[]" value="%s" data-gallery-media-selection-target="checkbox" data-action="gallery-media-selection#update"%s></label>',
                $id,
                \in_array($id, $checked, true) ? ' checked' : ''
            );
        }

        return '<form method="post" data-controller="gallery-media-selection">
                <label><input type="checkbox" data-gallery-media-selection-target="toggle" data-action="gallery-media-selection#toggleAll"> Tout selectionner</label>
                <button type="submit" disabled data-name="credit" data-gallery-media-selection-target="submit">Crediter</button>
                <button type="submit" disabled data-name="delete" data-gallery-media-selection-target="submit">Mettre a la corbeille</button>
                <div>
                    <select name="targetCategory" data-gallery-media-selection-target="moveTarget" data-action="gallery-media-selection#update">
                        <option value="">Choisir une galerie</option>
                        <option value="5">Randonnees 2025</option>
                        <option value="new">Nouvelle galerie</option>
                    </select>
                    <input type="text" name="newCategoryTitle" class="d-none" data-gallery-media-selection-target="newCategory" data-action="input->gallery-media-selection#update keydown.enter->gallery-media-selection#submitOwn">
                    <input type="text" id="title-root" name="titleRoot" data-action="keydown.enter->gallery-media-selection#submitOwn">
                    <button type="submit" disabled data-name="move" data-gallery-media-selection-target="moveSubmit">Deplacer</button>
                </div>
                ' . $tiles . '
            </form>';
    }
}
