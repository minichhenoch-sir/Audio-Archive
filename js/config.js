/**
 * Gemeinsame Grundlage fuer player.js und app.js.
 *
 * Buendelt die Werte, die der Server ins Dokument geschrieben hat, und die
 * Bildung der Endpunkt-Adressen an einer Stelle. Damit muessen die uebrigen
 * Dateien nichts darueber wissen, unter welchem Pfad die App laeuft - das
 * ist in Nextcloud anders als in der eigenstaendigen Fassung, wo alles
 * relativ zum Dokument lag.
 */
const AudioArchive = (() => {
  'use strict';

  const el = document.getElementById('app-config');

  const data = {
    publicToken: el.dataset.publicToken || '',
    headerTitle: el.dataset.headerTitle || '',
    headerSubtitle: el.dataset.headerSubtitle || '',
    serviceWorker: el.dataset.serviceWorker || '',
    scope: el.dataset.scope || '',
    assetBase: el.dataset.assetBase || '',
    backgroundUrl: el.dataset.background || '',
    betaEnabled: el.dataset.beta === '1',
    betaText: el.dataset.betaText || '',
    betaLinkUrl: el.dataset.betaLinkUrl || '',
    betaLinkLabel: el.dataset.betaLinkLabel || '',
    themeAccent: el.dataset.themeAccent || '#b9793f',
    themeBar: el.dataset.themeBar || '#291c12',
    themeBase: el.dataset.themeBase || '#a86a3d',
  };

  return {
    ...data,

    /** Adresse eines Endpunkts, z.B. api('list') oder api('public/login'). */
    api(name) {
      return data.scope + 'api/' + name;
    },

    /**
     * Vollstaendige Adresse einer mitgelieferten Datei (Bilder usw.).
     *
     * Bewusst absolut aufgeloest: Relative Angaben wuerden auf der
     * oeffentlichen Seite gegen /apps/audioarchive/s/<token>/ aufgeloest
     * und ins Leere zeigen.
     */
    asset(file) {
      return new URL(data.assetBase + file, location.href).href;
    },

    /** Ist der Aufruf ueber die oeffentliche Seite erfolgt? */
    isPublic() {
      return data.publicToken !== '';
    },
  };
})();
