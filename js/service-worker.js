/**
 * Service Worker der App.
 *
 * In dieser Ausbaustufe bewusst schlank: Er muss vor allem existieren, einen
 * fetch-Handler besitzen (ohne den stuft der Browser die Seite als nicht
 * installierbar ein) und im richtigen Geltungsbereich laufen.
 *
 * Die vorhandene Logik der eigenstaendigen App - Offline-Cache der Aufnahmen,
 * Vorausladen und das Herausschneiden von Byte-Bereichen aus
 * zwischengespeicherten Dateien - wird in einem spaeteren Schritt uebernommen.
 */
const SHELL_CACHE = 'recordings-player-shell-v1';

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(caches.open(SHELL_CACHE));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => key.startsWith('recordings-player-shell-') && key !== SHELL_CACHE)
            .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  // Vorerst nur durchreichen. Der Handler ist trotzdem noetig, damit die
  // Seite als installierbar gilt.
  if (event.request.method !== 'GET') {
    return;
  }
  event.respondWith(fetch(event.request).catch(() => caches.match(event.request)));
});
