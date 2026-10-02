/**
 * Einstellungen -> Persoenlich -> Audio Archive (ab 0.26.0, Vikunja #8):
 * eigene Freigaben anzeigen, Link kopieren, loeschen, in der App bearbeiten.
 */
(function () {
  'use strict';

  const APP_ID = 'audioarchive';
  const el = (id) => document.getElementById(id);

  function initialState() {
    try {
      return OCP.InitialState.loadState(APP_ID, 'personal');
    } catch (err) {
      return { sharingAllowed: true, appUrl: OC.generateUrl('/apps/' + APP_ID + '/') };
    }
  }

  function start() {
    const state = initialState();
    const table = el('aa-shares');
    const body = table.querySelector('tbody');
    const status = el('aa-shares-state');
    const newBtn = el('aa-personal-new');

    el('aa-personal-disabled').hidden = state.sharingAllowed !== false;
    newBtn.hidden = state.sharingAllowed === false;
    newBtn.href = state.appUrl;
    newBtn.addEventListener('click', () => { el('aa-personal-new-hint').hidden = false; });

    function members(list) {
      const out = (list || []).map((m) => (m.type === 'group' ? '👥 Gruppe ' : '👤 ') + m.label);
      return out.length ? out.join(', ') : 'niemand';
    }

    function formatDate(iso) {
      return iso ? iso.split('-').reverse().join('.') : 'unbegrenzt';
    }

    function btn(label, onClick, href) {
      const b = document.createElement(href ? 'a' : 'button');
      if (href) {
        b.href = href;
        b.className = 'button';
      } else {
        b.type = 'button';
        b.addEventListener('click', onClick);
      }
      b.textContent = label;
      return b;
    }

    async function load() {
      try {
        const res = await fetch(OC.generateUrl('/apps/' + APP_ID + '/api/shares'), {
          headers: { requesttoken: OC.requestToken },
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || '');
        render(data.shares || []);
      } catch (err) {
        status.textContent = 'Freigaben konnten nicht geladen werden.';
      }
    }

    function render(shares) {
      body.textContent = '';
      table.hidden = shares.length === 0;
      status.textContent = shares.length === 0 ? 'Du hast noch nichts geteilt.' : '';

      shares.forEach((share) => {
        const row = document.createElement('tr');
        const name = share.settings.title || share.folderName || share.path || '–';

        const folderCell = document.createElement('td');
        folderCell.className = 'aa-share-folder';
        const title = document.createElement('strong');
        title.textContent = name;
        folderCell.appendChild(title);
        const note = document.createElement('span');
        note.className = 'aa-share-note';
        note.textContent = (share.via
          ? 'Weitergeteilt aus einer Freigabe von ' + (share.via.creatorName || share.via.creator) + ': ' + (share.via.path || 'ganzer Ordner')
          : (share.sourceName ? share.sourceName + ': ' : (share.source === 'home' ? 'Eigene Dateien: ' : 'Gemeinsame Aufnahmen: ')) + (share.path || '/'))
          + (share.missing ? ' – gilt nicht mehr (Ordner fehlt oder nicht mehr geteilt)' : '')
          + (share.expired ? ' – abgelaufen' : '');
        folderCell.appendChild(note);

        const whoCell = document.createElement('td');
        if (share.kind === 'internal') {
          whoCell.textContent = members(share.members)
            + (share.settings.allowReshare ? ' · dürfen weiterteilen' : '');
        } else {
          whoCell.textContent = '🔗 Link ' + (share.hasPassword ? 'mit Passwort' : 'ohne Passwort');
        }

        const expiresCell = document.createElement('td');
        expiresCell.textContent = formatDate(share.expires);

        const actionCell = document.createElement('td');
        actionCell.className = 'aa-share-actions';
        if (!share.missing) {
          actionCell.appendChild(btn('In der App bearbeiten', null, state.appUrl + '#share=' + share.id));
        }
        if (share.url) {
          actionCell.appendChild(btn('Link kopieren', async () => {
            try {
              await navigator.clipboard.writeText(share.url);
              status.textContent = 'Link kopiert.';
            } catch (err) {
              window.prompt('Link:', share.url);
            }
          }));
        }
        const remove = btn('Löschen', async () => {
          const consequence = share.kind === 'internal'
            ? 'Die Personen sehen den Ordner danach nicht mehr.'
            : 'Der Link funktioniert danach nicht mehr.';
          if (!window.confirm('Freigabe „' + name + '“ löschen? ' + consequence)) return;
          remove.disabled = true;
          try {
            const res = await fetch(OC.generateUrl('/apps/' + APP_ID + '/api/shares/' + share.id + '/delete'), {
              method: 'POST',
              headers: { requesttoken: OC.requestToken },
            });
            if (!res.ok) throw new Error();
            load();
          } catch (err) {
            remove.disabled = false;
            status.textContent = 'Löschen fehlgeschlagen.';
          }
        });
        actionCell.appendChild(remove);

        row.append(folderCell, whoCell, expiresCell, actionCell);
        body.appendChild(row);
      });
    }

    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
