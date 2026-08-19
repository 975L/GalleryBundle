<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Service\GalleryMediaArchiver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class GalleryMediaArchiverTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/gallery-archiver-test-' . uniqid();
        new Filesystem()->mkdir($this->projectDir . '/public');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    private function createArchiver(): GalleryMediaArchiver
    {
        return new GalleryMediaArchiver($this->projectDir);
    }

    // The stored file's own name, from which the highres one is derived - the media is given the shape a real upload leaves behind
    private function createMedia(string $slug, string $storedName = 'voyages-abc-123.webp', ?string $originalName = null): GalleryMedia
    {
        $media = new GalleryMedia()
            ->setSlug($slug)
            ->setFilename('medias/gallery/voyages/' . $storedName)
        ;

        // Set apart from the chain above: unlike every other setter of the entity, this one answers nothing back
        $media->setOriginalFilename(null === $originalName ? null : 'medias/gallery/voyages/' . $originalName);

        return $media;
    }

    private function writeFile(string $relativePath, string $content = 'file'): void
    {
        $path = $this->projectDir . '/' . $relativePath;
        new Filesystem()->mkdir(\dirname($path));
        file_put_contents($path, $content);
    }

    /** @return string[] the names the archive holds */
    private function entriesOf(string $archivePath): array
    {
        $zip = new \ZipArchive();
        $zip->open($archivePath);

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $entries[] = (string) $zip->getNameIndex($index);
        }
        $zip->close();

        return $entries;
    }

    // The highres derivative sits next to the stored file, and is what the site never links as a file
    public function testTheHighresVariantPacksTheHighresFiles(): void
    {
        $this->writeFile('public/medias/gallery/voyages/voyages-abc-123-highres.webp');

        $response = $this->createArchiver()->archive([$this->createMedia('mont-blanc')], GalleryMediaArchiver::VARIANT_HIGHRES, 'voyages');

        $this->assertNotNull($response);
        $this->assertSame(['mont-blanc.webp'], $this->entriesOf($response->getFile()->getPathname()));
    }

    // The kept original lives outside public/, which is the whole reason a download action exists at all
    public function testTheOriginalVariantPacksTheKeptOriginals(): void
    {
        $this->writeFile('private/medias/gallery/voyages/voyages-abc-123-original.jpg');

        $response = $this->createArchiver()->archive(
            [$this->createMedia('mont-blanc', originalName: 'voyages-abc-123-original.jpg')],
            GalleryMediaArchiver::VARIANT_ORIGINAL,
            'voyages'
        );

        $this->assertNotNull($response);
        // The extension is the file's own: an original keeps the format it was shot in, where every derivative is a webp
        $this->assertSame(['mont-blanc.jpg'], $this->entriesOf($response->getFile()->getPathname()));
    }

    // A batch uploaded without keeping the originals has nothing to hand back - a message to show, not an empty zip to open
    public function testASelectionWithNoFileAtAllGivesNoArchive(): void
    {
        $this->assertNull($this->createArchiver()->archive(
            [$this->createMedia('mont-blanc')],
            GalleryMediaArchiver::VARIANT_ORIGINAL,
            'voyages'
        ));
    }

    // One media missing its file never costs the others theirs
    public function testAMediaWhoseFileIsGoneIsSkippedRatherThanFailing(): void
    {
        $this->writeFile('public/medias/gallery/voyages/voyages-abc-123-highres.webp');

        $response = $this->createArchiver()->archive(
            [$this->createMedia('mont-blanc'), $this->createMedia('cervin', 'voyages-def-456.webp')],
            GalleryMediaArchiver::VARIANT_HIGHRES,
            'voyages'
        );

        $this->assertNotNull($response);
        $this->assertSame(['mont-blanc.webp'], $this->entriesOf($response->getFile()->getPathname()));
    }

    // A gallery stored before slugs existed is the only case two medias can name one entry
    public function testTwoMediasNamingOneEntryAreKeptApart(): void
    {
        $this->writeFile('public/medias/gallery/voyages/voyages-abc-123-highres.webp');
        $this->writeFile('public/medias/gallery/voyages/voyages-def-456-highres.webp');

        $media = $this->createMedia('mont-blanc');
        $sibling = $this->createMedia('mont-blanc', 'voyages-def-456.webp');

        $response = $this->createArchiver()->archive([$media, $sibling], GalleryMediaArchiver::VARIANT_HIGHRES, 'voyages');

        $this->assertNotNull($response);
        $this->assertSame(['mont-blanc.webp', 'mont-blanc-2.webp'], $this->entriesOf($response->getFile()->getPathname()));
    }

    // What the caller compares to the cap before anything is written
    public function testWeighAddsUpOnlyTheFilesReallyThere(): void
    {
        $this->writeFile('public/medias/gallery/voyages/voyages-abc-123-highres.webp', str_repeat('x', 120));

        $medias = [$this->createMedia('mont-blanc'), $this->createMedia('cervin', 'voyages-def-456.webp')];

        $this->assertSame(120, $this->createArchiver()->weigh($medias, GalleryMediaArchiver::VARIANT_HIGHRES));
    }

    // The archive is the one file the download leaves in the temporary directory - the name tempnam() reserves is written into, never left empty next to a .zip nobody would clean up
    public function testTheArchiveLeavesNoSecondTemporaryFileBehind(): void
    {
        $this->writeFile('public/medias/gallery/voyages/voyages-abc-123-highres.webp');

        $before = glob(sys_get_temp_dir() . '/gallery_medias_*') ?: [];

        $response = $this->createArchiver()->archive([$this->createMedia('mont-blanc')], GalleryMediaArchiver::VARIANT_HIGHRES, 'voyages');

        $this->assertNotNull($response);

        $created = array_values(array_diff(glob(sys_get_temp_dir() . '/gallery_medias_*') ?: [], $before));
        $this->assertSame([$response->getFile()->getPathname()], $created);

        unlink($response->getFile()->getPathname());
    }

    // The archive is downloaded, not stored: it is named after the category and dropped once sent
    public function testTheArchiveIsSentAsADeletedAttachment(): void
    {
        $this->writeFile('public/medias/gallery/voyages/voyages-abc-123-highres.webp');

        $response = $this->createArchiver()->archive([$this->createMedia('mont-blanc')], GalleryMediaArchiver::VARIANT_HIGHRES, 'voyages');

        $this->assertNotNull($response);
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('voyages-highres', (string) $response->headers->get('Content-Disposition'));
    }
}
