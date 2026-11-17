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
  const title = el('aa-title');
  const subtitle = el('aa-subtitle');
  const accent = el('aa-accent');
  const bar = el('aa-bar');
  const base = el('aa-base');
  const noticeEnabled = el('aa-notice-enabled'); // ab 0.31.0 (Vikunja #39)
  const noticeClosable = el('aa-notice-closable'); // ab 1.0.5
  // Ab 0.37.0 (Vikunja #49) mit Formatierungsleiste; .value liefert HTML
  const betaText = window.AARichText ? window.AARichText.attach(el('aa-beta-text')) : el('aa-beta-text');
  const betaLinkUrl = el('aa-beta-link-url');
  const betaLinkLabel = el('aa-beta-link-label');
  const customDesign = el('aa-custom-design');
  const adminStyleEnabled = el('aa-admin-style-enabled');
  const backgroundNextcloud = el('aa-background-nextcloud');
  const userCustomization = el('aa-user-customization');
  const userShares = el('aa-user-shares');
  const featureOffline = el('aa-feature-offline');
  const offlineOpen = el('aa-offline-open');
  const featureDownload = el('aa-feature-download');
  const sortDefault = el('aa-sort-default');
  const showFolderCount = el('aa-show-folder-count'); // ab 0.30.0 (Vikunja #42)
  // Anzeige in der Liste, Namen, Sternfarbe (ab 0.33.0, Vikunja #50, #44, #3)
  const showFolderDate = el('aa-show-folder-date');
  const showTrackDuration = el('aa-show-track-duration');
  const showTrackDate = el('aa-show-track-date');
  const prettyFolderNames = el('aa-pretty-folder-names');
  const titleFromTags = el('aa-title-from-tags');
  const starColor = el('aa-star-color');
  const searchScope = el('aa-search-scope');
  // Kommentare (ab 0.29.0)
  const featureComments = el('aa-feature-comments');
  const featureRating = el('aa-feature-rating');
  const commentGroup = el('aa-comment-group');
  // Hilfe und Kontakt (ab 0.37.0, Vikunja #27)
  const helpGroup = el('aa-help-group');
  const helpEmail = el('aa-help-email');
  const repeatDefault = el('aa-repeat-default');
  const featureFavorites = el('aa-feature-favorites');
  const featureFolderDownload = el('aa-feature-folder-download');
  const transcode = el('aa-transcode');
  const rememberDays = el('aa-remember-days');
  const sharedLabel = el('aa-shared-label');
  const status = el('aa-status');

  // ---------- Startwerte einsetzen ----------
  folder.value = state.sourceFolder || '';
  title.value = state.headerTitle || '';
  subtitle.value = state.headerSubtitle || '';
  accent.value = state.themeAccent || '#b9793f';
  bar.value = state.themeBar || '#291c12';
  base.value = state.themeBase || '#a86a3d';
  noticeEnabled.checked = state.noticeEnabled === true;
  noticeClosable.checked = state.noticeClosable !== false;
  betaText.value = state.betaText || '';
  betaLinkUrl.value = state.betaLinkUrl || '';
  betaLinkLabel.value = state.betaLinkLabel || '';
  /*
   * Gestaltung (ab 0.17): Vorgabe als Karten mit Vorschau. "Vom
   * Administrator" ist nur waehlbar, solange sie angeboten wird.
   */
  const AAStyle = window.AAStyle;
  let design = ['nextcloud', 'custom', 'admin'].includes(state.design) ? state.design : 'custom';
  adminStyleEnabled.checked = state.adminStyleEnabled === true;
  if (design === 'admin' && !adminStyleEnabled.checked) design = 'custom';

  const modernThumb = () => AAStyle.modernThumb({ accent: accent.value, bar: bar.value, base: base.value });
  let ncPrimary = '';
  try {
    ncPrimary = getComputedStyle(document.documentElement).getPropertyValue('--color-primary-element').trim();
  } catch (e) { /* egal - dann Nextclouds Standardblau */ }

  const adminEditor = AAStyle.createEditor({
    value: state.adminStyle || AAStyle.DEFAULTS,
    imageUrl: state.hasBackground ? OC.generateUrl('/apps/' + APP_ID + '/background') : '',
    onChange(style) { cards.setThumb('admin', style); },
  });
  el('aa-admin-style').appendChild(adminEditor.el);

  const cards = AAStyle.createDesignCards({
    name: 'aa-design',
    value: design,
    options: [
      { value: 'nextcloud', label: 'Klassisch', desc: 'Nextcloud-Design', style: AAStyle.classicThumb(ncPrimary) },
      { value: 'custom', label: 'Modern', desc: 'Rund, Glas, Farben unten', style: modernThumb() },
      { value: 'admin', label: 'Vom Administrator', desc: 'Frei eingestellt (unten)', style: adminEditor.get(), disabled: !adminStyleEnabled.checked },
    ],
    onChange(value) {
      design = value;
      updateDesignState();
    },
  });
  el('aa-design-cards').appendChild(cards.el);

  const palettes = AAStyle.createPalettePicker((p) => {
    accent.value = p.accent;
    bar.value = p.bar;
    base.value = p.base;
    cards.setThumb('custom', modernThumb());
  }, { accent: accent.value, bar: bar.value, base: base.value });
  el('aa-palettes').appendChild(palettes.el);
  [accent, bar, base].forEach((input) => input.addEventListener('input', () => {
    palettes.mark({ accent: accent.value, bar: bar.value, base: base.value });
    cards.setThumb('custom', modernThumb());
  }));

  adminStyleEnabled.addEventListener('change', () => {
    cards.setDisabled('admin', !adminStyleEnabled.checked);
    if (!adminStyleEnabled.checked && design === 'admin') {
      design = 'custom';
      cards.set(design);
    }
    updateDesignState();
  });

  /*
   * Farben fuer Modern wirken nur bei dieser Vorgabe (Nutzer koennen
   * Modern aber auch selbst waehlen). Sie bleiben deshalb bedienbar -
   * nur abgeblendet.
   */
  function updateDesignState() {
    customDesign.classList.toggle('aa-inactive', design !== 'custom');
  }
  updateDesignState();

  backgroundNextcloud.checked = state.backgroundNextcloud === true;
  userCustomization.checked = state.userCustomization !== false;
  userShares.checked = state.userShares !== false;
  featureOffline.checked = state.featureOffline !== false;
  offlineOpen.checked = state.offlineOpen !== false;
  featureDownload.checked = state.featureDownload === true;
  sortDefault.value = ['newest', 'random'].includes(state.sortDefault) ? state.sortDefault : 'name';
  showFolderCount.checked = state.showFolderCount === true;
  showFolderDate.checked = state.showFolderDate !== false;
  showTrackDuration.checked = state.showTrackDuration !== false;
  showTrackDate.checked = state.showTrackDate !== false;
  prettyFolderNames.checked = state.prettyFolderNames === true;
  titleFromTags.checked = state.titleFromTags !== false;
  starColor.value = ['accent', 'yellow', 'text'].includes(state.starColor) ? state.starColor : 'accent';
  searchScope.value = state.searchScope === 'all' ? 'all' : 'folder';
  featureComments.checked = state.featureComments === true;
  featureRating.checked = state.featureRating !== false;
  [{ id: '', name: '– niemanden –' }].concat(state.groups || []).forEach((g) => {
    const opt = document.createElement('option');
    opt.value = g.id;
    opt.textContent = g.name || g.id;
    commentGroup.appendChild(opt);
  });
  commentGroup.value = state.commentNotifyGroup || '';
  [{ id: '', name: '– niemanden (kein Textfenster) –' }].concat(state.groups || []).forEach((g) => {
    const opt = document.createElement('option');
    opt.value = g.id;
    opt.textContent = g.name || g.id;
    helpGroup.appendChild(opt);
  });
  helpGroup.value = state.helpGroup || '';
  helpEmail.value = state.helpEmail || '';
  const syncCommentOptions = () => {
    el('aa-comments-options').classList.toggle('aa-inactive', !featureComments.checked);
  };
  featureComments.addEventListener('change', syncCommentOptions);
  syncCommentOptions();
  repeatDefault.value = ['off', 'next', 'folder', 'one'].includes(state.repeatDefault) ? state.repeatDefault : 'next';
  featureFavorites.checked = state.featureFavorites !== false;
  featureFolderDownload.checked = state.featureFolderDownload === true;
  // Umwandlung in MP3 (ab 0.27.0): ohne ffmpeg nicht waehlbar
  transcode.checked = state.transcode === true && !!state.ffmpegPath;
  transcode.disabled = !state.ffmpegPath;
  el('aa-transcode-state').textContent = state.ffmpegPath
    ? 'ffmpeg ist vorhanden (' + state.ffmpegPath + ').'
    : 'ffmpeg ist auf diesem Server nicht installiert – die Umwandlung ist deshalb nicht möglich.';
  rememberDays.value = String([0, 7, 15, 30, 90].includes(state.rememberDays) ? state.rememberDays : 0);
  sharedLabel.value = state.sharedLabel || '';

  if (state.sourceFolderOwner) {
    folderOwner.textContent =
      'Die Aufnahmen werden aus den Dateien von „' + state.sourceFolderOwner
      + '“ gelesen – auch für Links auf diesen Ordner.';
  }


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

    backgroundState.textContent = 'Wird hochgeladen …';

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
    setStatus('Die Dateiauswahl ist nicht verfügbar – bitte den Pfad von Hand eintragen.', true);
  });

  // ---------- Weitere Quellen (ab 0.32.0, Vikunja #8) ----------
  const extraBox = el('aa-extra-sources');

  function pickFolder(title, done) {
    if (window.OC && OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
      OC.dialogs.filepicker(title, done, false, 'httpd/unix-directory', true, OC.dialogs.FILEPICKER_TYPE_CHOOSE);
      return true;
    }
    return false;
  }

  function addExtraRow(src) {
    const row = document.createElement('div');
    row.className = 'aa-extra-row';
    row.dataset.id = src.id ? String(src.id) : '';

    const name = document.createElement('input');
    name.type = 'text';
    name.maxLength = 60;
    name.placeholder = 'Name, z. B. Hörbücher';
    name.value = src.name || '';
    name.className = 'aa-extra-name';

    const path = document.createElement('input');
    path.type = 'text';
    path.readOnly = true;
    path.placeholder = '/Ordner';
    path.value = src.path || '';
    path.className = 'aa-extra-path';

    const pick = document.createElement('button');
    pick.type = 'button';
    pick.textContent = 'Auswählen …';
    pick.addEventListener('click', () => {
      if (!pickFolder('Ordner für „' + (name.value || 'weitere Quelle') + '“ wählen', (p) => { path.value = p; })) {
        path.readOnly = false;
        path.focus();
      }
    });

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.textContent = 'Entfernen';
    remove.addEventListener('click', () => {
      row.remove();
      setStatus('Quelle entfernt – zum Übernehmen „Speichern“ drücken.', false);
    });

    row.append(name, path, pick);
    if (src.id) {
      const open = document.createElement('a');
      open.className = 'button';
      open.textContent = 'In der App öffnen';
      open.href = OC.generateUrl('/apps/' + APP_ID + '/') + '#source=src:' + src.id;
      open.target = '_blank';
      open.rel = 'noopener';
      row.appendChild(open);
    }
    row.appendChild(remove);
    if (src.id && src.found === false) {
      const warn = document.createElement('p');
      warn.className = 'settings-hint aa-status--error';
      warn.textContent = 'Ordner nicht gefunden – die Quelle erscheint nicht in der App.';
      row.appendChild(warn);
    }
    extraBox.appendChild(row);
    return row;
  }

  function renderExtraSources(list) {
    extraBox.innerHTML = '';
    (list || []).forEach(addExtraRow);
  }
  renderExtraSources(state.extraSources);
  el('aa-extra-add').addEventListener('click', () => {
    addExtraRow({}).querySelector('.aa-extra-name').focus();
  });

  function collectExtraSources() {
    return Array.from(extraBox.querySelectorAll('.aa-extra-row')).map((row) => ({
      id: parseInt(row.dataset.id || '0', 10) || 0,
      name: row.querySelector('.aa-extra-name').value,
      path: row.querySelector('.aa-extra-path').value,
    }));
  }

  // ---------- Wer darf teilen (ab 0.32.0) ----------
  const shareGroupsBox = el('aa-share-groups');
  const chosenGroups = new Set(state.shareGroups || []);
  (state.groups || []).forEach((g) => {
    const label = document.createElement('label');
    label.className = 'aa-share-group';
    const box = document.createElement('input');
    box.type = 'checkbox';
    box.value = g.id;
    box.checked = chosenGroups.has(g.id);
    label.append(box, document.createTextNode(' ' + (g.name || g.id)));
    shareGroupsBox.appendChild(label);
  });
  if (!(state.groups || []).length) {
    shareGroupsBox.textContent = 'Es gibt noch keine Nextcloud-Gruppen.';
  }

  // ---------- Speichern ----------
  function setStatus(message, isError) {
    status.textContent = message;
    status.classList.toggle('aa-status--error', !!isError);
  }

  el('aa-save').addEventListener('click', async () => {
    setStatus('Wird gespeichert …', false);

    const payload = {
      sourceFolder: folder.value,
      headerTitle: title.value,
      headerSubtitle: subtitle.value,
      themeAccent: accent.value,
      themeBar: bar.value,
      themeBase: base.value,
      design,
      adminStyle: adminEditor.get(),
      adminStyleEnabled: adminStyleEnabled.checked,
      backgroundNextcloud: backgroundNextcloud.checked,
      userCustomization: userCustomization.checked,
      userShares: userShares.checked,
      featureOffline: featureOffline.checked,
      offlineOpen: offlineOpen.checked,
      featureDownload: featureDownload.checked,
      sortDefault: sortDefault.value,
      showFolderCount: showFolderCount.checked,
      showFolderDate: showFolderDate.checked,
      showTrackDuration: showTrackDuration.checked,
      showTrackDate: showTrackDate.checked,
      prettyFolderNames: prettyFolderNames.checked,
      titleFromTags: titleFromTags.checked,
      starColor: starColor.value,
      searchScope: searchScope.value,
      featureComments: featureComments.checked,
      featureRating: featureRating.checked,
      commentNotifyGroup: commentGroup.value,
      helpGroup: helpGroup.value,
      helpEmail: helpEmail.value,
      repeatDefault: repeatDefault.value,
      featureFavorites: featureFavorites.checked,
      featureFolderDownload: featureFolderDownload.checked,
      transcode: transcode.checked,
      rememberDays: parseInt(rememberDays.value, 10) || 0,
      sharedLabel: sharedLabel.value,
      noticeEnabled: noticeEnabled.checked,
      noticeClosable: noticeClosable.checked,
      betaText: betaText.value,
      betaLinkUrl: betaLinkUrl.value,
      betaLinkLabel: betaLinkLabel.value,
      extraSources: collectExtraSources(),
      shareGroups: Array.from(shareGroupsBox.querySelectorAll('input:checked')).map((b) => b.value),
    };

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

      if (Array.isArray(data.extraSources)) {
        renderExtraSources(data.extraSources.map((s) => ({ ...s, found: true })));
      }
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
      note.textContent = (share.sourceName ? share.sourceName + ': ' : (share.source === 'home' ? 'Eigene Dateien: ' : 'Gemeinsamer Ordner: '))
        + (share.path || '/')
        + (share.via ? ' – weitergeteilt aus einer Freigabe von ' + (share.via.creatorName || share.via.creator) : '')
        + (share.missing ? ' – Ordner nicht mehr vorhanden' : '')
        + (share.expired ? ' – abgelaufen' : '');
      folderCell.appendChild(note);

      const creatorCell = document.createElement('td');
      creatorCell.textContent = share.creatorName || share.creator;
      const passwordCell = document.createElement('td');
      if (share.kind === 'internal') {
        // Person oder Gruppe erkennbar (ab 0.26.0, Vikunja #8)
        passwordCell.textContent = ((share.members || [])
          .map((m) => (m.type === 'group' ? '👥 Gruppe ' : '👤 ') + m.label).join(', ') || '–')
          + (share.settings.allowReshare ? ' · dürfen weiterteilen' : '');
      } else {
        passwordCell.textContent = '🔗 Link' + (share.slug ? ' „' + share.slug + '“' : '')
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
        if (!window.confirm('Freigabe „' + link.textContent + '“ löschen? ' + consequence)) return;
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
})();
