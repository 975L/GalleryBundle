import { startStimulusApp } from '@symfony/stimulus-bundle';
import GalleryMediaPreloadController from './js/gallery-media-preload.js';
import GalleryMediaProtectController from './js/gallery-media-protect.js';

// Front-end controllers, used on public pages. One Stimulus application per page: every c975L barrel joins the same one, whichever loads first starting it
globalThis.c975lStimulusApp ??= startStimulusApp();
const app = globalThis.c975lStimulusApp;
app.register('gallery-media-preload', GalleryMediaPreloadController);
app.register('gallery-media-protect', GalleryMediaProtectController);
