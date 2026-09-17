/**
 * Startskript der Player-Seite.
 *
 * Stand: Prueffassung. Sie weist nach, dass die Grundlagen tragen -
 * Installierbarkeit als PWA, Zugangspruefung, Ordnerliste und Ausgabe der
 * Audiodateien. Die vollstaendige Oberflaeche folgt im naechsten Schritt.
 */
(() => {
  'use strict';

  const config = document.getElementById('app-config');
  const swUrl = config.dataset.serviceWorker;
  const scope = config.dataset.scope;
  const publicToken = config.dataset.publicToken;

  const api = (name) => scope + 'api/' + name;

  const swState = document.getElementById('sw-state');
  const manifestState = document.getElementById('manifest-state');
  const modeState = document.getElementById('mode-state');
  const loginBox = document.getElementById('public-login');
  const loginError = document.getElementById('public-login-error');
  const apiHeading = document.getElementById('api-heading');
  const apiResult = document.getElementById('api-result');

  modeState.textContent = publicToken
    ? 'Zugang: öffentliche Seite (ohne Nextcloud-Konto)'
    : 'Zugang: angemeldeter Nextcloud-Nutzer';

  // ---------- Service Worker ----------
  if (!('serviceWorker' in navigator)) {
    swState.textContent = 'Service Worker: vom Browser nicht unterstützt';
  } else {
    navigator.serviceWorker.register(swUrl, { scope })
      .then((reg) => { swState.textContent = 'Service Worker: registriert für ' + reg.scope; })
      .catch((err) => { swState.textContent = 'Service Worker: fehlgeschlagen – ' + err.message; });
  }

  // ---------- Manifest ----------
  const link = document.querySelector('link[rel="manifest"]');
  if (link) {
    fetch(link.href, { credentials: 'same-origin' })
      .then((res) => res.ok ? res.json() : Promise.reject(new Error('HTTP ' + res.status)))
      .then((data) => {
        manifestState.textContent =
          'Manifest: geladen (start_url ' + data.start_url + ', scope ' + data.scope + ')';
      })
      .catch((err) => { manifestState.textContent = 'Manifest: nicht ladbar – ' + err.message; });
  }

  window.addEventListener('beforeinstallprompt', () => {
    const note = document.createElement('p');
    note.textContent = 'Installierbar: ja (Browser bietet die Installation an)';
    document.getElementById('prototype-check').appendChild(note);
  });

  // ---------- Zugang und Ordnerliste ----------
  async function loadFolder(path) {
    const url = api('list') + '?path=' + encodeURIComponent(path || '');
    const res = await fetch(url, { credentials: 'same-origin' });

    if (res.status === 401) {
      // Nicht freigeschaltet: Auf der oeffentlichen Seite nach dem Passwort fragen
      loginBox.hidden = !publicToken;
      apiHeading.hidden = true;
      apiResult.textContent = publicToken ? '' : 'Kein Zugang.';
      return;
    }

    const data = await res.json();

    if (!res.ok) {
      apiHeading.hidden = true;
      apiResult.textContent = data.error || ('Fehler ' + res.status);
      return;
    }

    loginBox.hidden = true;
    apiHeading.hidden = false;
    render(data);
  }

  function render(data) {
    apiResult.innerHTML = '';

    const info = document.createElement('p');
    info.textContent = 'Ordner: "' + (data.path || '(Hauptordner)') + '" · '
      + data.entries.length + ' Einträge · Funktionen: offline='
      + data.features.offline + ', download=' + data.features.download;
    apiResult.appendChild(info);

    if (data.path !== '') {
      const up = document.createElement('button');
      up.type = 'button';
      up.textContent = '⬆ eine Ebene zurück';
      up.addEventListener('click', () => loadFolder(data.parent || ''));
      apiResult.appendChild(up);
    }

    data.entries.forEach((entry) => {
      const row = document.createElement('p');

      if (entry.type === 'dir') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '📁 ' + entry.name + ' (' + entry.count + ')';
        btn.addEventListener('click', () => loadFolder(entry.path));
        row.appendChild(btn);
      } else {
        // Dauer und Tags stammen aus der Datei selbst - hier sichtbar
        // gemacht, damit der portierte Parser gegengeprueft werden kann.
        const label = document.createElement('span');
        const mins = entry.duration
          ? Math.floor(entry.duration / 60) + ':' + String(Math.round(entry.duration % 60)).padStart(2, '0')
          : '–';
        const tags = [entry.artist, entry.album].filter(Boolean).join(' · ');
        label.textContent = '🎵 ' + (entry.title || entry.name) + ' [' + mins + ']'
          + (tags ? ' – ' + tags : '');
        row.appendChild(label);

        const audio = document.createElement('audio');
        audio.controls = true;
        audio.preload = 'none';
        audio.src = api('stream') + '?path=' + encodeURIComponent(entry.path);
        row.appendChild(audio);
      }

      apiResult.appendChild(row);
    });
  }

  // ---------- Anmeldung der oeffentlichen Seite ----------
  document.getElementById('public-login-btn').addEventListener('click', async () => {
    loginError.textContent = '';
    const password = document.getElementById('public-password').value;

    try {
      const res = await fetch(api('public/login'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ token: publicToken, password }),
      });

      if (res.ok) {
        document.getElementById('public-password').value = '';
        loadFolder('');
      } else {
        const data = await res.json().catch(() => ({}));
        loginError.textContent = data.error || 'Anmeldung fehlgeschlagen.';
      }
    } catch (err) {
      loginError.textContent = 'Verbindung fehlgeschlagen.';
    }
  });

  loadFolder('');
})();
