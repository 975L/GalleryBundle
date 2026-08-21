<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Field;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;

// The fields a site adds to a gallery or to a media, rendered from the form type it declares (see c975L\GalleryBundle\Contract\GalleryCustomizationProviderInterface). A field of its own rather than one of EasyAdmin's: an untyped field resolves off the Doctrine type, and a json column resolves to ArrayField, whose configurator hands the form type collection options ("allow_add", "entry_type"...) a plain form knows nothing of - TextField's own refuses the array outright
final class GalleryDataField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName, $label = null): self
    {
        return new self()
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplatePath('@c975LGallery/management/field_data.html.twig')
            ->hideOnIndex()
        ;
    }
}
