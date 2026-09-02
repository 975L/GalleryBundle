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

// assets/js/gallery-upload-limits.js weighed against selections a browser really made
// This is the one check that has no server-side counterpart to fall back on: past max_file_uploads php drops the extra files in silence and the screen reports a success over part of the batch, and past post_max_size the request arrives empty. By the time php has truncated, what was sent is gone - so either this works before the upload starts, or nothing does. And a selection is a FileList, which nothing but a browser can hand a script
#[Group('browser')]
class GalleryUploadLimitsBehaviourTest extends JsCase
{
    private const int MEGABYTE = 1048576;

    public function testASelectionWithinEveryCeilingSaysNothing(): void
    {
        $accepted = $this->upload('pick([["vacances.jpg", 1], ["montagne.jpg", 1]]); return state();');

        $this->assertSame([], $accepted['lines'], 'A selection the server would accept is reported as a problem.');
        $this->assertTrue($accepted['hidden'], 'An empty message block is left standing above the form.');
        $this->assertFalse($accepted['disabled'], 'A selection within every ceiling cannot be sent.');
    }

    // Past max_file_uploads the extra files are dropped in silence, and the screen would report a success over part of the batch
    public function testTooManyFilesAtOnceIsRefusedWithBothNumbers(): void
    {
        $refused = $this->upload('pick([["a.jpg", 1], ["b.jpg", 1], ["c.jpg", 1], ["d.jpg", 1]]); return state();');

        $this->assertSame(['4 fichiers choisis pour un maximum de 3'], $refused['lines'], 'A selection of more files than the server accepts is not reported, or is reported without saying how many too many.');
        $this->assertTrue($refused['disabled'], 'A batch the server would silently truncate can still be sent.');
    }

    // "A file is too big" over a selection of a hundred and fifty leaves the person to find which - and a selection breaking two ceilings is told about both at once rather than one fix at a time
    public function testEveryFileOverTheCeilingIsNamedOneByOneAndEveryCeilingAtOnce(): void
    {
        $named = $this->upload('pick([["petite.jpg", 1], ["enorme.jpg", 3], ["geante.jpg", 4]]); return state();');

        $this->assertSame(
            [
                'enorme.jpg pese 3.0 Mo pour un maximum de 2.0 Mo',
                'geante.jpg pese 4.0 Mo pour un maximum de 2.0 Mo',
                'Le lot pese 8.0 Mo pour un maximum de 4.0 Mo',
            ],
            $named['lines'],
            'The files that are too big are not named one by one, so the person has to find them among the rest - or the ceilings are reported one at a time, each fix uncovering the next.'
        );
    }

    // One decimal: a file of 7.1 MB against a limit of 2 MB has to read as a size, not as a rounded 7
    public function testASizeIsWrittenWithItsDecimalRatherThanRounded(): void
    {
        $this->assertSame(
            ['grande.jpg pese 2.5 Mo pour un maximum de 2.0 Mo'],
            $this->upload('pickBytes([["grande.jpg", 2621440]]); return state().lines;'),
            'A size is rounded to the megabyte, which reads as a file at the very limit rather than over it.'
        );
    }

    // Past post_max_size the request arrives empty, whatever each file weighs on its own
    public function testABatchOverWhatOneRequestCanCarryIsRefusedOnItsTotal(): void
    {
        $refused = $this->upload('pick([["a.jpg", 2], ["b.jpg", 2], ["c.jpg", 2]]); return state();');

        $this->assertSame(['Le lot pese 6.0 Mo pour un maximum de 4.0 Mo'], $refused['lines'], 'A batch that would arrive empty is not reported at all.');
        $this->assertTrue($refused['disabled']);
    }

    // The button is disabled rather than the submission cancelled, so what is wrong stays readable while the selection is being fixed - and readable means gone once it is
    public function testFixingTheSelectionTakesTheReportBackAndGivesTheButtonBack(): void
    {
        $fixed = $this->upload(
            'pick([["enorme.jpg", 3]]);
             const during = state();
             pick([["petite.jpg", 1]]);

             return { during, after: state() };'
        );

        $this->assertTrue($fixed['during']['disabled']);
        $this->assertSame([], $fixed['after']['lines'], 'The previous report is still on screen over a selection that no longer has anything wrong with it.');
        $this->assertTrue($fixed['after']['hidden'], 'The message block stays open over nothing.');
        $this->assertFalse($fixed['after']['disabled'], 'A corrected selection still cannot be sent.');
    }

    // The name is the person's own, typed on their machine and put on screen here
    public function testAFileNameReachesTheReportAsTextAndNeverAsMarkup(): void
    {
        $written = $this->upload('pick([["<img src=x onerror=alert(1)>.jpg", 3]]); return { html: message().innerHTML, images: message().querySelectorAll("img").length, lines: state().lines };');

        $this->assertSame(0, $written['images'], 'A file name was written into the page as markup, so naming a file is enough to run a script in the back-office.');
        $this->assertStringContainsString('&lt;img', $written['html']);
        $this->assertStringContainsString('<img src=x onerror=alert(1)>.jpg pese', $written['lines'][0], 'The name shown is not the one the file carries.');
    }

    // EasyAdmin renders the buttons of its own screens and leaves no place to declare a target on them
    public function testAScreenDeclaringNoButtonHasWhateverSubmitItHoldsDisabled(): void
    {
        $this->assertTrue(
            (bool) $this->upload('pick([["enorme.jpg", 3]]); return root.querySelector("[type=submit]").disabled;', false),
            'A screen whose submit button this bundle does not render can send a batch the server would refuse.'
        );
    }

    private function upload(string $probe, bool $declared = true): mixed
    {
        $preamble = sprintf(
            'const input = () => root.querySelector("[data-gallery-upload-limits-target=input]");
             const message = () => root.querySelector("[data-gallery-upload-limits-target=message]");
             const state = () => ({
                 lines: [...message().querySelectorAll("p")].map((line) => line.textContent),
                 hidden: message().hidden,
                 disabled: root.querySelector("[type=submit]").disabled,
             });
             // A selection as the browser hands one over, which is the only way a FileList is ever made
             const pickBytes = (files) => {
                 const transfer = new DataTransfer();
                 for (const [name, size] of files) { transfer.items.add(new File([new Uint8Array(size)], name)); }
                 input().files = transfer.files;
                 input().dispatchEvent(new Event("change", { bubbles: true }));
             };
             const pick = (files) => pickBytes(files.map(([name, megabytes]) => [name, megabytes * %d])); ',
            self::MEGABYTE
        );

        return $this->observe($this->page($declared), ['gallery-upload-limits' => 'gallery-upload-limits'], $preamble . $probe);
    }

    // The upload screen as gallery_media_upload.html.twig renders it, the ceilings being the server's own read off php.ini
    private function page(bool $declared): string
    {
        return sprintf(
            '<form data-controller="gallery-upload-limits"
                data-gallery-upload-limits-max-files-value="3"
                data-gallery-upload-limits-max-file-size-value="%d"
                data-gallery-upload-limits-max-batch-size-value="%d"
                data-gallery-upload-limits-files-message-value="%%count%% fichiers choisis pour un maximum de %%limit%%"
                data-gallery-upload-limits-size-message-value="%%name%% pese %%size%% Mo pour un maximum de %%limit%% Mo"
                data-gallery-upload-limits-batch-message-value="Le lot pese %%size%% Mo pour un maximum de %%limit%% Mo">
                <input type="file" multiple data-gallery-upload-limits-target="input" data-action="change->gallery-upload-limits#check">
                <div data-gallery-upload-limits-target="message" hidden></div>
                <button type="submit"%s>Envoyer</button>
            </form>',
            2 * self::MEGABYTE,
            4 * self::MEGABYTE,
            $declared ? ' data-gallery-upload-limits-target="submit"' : ''
        );
    }
}
