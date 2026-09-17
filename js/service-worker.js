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
const SHELL_CACHE = 'audioarchive-shell-v4';

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
  /*
   * Der Handler muss vorhanden sein, damit der Browser die Seite als
   * installierbar einstuft - er greift aber (noch) NICHT in die Anfragen ein.
   *
   * Hintergrund: Wer hier respondWith() aufruft, uebernimmt die volle
   * Verantwortung fuer die Antwort. Schlaegt der Abruf im Worker fehl,
   * faellt nicht nur eine Datei aus, sondern die Seite verliert auf einen
   * Schlag Stylesheet, Skript und Bilder - und der wahre Grund ist hinter
   * der Ersatzantwort nicht mehr zu sehen.
   *
   * Der Offline-Betrieb wird hier in einem spaeteren Schritt gezielt
   * ergaenzt: dann nur fuer die Audio-Endpunkte und mit Antwort aus dem
   * Cache statt einer weitergereichten Netzwerkantwort.
   */
  if (event.request.method !== 'GET') {
    return;
  }
});
