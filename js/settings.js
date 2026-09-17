/**
 * Verwaltungs-Einstellungen.
 *
 * Bewusst ohne Aufbauwerkzeuge (kein Vue, kein Bündler): Die Seite hat wenige
 * Felder, und so bleibt die App ohne Übersetzungsschritt installierbar - auch
 * für alle, die sie später aus dem App Store beziehen.
 */
(() => {
  'use strict';

  const APP_ID = 'audioarchive';

  /**
   * Liest die vom Server mitgegebenen Startwerte. Nextcloud legt sie als
   * base64-kodiertes JSON in ein verstecktes Element.
   */
  function initialState() {
    const el = document.getElementById('initial-state-' + APP_ID + '-settings');
    if (!el) return {};
    try {
      return JSON.parse(atob(el.value));
    } catch (err) {
      return {};
    }
  }

  const state = initialState();

  const el = (id) => document.getElementById(id);

  const folder = el('aa-folder');
  const folderOwner = el('aa-folder-owner');
  const publicEnabled = el('aa-public-enabled');
  const publicPassword = el('aa-public-password');
  const publicPasswordState = el('aa-public-password-state');
  const publicUrlRow = el('aa-public-url-row');
  const publicUrl = el('aa-public-url');
  const title = el('aa-title');
  const subtitle = el('aa-subtitle');
  const accent = el('aa-accent');
  const bar = el('aa-bar');
  const base = el('aa-base');
  const featureOffline = el('aa-feature-offline');
  const featureDownload = el('aa-feature-download');
  const status = el('aa-status');

  // ---------- Startwerte einsetzen ----------
  folder.value = state.sourceFolder || '';
  publicEnabled.checked = !!state.publicEnabled;
  title.value = state.headerTitle || '';
  subtitle.value = state.headerSubtitle || '';
  accent.value = state.themeAccent || '#b9793f';
  bar.value = state.themeBar || '#291c12';
  base.value = state.themeBase || '#a86a3d';
  featureOffline.checked = state.featureOffline !== false;
  featureDownload.checked = state.featureDownload === true;

  if (state.sourceFolderOwner) {
    folderOwner.textContent =
      'Die Aufnahmen werden aus den Dateien von „' + state.sourceFolderOwner
      + '" gelesen – auch beim öffentlichen Zugang.';
  }

  publicPasswordState.textContent = state.hasPublicPassword
    ? 'Ein Passwort ist gesetzt. Feld leer lassen, um es unverändert zu lassen.'
    : 'Noch kein Passwort gesetzt.';

  function showPublicUrl(url) {
    if (url) {
      publicUrl.value = url;
      publicUrlRow.hidden = false;
    } else {
      publicUrlRow.hidden = true;
    }
  }
  showPublicUrl(state.publicUrl);

  // ---------- Ordnerauswahl ----------
  el('aa-folder-pick').addEventListener('click', () => {
    // Nextclouds eigener Dateidialog. Ist er nicht verfügbar (ältere oder
    // abweichende Fassung), kann der Pfad von Hand eingetragen werden.
    if (window.OC && OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
      OC.dialogs.filepicker(
        'Ordner mit den Aufnahmen wählen',
        (path) => { folder.value = path; },
        false,
        'httpd/unix-directory',
        true,
        OC.dialogs.FILEPICKER_TYPE_CHOOSE
      );
      return;
    }

    folder.readOnly = false;
    folder.focus();
    setStatus('Dateidialog nicht verfügbar – bitte den Pfad von Hand eintragen.', true);
  });

  // ---------- Speichern ----------
  function setStatus(message, isError) {
    status.textContent = message;
    status.classList.toggle('aa-status--error', !!isError);
  }

  el('aa-save').addEventListener('click', async () => {
    setStatus('Speichere …', false);

    const payload = {
      sourceFolder: folder.value,
      publicEnabled: publicEnabled.checked,
      headerTitle: title.value,
      headerSubtitle: subtitle.value,
      themeAccent: accent.value,
      themeBar: bar.value,
      themeBase: base.value,
      featureOffline: featureOffline.checked,
      featureDownload: featureDownload.checked,
    };

    // Leeres Feld bedeutet: Passwort unverändert lassen
    if (publicPassword.value !== '') {
      payload.publicPassword = publicPassword.value;
    }

    try {
      const res = await fetch(OC.generateUrl('/apps/' + APP_ID + '/settings/admin'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // Ohne dieses Token weist Nextcloud die Anfrage ab
          'requesttoken': OC.requestToken,
        },
        body: JSON.stringify(payload),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        setStatus(data.error || 'Speichern fehlgeschlagen.', true);
        return;
      }

      if (publicPassword.value !== '') {
        publicPassword.value = '';
        publicPasswordState.textContent =
          'Ein Passwort ist gesetzt. Feld leer lassen, um es unverändert zu lassen.';
      }

      showPublicUrl(data.publicUrl);
      setStatus('Gespeichert.', false);
    } catch (err) {
      setStatus('Verbindung fehlgeschlagen.', true);
    }
  });

  // ---------- Link kopieren ----------
  el('aa-public-copy').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(publicUrl.value);
      setStatus('Link kopiert.', false);
    } catch (err) {
      publicUrl.select();
      setStatus('Bitte von Hand kopieren.', true);
    }
  });
})();
