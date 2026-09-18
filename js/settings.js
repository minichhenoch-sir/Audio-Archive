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
  const publicSlug = el('aa-public-slug');
  const title = el('aa-title');
  const subtitle = el('aa-subtitle');
  const accent = el('aa-accent');
  const bar = el('aa-bar');
  const base = el('aa-base');
  const betaEnabled = el('aa-beta-enabled');
  const betaText = el('aa-beta-text');
  const betaLinkUrl = el('aa-beta-link-url');
  const betaLinkLabel = el('aa-beta-link-label');
  const designCustom = el('aa-design-custom');
  const designNextcloud = el('aa-design-nextcloud');
  const customDesign = el('aa-custom-design');
  const backgroundNextcloud = el('aa-background-nextcloud');
  const userCustomization = el('aa-user-customization');
  const userShares = el('aa-user-shares');
  const featureOffline = el('aa-feature-offline');
  const featureDownload = el('aa-feature-download');
  const status = el('aa-status');

  // ---------- Startwerte einsetzen ----------
  folder.value = state.sourceFolder || '';
  publicEnabled.checked = !!state.publicEnabled;
  publicSlug.value = state.publicSlug || '';
  title.value = state.headerTitle || '';
  subtitle.value = state.headerSubtitle || '';
  accent.value = state.themeAccent || '#b9793f';
  bar.value = state.themeBar || '#291c12';
  base.value = state.themeBase || '#a86a3d';
  betaEnabled.checked = state.betaEnabled === true;
  betaText.value = state.betaText || '';
  betaLinkUrl.value = state.betaLinkUrl || '';
  betaLinkLabel.value = state.betaLinkLabel || '';
  if (state.design === 'nextcloud') {
    designNextcloud.checked = true;
  } else {
    designCustom.checked = true;
  }

  /*
   * Farben und Hintergrundbild wirken nur bei eigener Gestaltung. Sie
   * bleiben trotzdem bedienbar - wer zurueckwechselt, findet seine Werte
   * unveraendert vor.
   */
  function updateDesignState() {
    customDesign.classList.toggle('aa-inactive', designNextcloud.checked);
  }
  designCustom.addEventListener('change', updateDesignState);
  designNextcloud.addEventListener('change', updateDesignState);
  updateDesignState();

  backgroundNextcloud.checked = state.backgroundNextcloud === true;
  userCustomization.checked = state.userCustomization !== false;
  userShares.checked = state.userShares !== false;
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

  // ---------- Hintergrundbild ----------
  const backgroundFile = el('aa-background-file');
  const backgroundRemove = el('aa-background-remove');
  const backgroundState = el('aa-background-state');

  function showBackgroundState(hasImage) {
    backgroundRemove.hidden = !hasImage;
    backgroundState.textContent = hasImage
      ? 'Ein Hintergrundbild ist gesetzt.'
      : 'Kein Hintergrundbild gesetzt.';
  }
  showBackgroundState(state.hasBackground === true);

  backgroundFile.addEventListener('change', async () => {
    const file = backgroundFile.files[0];
    if (!file) return;

    backgroundState.textContent = 'Lade hoch …';

    // Klassischer Datei-Upload statt JSON - der Inhalt geht als FormData raus
    const form = new FormData();
    form.append('file', file);

    try {
      const res = await fetch(OC.generateUrl('/apps/' + APP_ID + '/settings/background'), {
        method: 'POST',
        headers: { requesttoken: OC.requestToken },
        body: form,
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        backgroundState.textContent = data.error || 'Hochladen fehlgeschlagen.';
        return;
      }

      showBackgroundState(true);
      backgroundState.textContent = 'Hintergrundbild gespeichert.';
    } catch (err) {
      backgroundState.textContent = 'Verbindung fehlgeschlagen.';
    } finally {
      backgroundFile.value = '';
    }
  });

  backgroundRemove.addEventListener('click', async () => {
    try {
      await fetch(OC.generateUrl('/apps/' + APP_ID + '/settings/background/remove'), {
        method: 'POST',
        headers: { requesttoken: OC.requestToken },
      });
      showBackgroundState(false);
      backgroundState.textContent = 'Hintergrundbild entfernt.';
    } catch (err) {
      backgroundState.textContent = 'Entfernen fehlgeschlagen.';
    }
  });

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
      publicSlug: publicSlug.value,
      headerTitle: title.value,
      headerSubtitle: subtitle.value,
      themeAccent: accent.value,
      themeBar: bar.value,
      themeBase: base.value,
      design: designNextcloud.checked ? 'nextcloud' : 'custom',
      backgroundNextcloud: backgroundNextcloud.checked,
      userCustomization: userCustomization.checked,
      userShares: userShares.checked,
      featureOffline: featureOffline.checked,
      featureDownload: featureDownload.checked,
      betaEnabled: betaEnabled.checked,
      betaText: betaText.value,
      betaLinkUrl: betaLinkUrl.value,
      betaLinkLabel: betaLinkLabel.value,
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
      publicSlug.value = data.publicSlug || '';
      setStatus('Gespeichert.', false);
    } catch (err) {
      setStatus('Verbindung fehlgeschlagen.', true);
    }
  });

  // ---------- Freigaben durch Nutzer ----------
  const sharesTable = el('aa-shares');
  const sharesBody = sharesTable.querySelector('tbody');
  const sharesState = el('aa-shares-state');

  async function loadShares() {
    try {
      const res = await fetch(OC.generateUrl('/apps/' + APP_ID + '/api/admin/shares'), {
        headers: { requesttoken: OC.requestToken },
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || '');
      renderShares(data.shares || []);
    } catch (err) {
      sharesState.textContent = 'Freigaben konnten nicht geladen werden.';
    }
  }

  function renderShares(shares) {
    sharesBody.textContent = '';
    sharesTable.hidden = shares.length === 0;
    sharesState.textContent = shares.length === 0 ? 'Es gibt noch keine Freigaben.' : '';

    shares.forEach((share) => {
      const row = document.createElement('tr');

      const folderCell = document.createElement('td');
      folderCell.className = 'aa-share-folder';
      // Interne Freigaben haben keinen Link
      const link = document.createElement(share.url ? 'a' : 'span');
      if (share.url) {
        link.href = share.url;
        link.target = '_blank';
        link.rel = 'noopener';
      }
      link.textContent = share.settings.title || share.folderName || share.path || '–';
      folderCell.appendChild(link);
      const note = document.createElement('span');
      note.className = 'aa-share-note';
      note.textContent = (share.source === 'home' ? 'Eigene Dateien: ' : 'Gemeinsamer Ordner: ')
        + (share.path || '/')
        + (share.missing ? ' – Ordner nicht mehr vorhanden' : '')
        + (share.expired ? ' – abgelaufen' : '');
      folderCell.appendChild(note);

      const creatorCell = document.createElement('td');
      creatorCell.textContent = share.creator;
      const passwordCell = document.createElement('td');
      if (share.kind === 'internal') {
        passwordCell.textContent = 'Personen: ' + ((share.members || [])
          .map((m) => (m.type === 'group' ? 'Gruppe ' : '') + m.label).join(', ') || '–');
      } else {
        passwordCell.textContent = 'Link' + (share.slug ? ' „' + share.slug + '"' : '')
          + (share.hasPassword ? ', mit Passwort' : ', ohne Passwort');
      }
      const expiresCell = document.createElement('td');
      expiresCell.textContent = share.expires || 'unbegrenzt';

      const actionCell = document.createElement('td');
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = 'Löschen';
      remove.addEventListener('click', async () => {
        const consequence = share.kind === 'internal'
          ? 'Die Personen sehen den Ordner danach nicht mehr.'
          : 'Der Link funktioniert danach nicht mehr.';
        if (!window.confirm('Freigabe „' + link.textContent + '" löschen? ' + consequence)) return;
        remove.disabled = true;
        try {
          const res = await fetch(OC.generateUrl('/apps/' + APP_ID + '/api/shares/' + share.id + '/delete'), {
            method: 'POST',
            headers: { requesttoken: OC.requestToken },
          });
          if (!res.ok) throw new Error();
          loadShares();
        } catch (err) {
          remove.disabled = false;
          sharesState.textContent = 'Löschen fehlgeschlagen.';
        }
      });
      actionCell.appendChild(remove);

      row.append(folderCell, creatorCell, passwordCell, expiresCell, actionCell);
      sharesBody.appendChild(row);
    });
  }

  loadShares();

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
