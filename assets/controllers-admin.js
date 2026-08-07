import { startStimulusApp } from '@symfony/stimulus-bundle';
import GalleryMediaSelectionController from './js/gallery-media-selection.js';
import GalleryMediaSortController from './js/gallery-media-sort.js';
import GalleryUploadLimitsController from './js/gallery-upload-limits.js';

// Back-office controllers, used only in EasyAdmin. Loaded as its own <script type="module"> tag (see importmap.php), starts its own Stimulus app
// Kebab-case on purpose: the identifier is what Stimulus derives data-gallery-upload-limits-* from, and a camelCase one would look for data-galleryUploadLimits-* instead
const app = startStimulusApp();
app.register('gallery-media-selection', GalleryMediaSelectionController);
app.register('gallery-media-sort', GalleryMediaSortController);
app.register('gallery-upload-limits', GalleryUploadLimitsController);
