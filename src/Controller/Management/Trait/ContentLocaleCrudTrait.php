<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller\Management\Trait;

use c975L\ConfigBundle\Management\ContentLocaleScreen;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Service\GalleryTranslator;
use c975L\UiBundle\Contract\TrashableInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Intl\Locales;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

// "The same edit screen, opened on another language" for the gallery's three prose screens, written once and overriding nothing - a class method wins over a trait's, so each screen calls addContentLocaleParameters() and stageContentLocale() from its own methods
trait ContentLocaleCrudTrait
{
    abstract protected function adminContextProvider(): AdminContextProviderInterface;

    abstract protected function contentLocaleScreen(): ContentLocaleScreen;

    abstract protected function galleryTranslator(): GalleryTranslator;

    /** @return array<string, TranslatableInterface> field => the label this screen gives it */
    abstract protected function translationFieldLabels(): array;

    // The language this row is being written in, when it is not the one the site was written in (see ContentLocaleScreen)
    private function contentLocale(): ?string
    {
        return $this->contentLocaleScreen()->locale($this->galleryTranslator()->getTranslatableLocales());
    }

    // The row the screen is open on, and null on a "new" screen or on anything this gallery does not translate
    private function translatableRow(): GalleryCategory | GalleryMedia | GalleryPrintFormat | null
    {
        $entity = $this->adminContextProvider()->getContext()?->getEntity()?->getInstance();

        return null === $entity ? null : $this->asTranslatableRow($entity);
    }

    // The same reading of one entity, for the form's own submission: what a screen hands over is the row it was open on, and this is what says so in a type the translator accepts
    private function asTranslatableRow(object $entity): GalleryCategory | GalleryMedia | GalleryPrintFormat | null
    {
        return $entity instanceof GalleryCategory || $entity instanceof GalleryMedia || $entity instanceof GalleryPrintFormat
            ? $entity
            : null;
    }

    // What a language screen offers: each translatable text, unmapped so the row's own text is never overwritten, holding what that language says or the bracketed source text where it says nothing yet
    /** @return list<FieldInterface> */
    private function translationFields(string $locale): array
    {
        $row = $this->translatableRow();
        if (null === $row) {
            return [];
        }

        $values = $this->galleryTranslator()->promptValues($row, $locale);
        $labels = $this->translationFieldLabels();

        $fields = [
            FormField::addFieldset(t('label.fieldset_this_language', ['%language%' => Locales::getName($locale, $locale)], 'gallery'))
                ->setHelp(t('label.fieldset_this_language_help', [], 'gallery')),
        ];

        foreach ($this->galleryTranslator()->fields($row) as $position => $field) {
            // The first is the row's own name - a line, and the one a listing, a tile and a <title> read; what follows it is prose
            $fields[] = 0 === $position
                ? TextField::new($field)
                    ->setLabel($labels[$field] ?? $field)
                    ->setRequired(false)
                    ->setFormTypeOption('mapped', false)
                    ->setFormTypeOption('data', $values[$field])
                : TextareaField::new($field)
                    ->setLabel($labels[$field] ?? $field)
                    ->setRequired(false)
                    ->setFormTypeOption('mapped', false)
                    ->setFormTypeOption('data', $values[$field])
                    // Opt-in marker read by the block form theme, which is what puts Donovan under a plain textarea
                    ->setFormTypeOption('attr', ['data-ai-rephrase' => true]);
        }

        return $fields;
    }

    // Opens the first language screen straight from the list, the way BookBundle's catalog, SiteBundle's pages and ShopBundle's products are translated - the tabs above a row already opened are the only other way in, and a translation screen nobody finds translates nothing
    private function translateAction(): Action
    {
        return $this->contentLocaleScreen()
            ->action('translate', t('action.translate', [], 'gallery'), 'fa fa-language', $this->galleryTranslator()->getTranslatableLocales())
            ->displayIf(fn (object $entity): bool => $this->galleryTranslator()->isActive() && !($entity instanceof TrashableInterface && $entity->isDeleted()))
            ->addCssClass('btn btn-secondary');
    }

    // What the language tabs at the top of the edit screen need, and nothing at all where the row is not saved yet or the site declares a single language
    private function addContentLocaleParameters(KeyValueStore $responseParameters): void
    {
        $id = $this->translatableRow()?->getId();
        if (null !== $id && $this->galleryTranslator()->isActive()) {
            $this->contentLocaleScreen()->addParameters($responseParameters, self::class, $id, $this->galleryTranslator()->getTranslatableLocales(), $this->contentLocale());
        }
    }

    // What a language screen wrote, handed over to be stored on the flush that saves the row and never before it (see ContentLocaleScreen::stageOnSubmit)
    private function stageContentLocale(FormBuilderInterface $formBuilder): void
    {
        $contentLocale = $this->contentLocale();
        $row = $this->translatableRow();

        $this->contentLocaleScreen()->stageOnSubmit(
            $formBuilder,
            $contentLocale,
            null === $row ? [] : $this->galleryTranslator()->fields($row),
            function (object $entity, array $values) use ($contentLocale): void {
                $row = $this->asTranslatableRow($entity);
                if (null !== $contentLocale && null !== $row) {
                    $this->galleryTranslator()->stage($row, $contentLocale, $values);
                }
            }
        );
    }
}
