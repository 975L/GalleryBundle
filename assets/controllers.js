import { startStimulusApp } from '@symfony/stimulus-bundle';
import GalleryPhotoPreloadController from './js/gallery-photo-preload.js';

// Front-end controllers, used on public pages
const app = startStimulusApp();
app.register('gallery-photo-preload', GalleryPhotoPreloadController);
