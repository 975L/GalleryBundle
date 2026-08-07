/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Weighs a selection against the server's own ceilings the moment it is made, rather than letting php enforce them the way it does: past max_file_uploads the extra files are dropped in silence and the screen reports a success over part of the batch, and past post_max_size the request arrives empty. Both are unrecoverable server-side - by the time php has truncated, what was sent is gone - so the check belongs here, before the upload starts
export default class extends Controller {
    static targets = ["input", "message", "submit"];

    static values = {
        maxFiles: Number,
        maxFileSize: Number,
        maxBatchSize: Number,
        filesMessage: String,
        sizeMessage: String,
        batchMessage: String,
    };

    check() {
        const files = Array.from(this.inputTarget.files || []);
        const errors = [];

        if (files.length > this.maxFilesValue) {
            errors.push(this.fill(this.filesMessageValue, {
                "%count%": files.length,
                "%limit%": this.maxFilesValue,
            }));
        }

        // Named one by one: "a file is too big" over a selection of 150 leaves the person to find which
        for (const file of files.filter((file) => file.size > this.maxFileSizeValue)) {
            errors.push(this.fill(this.sizeMessageValue, {
                "%name%": file.name,
                "%size%": this.megabytes(file.size),
                "%limit%": this.megabytes(this.maxFileSizeValue),
            }));
        }

        const total = files.reduce((sum, file) => sum + file.size, 0);
        if (total > this.maxBatchSizeValue) {
            errors.push(this.fill(this.batchMessageValue, {
                "%size%": this.megabytes(total),
                "%limit%": this.megabytes(this.maxBatchSizeValue),
            }));
        }

        this.report(errors);
    }

    // The submit button is disabled rather than the submission cancelled, so what is wrong is readable while the selection is being fixed
    report(errors) {
        if (this.hasMessageTarget) {
            this.messageTarget.innerHTML = "";
            for (const error of errors) {
                const line = document.createElement("p");
                line.textContent = error;
                this.messageTarget.appendChild(line);
            }
            this.messageTarget.hidden = 0 === errors.length;
        }

        for (const button of this.submitButtons()) {
            button.disabled = errors.length > 0;
        }
    }

    // Falls back to whatever submit the scope holds, EasyAdmin rendering the buttons of its own screens and leaving no place to declare a target on them
    submitButtons() {
        return this.hasSubmitTarget ? this.submitTargets : this.element.querySelectorAll('[type="submit"]');
    }

    fill(message, replacements) {
        return Object.entries(replacements).reduce(
            (text, [placeholder, value]) => text.replaceAll(placeholder, String(value)),
            message
        );
    }

    // One decimal: a file of 7.1 MB against a limit of 2 MB has to read as a size, not as a rounded 7
    megabytes(bytes) {
        return (bytes / (1024 * 1024)).toFixed(1);
    }
}
