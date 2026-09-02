<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Email;

use c975L\UiBundle\Contract\EmailTemplateProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The letters a print order sends, as templates an admin rewrites rather than Twig files only a developer can touch -
 * the same shape as PaymentBundle's own provider, whose comment explains the reasoning at length.
 *
 * None of them restates what the customer bought and what it cost: that is the order confirmation's business, and
 * saying it again here would send two e-mails carrying the same thing.
 */
class GalleryEmailTemplateProvider implements EmailTemplateProviderInterface
{
    // The languages this bundle ships a catalogue for, listed rather than read from the site's locales: the translator answers every locale by falling back on the default one, so iterating them would seed a German row holding French sentences
    private const array LOCALES = ['fr', 'en', 'es'];

    // The name GalleryPrintEmail asks for when a numbered edition is sold
    public const TEMPLATE_EDITION_SOLD = 'gallery_edition_sold';

    // The name it asks for when the shop has a certificate to sign
    public const TEMPLATE_EDITION_SIGNATURE = 'gallery_edition_signature';

    // The name it asks for when the lab reports the prints have left
    public const TEMPLATE_PRINT_SHIPPED = 'gallery_print_shipped';

    // The name it asks for when a lab cancels an order it had accepted
    public const TEMPLATE_PRINT_CANCELLED = 'gallery_print_cancelled';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getEmailTemplates(): array
    {
        $templates = [];

        foreach (self::LOCALES as $locale) {
            foreach ($this->structure($locale) as $name => $blocks) {
                $templates[$name][$locale] = $blocks;
            }
        }

        return $templates;
    }

    /**
     * @return array<string, list<array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: ?string}>>
     */
    private function structure(string $locale): array
    {
        return [
            self::TEMPLATE_EDITION_SOLD => [
                $this->text('email.edition_sold_intro', $locale, ['%numbers%' => '{{ numbers }}']),
                $this->text('email.edition_sold_certificate', $locale),
                ['button', null, null, null, $this->trans('email.edition_sold_verify', $locale), '{{ certificate_url }}'],
            ],
            // No button back to the back-office: the address of an EasyAdmin screen is only generated inside an admin request, and this letter leaves from a customer's checkout. What it carries instead is what the admin needs to find the order - the numbers
            self::TEMPLATE_EDITION_SIGNATURE => [
                $this->text('email.edition_signature_intro', $locale, ['%numbers%' => '{{ numbers }}']),
                $this->text('email.edition_signature_what_next', $locale),
            ],
            // Written to the shop and not to the buyer: an order cancelled is an order to refund, and a letter saying so before the money has left would be the shop promising what nobody has done yet
            self::TEMPLATE_PRINT_CANCELLED => [
                $this->text('email.print_cancelled_intro', $locale, ['%number%' => '{{ number }}']),
                $this->text('email.print_cancelled_what_next', $locale),
            ],
            // No tracking number: a lab shipping white-label posts the parcel under the shop's name and reports a stage, not a carrier's reference
            self::TEMPLATE_PRINT_SHIPPED => [
                $this->text('email.print_shipped_intro', $locale),
                $this->text('email.print_shipped_care', $locale),
            ],
        ];
    }

    /** @return array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: ?string} */
    private function text(string $key, string $locale, array $parameters = []): array
    {
        return ['text', null, null, $this->trans($key, $locale, $parameters), null, null];
    }

    // A catalogue parameter becomes the "{{ name }}" an EmailTemplate block substitutes, so an admin rewriting the sentence in the back-office sees the placeholder the editor documents
    private function trans(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'gallery', $locale);
    }
}
