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

  const noticeClosable = el('aa-notice-closable'); // ab 1.0.5
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
  const status = el('aa-status');

  // ---------- Startwerte einsetzen ----------
  noticeClosable.checked = state.noticeClosable !== false;
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
      noticeClosable: noticeClosable.checked,
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
          .map((m) => (m.type === 'all' ? '🌐 ' : (m.type === 'group' ? '👥 Gruppe ' : '👤 ')) + m.label).join(', ') || '–')
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
