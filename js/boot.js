/**
 * Startskript der Player-Seite.
 *
 * In dieser Ausbaustufe registriert es den Service Worker und zeigt an, ob
 * die Voraussetzungen fuer eine Installation als eigene App erfuellt sind.
 * Genau das ist die offene Frage, die vor dem weiteren Umbau geklaert
 * werden muss.
 */
(() => {
  'use strict';

  const config = document.getElementById('app-config');
  const swUrl = config.dataset.serviceWorker;
  const scope = config.dataset.scope;
  const publicToken = config.dataset.publicToken;

  const swState = document.getElementById('sw-state');
  const manifestState = document.getElementById('manifest-state');
  const modeState = document.getElementById('mode-state');

  modeState.textContent = publicToken
    ? 'Zugang: öffentliche Seite (ohne Nextcloud-Konto)'
    : 'Zugang: angemeldeter Nextcloud-Nutzer';

  // --- Service Worker ---
  if (!('serviceWorker' in navigator)) {
    swState.textContent = 'Service Worker: vom Browser nicht unterstützt';
  } else {
    navigator.serviceWorker.register(swUrl, { scope })
      .then((reg) => {
        swState.textContent = 'Service Worker: registriert für ' + reg.scope;
      })
      .catch((err) => {
        // Haeufigster Fall: Der Worker liegt nicht oberhalb des gewuenschten
        // Geltungsbereichs - dann lehnt der Browser die Registrierung ab.
        swState.textContent = 'Service Worker: fehlgeschlagen – ' + err.message;
      });
  }

  // --- Manifest ---
  const link = document.querySelector('link[rel="manifest"]');
  if (!link) {
    manifestState.textContent = 'Manifest: keine Verknüpfung im Dokument';
  } else {
    fetch(link.href, { credentials: 'same-origin' })
      .then((res) => res.ok ? res.json() : Promise.reject(new Error('HTTP ' + res.status)))
      .then((data) => {
        manifestState.textContent =
          'Manifest: geladen (start_url ' + data.start_url + ', scope ' + data.scope + ')';
      })
      .catch((err) => {
        manifestState.textContent = 'Manifest: nicht ladbar – ' + err.message;
      });
  }

  // Chrome meldet hierueber, dass alle Bedingungen fuer eine Installation
  // erfuellt sind - der verlaesslichste Nachweis, den wir bekommen koennen.
  window.addEventListener('beforeinstallprompt', () => {
    const note = document.createElement('p');
    note.textContent = 'Installierbar: ja (Browser bietet die Installation an)';
    document.getElementById('prototype-check').appendChild(note);
  });
})();
