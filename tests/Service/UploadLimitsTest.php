<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Service\UploadLimits;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class UploadLimitsTest extends TestCase
{
    public function testReadsTheIniValuesItIsGiven(): void
    {
        $limits = new UploadLimits('30', '8M', '64M');

        $this->assertSame(30, $limits->getMaxFiles());
        $this->assertSame(8 * 1024 ** 2, $limits->getMaxFileSize());
        $this->assertSame(64 * 1024 ** 2, $limits->getMaxBatchSize());
    }

    // A php.ini size is written K/M/G or as a plain byte count, and the shorthand is binary - 2M is 2097152, not 2000000
    public function testConvertsEveryPhpIniSizeShorthand(): void
    {
        $this->assertSame(1024, new UploadLimits(uploadMaxFilesize: '1K')->getMaxFileSize());
        $this->assertSame(2 * 1024 ** 2, new UploadLimits(uploadMaxFilesize: '2M')->getMaxFileSize());
        $this->assertSame(500_000, new UploadLimits(uploadMaxFilesize: '500000')->getMaxFileSize());

        // 1G against this bundle's own 20M ceiling: the smaller wins
        $this->assertSame(20 * 1024 ** 2, new UploadLimits(uploadMaxFilesize: '1G')->getMaxFileSize());
    }

    // Raising php's ceiling past this bundle's changes nothing, and vice versa - the screen has to state the one that really applies
    public function testTheSmallerOfThePhpAndBundleCeilingsApplies(): void
    {
        $this->assertSame(2 * 1024 ** 2, new UploadLimits(uploadMaxFilesize: '2M')->getMaxFileSize());
        $this->assertSame(20 * 1024 ** 2, new UploadLimits(uploadMaxFilesize: '100M')->getMaxFileSize());
    }

    // Same rule on the count: a host allowing 500 files in one request says nothing about what resizing 500 medias costs within it
    public function testTheFileCountIsCappedByThisBundleToo(): void
    {
        $this->assertSame(20, new UploadLimits('20')->getMaxFiles());
        $this->assertSame(100, new UploadLimits('100')->getMaxFiles());
        $this->assertSame(100, new UploadLimits('500')->getMaxFiles());
    }

    // A full batch of files at the per-file ceiling caps the request as surely as post_max_size does
    public function testTheBatchCeilingIsWhicheverComesFirst(): void
    {
        // 10 files x 2M is below a post_max_size of 500M
        $this->assertSame(10 * 2 * 1024 ** 2, new UploadLimits('10', '2M', '500M')->getMaxBatchSize());

        // 100 files x 20M is well above a post_max_size of 8M
        $this->assertSame(8 * 1024 ** 2, new UploadLimits('100', '20M', '8M')->getMaxBatchSize());
    }

    // "0" means no limit at all in php.ini, which must never read as "nothing may be uploaded"
    public function testAnUnlimitedSettingFallsBackOnWhatTheOtherOneAllows(): void
    {
        $limits = new UploadLimits('20', '5M', '0');

        $this->assertSame(20 * 5 * 1024 ** 2, $limits->getMaxBatchSize());
    }

    // A video is not a photograph: this bundle's 20 MiB ceiling would refuse any video worth uploading, so what is left is php's own per-file limit alone
    public function testTheVideoCeilingIsPhpOwnAndIgnoresTheBundleOne(): void
    {
        $this->assertSame(64 * 1024 ** 2, new UploadLimits(uploadMaxFilesize: '64M')->getMaxVideoFileSize());

        // Where a photograph would be capped at 20 MiB
        $this->assertSame(1024 ** 3, new UploadLimits(uploadMaxFilesize: '1G')->getMaxVideoFileSize());
        $this->assertSame(20 * 1024 ** 2, new UploadLimits(uploadMaxFilesize: '1G')->getMaxFileSize());
    }

    // Below the bundle's ceiling php's limit is still the one that applies, there being nothing else to cap a video with
    public function testAVideoIsCappedByAPhpLimitSmallerThanTheBundleOneToo(): void
    {
        $this->assertSame(2 * 1024 ** 2, new UploadLimits(uploadMaxFilesize: '2M')->getMaxVideoFileSize());
    }

    public function testStatesAFigureInWholeMegabytes(): void
    {
        $limits = new UploadLimits();

        $this->assertSame(20, $limits->toMegabytes(20 * 1024 ** 2));
        $this->assertSame(2, $limits->toMegabytes(2 * 1024 ** 2 + 1024));

        // Never 0: a ceiling below a megabyte still has to read as a ceiling
        $this->assertSame(1, $limits->toMegabytes(500));
    }

    // Past post_max_size php hands over a POST with nothing in it, the browser having sent the whole batch - only Content-Length is left to tell that apart from an empty request
    public function testABatchPhpEmptiedIsRecognisedByItsContentLength(): void
    {
        $limits = new UploadLimits();

        $this->assertTrue($limits->isTruncatedRequest(Request::create('/gallery-upload', 'POST', [], [], [], ['CONTENT_LENGTH' => 500_000_000])));
    }

    public function testNothingElseIsTakenForABatchPhpEmptied(): void
    {
        $limits = new UploadLimits();

        // A GET carries no content of its own
        $this->assertFalse($limits->isTruncatedRequest(Request::create('/gallery-upload', 'GET', [], [], [], ['CONTENT_LENGTH' => 500_000_000])));

        // A POST that arrived whole
        $this->assertFalse($limits->isTruncatedRequest(Request::create('/gallery-upload', 'POST', ['credits' => 'Studio 975L'])));

        // A POST carrying nothing at all, which is not a batch thrown away
        $this->assertFalse($limits->isTruncatedRequest(Request::create('/gallery-upload', 'POST')));
    }

    // Left to itself, it describes the php actually running - whatever that php.ini says
    public function testFallsBackOnTheRunningPhpConfiguration(): void
    {
        $limits = new UploadLimits();

        $this->assertGreaterThan(0, $limits->getMaxFiles());
        $this->assertGreaterThan(0, $limits->getMaxFileSize());
        $this->assertGreaterThan(0, $limits->getMaxBatchSize());
        $this->assertGreaterThan(0, $limits->getMaxVideoFileSize());
    }
}
