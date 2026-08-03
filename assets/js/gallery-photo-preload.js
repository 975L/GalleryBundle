/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Warms the browser cache for the previous/next photo while the current one is being looked at, so clicking prev/next doesn't show a blank image while it loads - same new Image() technique as UiBundle's slider.js preloadSliderImages(), applied to just the two neighbours instead of a whole slide list
export default class extends Controller {
    connect() {
        this.preload(this.element.dataset.previousUrl);
        this.preload(this.element.dataset.nextUrl);
    }

    preload(url) {
        if (url) {
            new Image().src = url;
        }
    }
}
