/**
 * Service Worker der App.
 *
 * Aufgaben:
 *   1. Audio aus dem Offline-Speicher ausliefern (inkl. Byte-Bereichen)
 *   2. Oberflaeche (Seite, Stylesheet, Skripte, Bilder) offline bereithalten
 *
 * Zwei Erfahrungen aus frueheren Fassungen stecken hier drin:
 *
 * a) Ein Service Worker erbt die Sicherheitsrichtlinie der ANTWORT, mit der
 *    er selbst ausgeliefert wurde. Er kommt hier aus einem Controller, der
 *    ihm ausdruecklich "connect-src 'self'" mitgibt - sonst duerfte er gar
 *    nichts abrufen und wuerde die ganze Seite lahmlegen.
 *
 * b) Wer respondWith() aufruft, uebernimmt die volle Verantwortung fuer die
 *    Antwort. Deshalb wird hier so wenig wie moeglich abgefangen, und jeder
 *    Zweig liefert in jedem Fall eine gueltige Antwort.
 */

const SHELL_CACHE = 'audioarchive-shell-v10';

// Beide Audio-Speicher sind bewusst NICHT versioniert: Sie sollen
// App-Updates ueberleben, damit heruntergeladene Aufnahmen nicht verloren
// gehen.
const OFFLINE_AUDIO_CACHE = 'audioarchive-offline-audio';
const PREFETCH_CACHE = 'audioarchive-prefetch-audio';

/** Adresse der zuletzt erfolgreich geladenen Seite, fuer den Offline-Start. */
const PAGE_CACHE_KEY = 'audioarchive-last-page';

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(caches.open(SHELL_CACHE));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => key.startsWith('audioarchive-shell-') && key !== SHELL_CACHE)
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

/**
 * Beantwortet eine Audio-Anfrage aus einem der beiden Speicher.
 *
 * Besonderheit: Das <audio>-Element fordert Audio praktisch immer per
 * HTTP-Range-Request an. Im Speicher liegt aber die vollstaendige Datei als
 * gewoehnliche 200-Antwort, weil sich Teilantworten (206) nicht ablegen
 * lassen. Deshalb wird das angeforderte Stueck hier selbst herausgeschnitten -
 * ohne das koennte man offline nicht spulen, und auf manchen Geraeten wuerde
 * die Wiedergabe gar nicht erst starten.
 */
async function serveAudioFromCache(request) {
  let cached = null;

  for (const name of [OFFLINE_AUDIO_CACHE, PREFETCH_CACHE]) {
    const cache = await caches.open(name);
    // Ohne ignoreSearch: Der Pfad steckt im Abfrageteil und muss genau passen.
    cached = await cache.match(request.url);
    if (cached) break;
  }

  if (!cached) return null;

  const rangeHeader = request.headers.get('range');
  if (!rangeHeader) return cached;

  const match = /^bytes=(\d*)-(\d*)$/.exec(rangeHeader.trim());
  if (!match) return cached;

  const buffer = await cached.arrayBuffer();
  const total = buffer.byteLength;

  let start = match[1] === '' ? null : parseInt(match[1], 10);
  let end = match[2] === '' ? null : parseInt(match[2], 10);

  if (start === null && end !== null) {
    // Form "bytes=-500": die letzten N Bytes
    start = Math.max(0, total - end);
    end = total - 1;
  } else {
    if (start === null) start = 0;
    if (end === null || end >= total) end = total - 1;
  }

  if (start > end || start >= total) {
    return new Response(null, {
      status: 416,
      headers: { 'Content-Range': `bytes */${total}` },
    });
  }

  const slice = buffer.slice(start, end + 1);

  return new Response(slice, {
    status: 206,
    statusText: 'Partial Content',
    headers: {
      'Content-Type': cached.headers.get('Content-Type') || 'audio/mpeg',
      'Content-Length': String(slice.byteLength),
      'Content-Range': `bytes ${start}-${end}/${total}`,
      'Accept-Ranges': 'bytes',
    },
  });
}

/**
 * Ist das eine Seite ohne Nextcloud-Leiste? Das sind die installierbare
 * Fassung (/app) und die oeffentliche Seite (/s/<token>).
 */
function isStandalonePage(url) {
  const path = url.pathname.replace(/\/+$/, '');
  return path.endsWith('/audioarchive/app') || /\/audioarchive\/s\/[^/]+$/.test(path);
}

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  const sameOrigin = url.origin === self.location.origin;

  // ---------- Seitenaufrufe ----------
  if (request.mode === 'navigate') {
    /*
     * redirect: 'manual' ist hier entscheidend. Nextcloud beantwortet die
     * App-Adresse mit einer Weiterleitung; folgt der Worker ihr selbst,
     * erhaelt er eine als "weitergeleitet" markierte Antwort - und die darf
     * bei einem Seitenaufruf nicht an respondWith() uebergeben werden. Mit
     * 'manual' bekommt er stattdessen eine Antwort, die der Browser selbst
     * aufloest.
     */
    event.respondWith(
      fetch(request, { redirect: 'manual' })
        .then((response) => {
          // Letzte funktionierende Seite fuer den Offline-Start aufheben -
          // aber nur die Fassungen OHNE Nextcloud-Leiste. Die eingebettete
          // Seite braucht Nextclouds Skripte und Stylesheets, die hier
          // nicht gespeichert werden; offline bliebe sie leer.
          if (response && response.ok && response.type === 'basic' && isStandalonePage(url)) {
            const copy = response.clone();
            caches.open(SHELL_CACHE).then((cache) => cache.put(PAGE_CACHE_KEY, copy));
          }
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(PAGE_CACHE_KEY);
          return cached || new Response(
            '<!DOCTYPE html><meta charset="utf-8"><p>Keine Verbindung.</p>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
        })
    );
    return;
  }

  if (!sameOrigin) {
    return;
  }

  // ---------- Audio ----------
  if (url.pathname.endsWith('/api/stream')) {
    event.respondWith(
      serveAudioFromCache(request)
        .then((cached) => cached || fetch(request))
        .catch(() => fetch(request))
    );
    return;
  }

  // ---------- Ordnerliste ----------
  /*
   * Erst Netz, bei Ausfall aus dem Speicher.
   *
   * Beim Offline-Speichern eines Ordners wird dessen Liste mit abgelegt.
   * Dadurch stehen ohne Verbindung auch Kuenstler, Album und Spieldauer zur
   * Verfuegung - die stecken naemlich NUR in dieser Antwort, nicht in den
   * Audiodateien selbst. Jeder Aufruf mit Verbindung frischt den Speicher
   * nebenbei auf.
   */
  if (url.pathname.endsWith('/api/list')) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response && response.ok && response.type === 'basic') {
            const copy = response.clone();
            caches.open(OFFLINE_AUDIO_CACHE).then((cache) => {
              // Nur auffrischen, was bereits gespeichert ist - sonst wuerde
              // sich der Speicher mit Ordnern fuellen, die gar nicht offline
              // verfuegbar sind.
              cache.match(request.url).then((existing) => {
                if (existing) cache.put(request.url, copy);
              });
            });
          }
          return response;
        })
        .catch(async () => {
          const cache = await caches.open(OFFLINE_AUDIO_CACHE);
          const cached = await cache.match(request.url);
          return cached || new Response(
            JSON.stringify({ error: 'offline' }),
            { status: 503, headers: { 'Content-Type': 'application/json' } }
          );
        })
    );
    return;
  }

  // ---------- Uebrige Schnittstellen: immer aus dem Netz ----------
  // Anmeldung und Einstellungen sind zu veraenderlich zum Speichern.
  /*
   * Cover (ab 0.14): zuerst aus dem Offline-Speicher (beim Speichern eines
   * Ordners mit abgelegt), sonst aus dem Netz. Ohne Verbindung und ohne
   * gespeichertes Bild ein 404 - der Player zeigt dann das App-Symbol.
   */
  if (url.pathname.endsWith('/api/cover')) {
    event.respondWith(
      caches.open(OFFLINE_AUDIO_CACHE)
        .then((cache) => cache.match(request.url))
        .then((cached) => cached || fetch(request))
        .catch(() => new Response('', { status: 404 }))
    );
    return;
  }

  if (url.pathname.includes('/api/')) {
    return;
  }

  // ---------- Oberflaeche ----------
  // Stylesheet, Skripte und Bilder: erst Netz, dann Speicher. So kommen
  // Aenderungen sofort an, und offline sieht die App trotzdem nicht kaputt
  // aus. Die Adressen tragen eine Versionskennung, alte Fassungen werden
  // also nie faelschlich weiterverwendet.
  //
  // Nur Dateien dieser App: Innerhalb von Nextcloud laufen auch Nextclouds
  // eigene Skripte und Stylesheets hier durch. Die gehoeren nicht in
  // diesen Speicher. Ausnahme: die Theming-App. Bei Nextcloud-Gestaltung
  // kommen Farben und Hintergrund von dort, und ohne sie saehe die
  // installierte App offline ungestaltet aus.
  if (!url.pathname.includes('/audioarchive/') && !url.pathname.includes('/apps/theming/')) {
    return;
  }

  if (/\.(css|js|png|jpe?g|webp|svg|webmanifest)$/.test(url.pathname) || url.search.includes('v=')) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response && response.ok && response.type === 'basic') {
            const copy = response.clone();
            caches.open(SHELL_CACHE).then((cache) => cache.put(request, copy));
          }
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          return cached || new Response('', { status: 504 });
        })
    );
  }
});
