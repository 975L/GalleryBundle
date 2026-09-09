import { startStimulusApp } from '@symfony/stimulus-bundle';
import GalleryMediaPreloadController from './js/gallery-media-preload.js';
import GalleryMediaProtectController from './js/gallery-media-protect.js';

// Front-end controllers, used on public pages
const app = startStimulusApp();
app.register('gallery-media-preload', GalleryMediaPreloadController);
app.register('gallery-media-protect', GalleryMediaProtectController);
