<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Form;

use c975L\GalleryBundle\Form\GalleryMediaBatchUploadType;
use c975L\GalleryBundle\Service\UploadLimits;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use c975L\UiBundle\Service\UploadProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

class GalleryMediaBatchUploadTypeTest extends TestCase
{
    // 20 files of 2M each, a 8M request: a default php install, and the three figures the screen has to state
    private function createType(): GalleryMediaBatchUploadType
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => [] === $parameters
                ? $id
                : $id . ':' . implode(',', $parameters)
        );

        return new GalleryMediaBatchUploadType(new UploadLimits('20', '2M', '8M'), $translator, new UploadProgress($translator));
    }

    private function buildFields(array $options): array
    {
        $added = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $type = null, array $fieldOptions = []) use (&$added, $builder) {
            $added[$name] = ['type' => $type, 'options' => $fieldOptions];

            return $builder;
        });

        $this->createType()->buildForm($builder, $options);

        return $added;
    }

    // A whole batch is picked at once, and shares its credits and rights - what varies media by media is retouched afterwards in the media edit screen
    public function testBuildFormAddsAMultipleFileFieldWithTheSharedCreditsAndRights(): void
    {
        $added = $this->buildFields(['category_title' => 'Voyages']);

        $this->assertSame(FileType::class, $added['files']['type']);
        $this->assertTrue($added['files']['options']['multiple']);
        $this->assertInstanceOf(All::class, $added['files']['options']['constraints'][0]);

        $this->assertSame(TextType::class, $added['credits']['type']);
        $this->assertFalse($added['credits']['options']['required']);

        $this->assertSame(CheckboxType::class, $added['rightsReserved']['type']);
        $this->assertFalse($added['rightsReserved']['options']['required']);
    }

    // The signature is stamped into the pixels of every derivative, so a batch answers for it at upload time or never - and the corner is left empty by default, taking the one set site-wide
    public function testBuildFormOffersTheWatermarkAndItsFourCorners(): void
    {
        $added = $this->buildFields(['category_title' => 'Voyages']);

        $this->assertSame(CheckboxType::class, $added['watermark']['type']);
        $this->assertFalse($added['watermark']['options']['required']);

        $this->assertSame(ChoiceType::class, $added['watermarkPosition']['type']);
        $this->assertFalse($added['watermarkPosition']['options']['required']);
        $this->assertSame('label.gallery_watermark_position_default', $added['watermarkPosition']['options']['placeholder']);
        $this->assertSame(
            VichWatermarkableInterface::POSITIONS,
            array_values($added['watermarkPosition']['options']['choices'])
        );
    }

    // The uploaded file is what the highres derivative is cut from, so the ceiling has to clear an original rather than a web-sized copy - lowering it silently caps the quality of every media added from then on
    public function testBuildFormAcceptsAnUploadUpToTwentyMegabytes(): void
    {
        $added = $this->buildFields(['category_title' => 'Voyages']);

        $constraint = $added['files']['options']['constraints'][0]->constraints[0];

        // Stated in bytes, so php.ini's binary shorthand and the constraint's decimal one can never disagree by a megabyte
        $this->assertInstanceOf(Image::class, $constraint);
        $this->assertSame(20 * 1024 ** 2, $constraint->maxSize);
    }

    // The ceilings reach the browser through the form tag itself, so the check survives an app overriding gallery_media_upload.html.twig - and they are the effective ones, php's own included
    public function testConfigureOptionsCarriesTheServerCeilingsOntoTheFormTag(): void
    {
        $type = $this->createType();
        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        $attr = $resolver->resolve(['category_title' => 'Voyages'])['attr'];

        $this->assertSame('gallery-upload-limits upload-progress', $attr['data-controller']);
        $this->assertSame(20, $attr['data-gallery-upload-limits-max-files-value']);
        // 2M from php.ini wins over this bundle's own 20M, and 20 files x 2M stays under the 8M request ceiling
        $this->assertSame(2 * 1024 ** 2, $attr['data-gallery-upload-limits-max-file-size-value']);
        $this->assertSame(8 * 1024 ** 2, $attr['data-gallery-upload-limits-max-batch-size-value']);

        // Translated here, javascript having no translator of its own
        $this->assertSame('label.gallery_upload_too_many_files', $attr['data-gallery-upload-limits-files-message-value']);
        $this->assertSame('label.gallery_upload_file_too_large', $attr['data-gallery-upload-limits-size-message-value']);
        $this->assertSame('label.gallery_upload_batch_too_large', $attr['data-gallery-upload-limits-batch-message-value']);
    }

    // A batch of fifty photos is minutes of transfer and processing, and a screen showing nothing of it reads as a submit that never took - the bar is UiBundle's, armed over the ceilings this form already declares (see UploadProgress)
    public function testConfigureOptionsArmsTheProgressBarAlongsideTheCeilings(): void
    {
        $type = $this->createType();
        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        $attr = $resolver->resolve(['category_title' => 'Voyages'])['attr'];

        $this->assertSame('submit->upload-progress#send', $attr['data-action']);
        $this->assertSame('label.upload_progress_uploading', $attr['data-upload-progress-uploading-message-value']);
        $this->assertSame('label.upload_progress_processing', $attr['data-upload-progress-processing-message-value']);
        $this->assertSame('label.upload_progress_failed', $attr['data-upload-progress-failed-message-value']);
    }

    // The same figures again, stated in the field's help before anything is picked
    public function testTheFilesFieldStatesTheCeilingsAndIsWatchedByTheController(): void
    {
        $added = $this->buildFields(['category_title' => 'Voyages']);

        $this->assertSame('label.gallery_batch_files_help:20,2,8', $added['files']['options']['help']);
        $this->assertSame('input', $added['files']['options']['attr']['data-gallery-upload-limits-target']);
        $this->assertSame('change->gallery-upload-limits#check', $added['files']['options']['attr']['data-action']);
    }

    // Medias are only ever added from within a category, which the url carries - the field shows it without ever submitting it back
    public function testBuildFormShowsTheCategoryAsADisabledUnmappedField(): void
    {
        $added = $this->buildFields(['category_title' => 'Voyages']);

        $this->assertSame(TextType::class, $added['category']['type']);
        $this->assertFalse($added['category']['options']['mapped']);
        $this->assertTrue($added['category']['options']['disabled']);
        $this->assertSame('Voyages', $added['category']['options']['data']);
    }

    public function testConfigureOptionsDefaultsToNoDataClassAndGalleryTranslationDomain(): void
    {
        $type = $this->createType();
        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        $options = $resolver->resolve(['category_title' => 'Voyages']);

        $this->assertNull($options['data_class']);
        $this->assertSame('gallery', $options['translation_domain']);
    }

    // The category is what the screen displays and what the controller attaches every media to - a caller forgetting it would show an unlabelled form
    public function testConfigureOptionsRequiresTheCategoryTitleOption(): void
    {
        $this->expectException(MissingOptionsException::class);

        $type = $this->createType();
        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        $resolver->resolve();
    }
}
