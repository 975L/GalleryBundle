/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";
import { addSortGesture } from "@c975l/ui-bundle/pointer-sort.js";

// Arranges a category's medias (see management/gallery_category_edit.html.twig): their order, by dragging their thumbnails around, and the cover representing the category, by the radio each of them carries
// The gesture is UiBundle's (pointer-sort.js, mouse and finger alike), this controller owning only where a dragged tile lands - a wrapping grid of thumbnails has nothing in common with the vertical list of rows ea-sortable computes for a blocks collection
// Saved on the spot: the grid is not part of the edit form above (an html form never nests in another), so its Save button would leave the arrangement behind without anything saying so
export default class extends Controller {
    static targets = ["grid", "item"];
    static values = { url: String, token: String, failedLabel: String };

    // Armed per tile as it appears rather than once in connect(), so a tile added to the grid later gets its gesture too
    itemTargetConnected(item) {
        const gesture = {
            item,
            onStart: () => this.pickUp(item),
            onMove: (dragged, x, y) => this.moveTo(dragged, x, y),
            onDrop: () => this.drop(item),
            onCancel: () => this.revert(item),
        };

        // The handle is the one grab point at the finger: "touch-action: none" over the whole tile would leave a screenful of thumbnails with nowhere left to scroll the page from
        const handle = item.querySelector("[data-gallery-media-sort-handle]");
        if (handle) addSortGesture(handle, gesture);

        // The tile itself stays grabbable with a mouse. Its own clicks survive - the thumbnail's link opens the media, the two boxes still tick - only a real drag gesture hijacking them
        addSortGesture(item, { ...gesture, touch: false, ignore: "label, [data-gallery-media-sort-handle]" });
    }

    // Where the tile was picked up, read back at the drop: by then it already sits wherever it was dragged to
    pickUp(item) {
        this.origin = item.nextElementSibling;
        item.style.opacity = "0.4";
    }

    // Computed from the pointer rather than hit-tested: the grid wraps, so the tile to insert before is the first one the pointer is above and left of the middle of - and a gap between two tiles has to land somewhere too
    moveTo(dragged, x, y) {
        const grid = this.gridTarget.getBoundingClientRect();
        if (x < grid.left || x > grid.right || y < grid.top || y > grid.bottom) return;

        const before = this.itemTargets.find((item) => {
            if (item === dragged) return false;

            const tile = item.getBoundingClientRect();

            return y < tile.bottom && x < tile.left + tile.width / 2;
        });

        if (before) this.gridTarget.insertBefore(dragged, before);
        else this.gridTarget.appendChild(dragged);
    }

    drop(item) {
        item.style.opacity = "";

        // A tile put back where it was picked up saves nothing
        if (item.nextElementSibling !== this.origin) this.save();
    }

    // insertBefore() with a null reference appends, which is where a tile dragged from the last place belongs
    revert(item) {
        item.style.opacity = "";
        this.gridTarget.insertBefore(item, this.origin);
    }

    // Order and cover go together on every call, whichever of the two was just changed: they are one row's two sides server-side, and sending both spares a second route for the second of them
    save() {
        const body = new URLSearchParams();
        this.itemTargets.forEach((item) => body.append("mediaOrder[]", item.dataset.mediaId));
        body.append("coverMediaId", this.cover());

        fetch(this.urlValue, {
            method: "POST",
            headers: { "X-CSRF-Token": this.tokenValue },
            body,
        })
            .then((response) => {
                if (!response.ok) this.failed();
            })
            .catch(() => this.failed());
    }

    cover() {
        const checked = this.element.querySelector('input[name="coverMediaId"]:checked');

        return checked ? checked.value : "";
    }

    // Reloaded rather than put back by hand: the screen then shows what was actually saved, whatever the call failed on
    failed() {
        window.alert(this.failedLabelValue);
        window.location.reload();
    }
}
