<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Template;

use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// What the detail screen shows of the payload a site declared (see c975L\GalleryBundle\Field\GalleryDataField). Rendered for real rather than read as a string: the point of this template is what it does with a value whose shape it does not know
class GalleryDataFieldTemplateTest extends TestCase
{
    // The ordinary case, a flat payload printed key by key
    public function testEachFieldIsPrintedUnderItsKey(): void
    {
        $rendered = $this->render(['photographer' => 'Laurent', 'roll' => 12]);

        $this->assertStringContainsString('<strong>photographer</strong> : Laurent', $rendered);
        $this->assertStringContainsString('<strong>roll</strong> : 12', $rendered);
    }

    // A site is free to group its fields, and a nested payload used to be handed to the printer as an array - a fatal rather than a screen
    public function testANestedPayloadIsPrintedAsJsonRatherThanFatalling(): void
    {
        $rendered = $this->render(['camera' => ['brand' => 'Leica', 'lens' => '35mm']]);

        $this->assertStringContainsString('<strong>camera</strong> : {&quot;brand&quot;:&quot;Leica&quot;,&quot;lens&quot;:&quot;35mm&quot;}', $rendered);
    }

    // A checkbox stores a boolean, which printed as it comes reads as 1 or as an empty cell
    public function testABooleanIsPrintedAsAWord(): void
    {
        $rendered = $this->render(['analog' => true, 'cropped' => false]);

        $this->assertStringContainsString('<strong>analog</strong> : true', $rendered);
        $this->assertStringContainsString('<strong>cropped</strong> : false', $rendered);
    }

    // A media carrying nothing is most of them, and it renders no row at all
    public function testAnEmptyPayloadPrintsNothing(): void
    {
        $this->assertSame('', trim($this->render([])));
        $this->assertSame('', trim($this->render(null)));
    }

    /** @param array<string, mixed>|null $data */
    private function render(?array $data): string
    {
        $field = new FieldDto();
        $field->setValue($data);

        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'));

        return $twig->render('management/field_data.html.twig', ['field' => $field]);
    }
}
