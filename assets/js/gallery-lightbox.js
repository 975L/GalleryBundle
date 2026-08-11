/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Opens the high resolution over the page instead of on a page of its own: browsing a category stays on the stored (medium) files, and the heavy one is only ever fetched for the media the visitor actually asks to see
export default class extends Controller {
    static targets = ["dialog", "image"];

    open(event) {
        // The link is a real one, pointing at the file itself, so it still opens the high resolution when this script doesn't run
        event.preventDefault();

        // Assigned on the first opening only, the browser cache serving the next ones
        if (!this.imageTarget.getAttribute("src")) {
            this.imageTarget.src = event.currentTarget.href;
        }

        this.dialogTarget.showModal();
    }

    // Anything clicked inside closes - the image and the backdrop alike, which is why there is no close button. Escape is handled by the dialog itself
    close() {
        this.dialogTarget.close();
    }
}
