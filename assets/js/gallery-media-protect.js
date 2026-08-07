/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Blocks the two gestures a photo leaves the page by - the context menu's "save image as", and dragging the file out. A deterrent, deliberately nothing more: the file is still in the browser cache and the developer tools still show its url, and a photographer's real protection remains what is served (see the medium/high resolution split) rather than what is forbidden
export default class extends Controller {
    // Declared on the container, so a whole grid is covered by one controller
    block(event) {
        event.preventDefault();
    }
}
