/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Drives the checkboxes under a category's medias (see management/gallery_category_edit.html.twig): the action buttons stay disabled until something is checked. Confirming the deletion is EasyAdmin's own business - its app.js opens the modal its delete actions use and submits the form from there
export default class extends Controller {
    static targets = ["checkbox", "toggle", "submit", "moveTarget", "moveSubmit", "newCategory"];

    // Also run on connect: a browser restoring the checked boxes on a back-navigation would otherwise leave the buttons disabled over a live selection
    connect() {
        this.update();
    }

    update() {
        const checked = this.checkboxTargets.filter((checkbox) => checkbox.checked);

        for (const submit of this.submitTargets) {
            submit.disabled = 0 === checked.length;
        }

        this.updateMove(checked.length);

        // Indeterminate on a partial selection: a "select all" box left unchecked over 8 medias out of 10 reads as "nothing selected"
        if (this.hasToggleTarget) {
            this.toggleTarget.checked = checked.length > 0 && checked.length === this.checkboxTargets.length;
            this.toggleTarget.indeterminate = checked.length > 0 && checked.length < this.checkboxTargets.length;
        }
    }

    // The move waits on a destination as well as on a selection: nothing is preselected in that list, precisely so a gallery standing first is never taken for a choice the admin made
    // The last entry creates the gallery instead of naming one that exists, so its title box is revealed and awaited too (see GalleryCategoryCrudController::createMoveTarget)
    updateMove(checked) {
        if (!this.hasMoveTargetTarget) {
            return;
        }

        const target = this.moveTargetTarget.value;
        const creating = "new" === target;

        this.newCategoryTarget.classList.toggle("d-none", !creating);
        this.moveSubmitTarget.disabled = 0 === checked || "" === target || (creating && "" === this.newCategoryTarget.value.trim());
    }

    // Enter in one of the toolbar's text boxes reaches the button of its own group, never the form's first submit: the credits box and the title root of the move sit side by side, and a browser's implicit submission would apply whichever button comes first
    submitOwn(event) {
        event.preventDefault();

        const submit = event.currentTarget.closest("div")?.querySelector("button[type='submit']");
        if (submit && !submit.disabled) {
            submit.click();
        }
    }

    toggleAll() {
        for (const checkbox of this.checkboxTargets) {
            checkbox.checked = this.toggleTarget.checked;
        }

        this.update();
    }
}
