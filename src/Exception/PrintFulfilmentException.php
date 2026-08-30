<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Exception;

// The lab refused an order or could not be reached. Never fatal to the customer, who has paid and whose order stands: what it interrupts is the sending, which the back-office then shows as pending for a human to retry or to pass by hand
class PrintFulfilmentException extends \RuntimeException
{
}
