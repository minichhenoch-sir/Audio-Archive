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
const SHELL_CACHE = 'audioarchive-shell-v2';

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(caches.open(SHELL_CACHE));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => key.startsWith('audioarchive-shell-') && key !== SHELL_CACHE)
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

  event.respondWith(
    fetch(event.request).catch(async () => {
      const cached = await caches.match(event.request);

      /*
       * Wichtig: respondWith() braucht IMMER eine Response. Liefert
       * caches.match() nichts (undefined), entsteht sonst ein
       * "Failed to convert value to 'Response'" - und der eigentliche
       * Grund des fehlgeschlagenen Abrufs wird dadurch verschleiert.
       * Deshalb hier eine klare eigene Antwort erzeugen.
       */
      return cached || new Response('Offline und nicht gespeichert.', {
        status: 504,
        statusText: 'Gateway Timeout',
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      });
    })
  );
});
