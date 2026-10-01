<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Dauerhafter Speicher fuer gelesene Angaben der Aufnahmen (ab 0.25.1).
 *
 * Bisher lagen Dauer, Titel, Kuenstler und Album nur im Zwischenspeicher
 * von Nextcloud (Redis/APCu). Der ist nach einem Neustart leer - oder gar
 * nicht eingerichtet -, dann musste die Suche jede Datei erneut oeffnen und
 * fand innerhalb ihres Zeitbudgets nicht alles (Vikunja #32).
 *
 * Neue, eigene Tabelle; bestehende Daten werden nicht veraendert. Je Datei
 * eine Zeile (Dateikennung), gueltig solange die Aenderungszeit stimmt.
 */
class Version2501Date20261001150000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('audioarchive_meta')) {
            return null;
        }
        $table = $schema->createTable('audioarchive_meta');
        $table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('mtime', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        // Angaben als JSON (Dauer, Titel, Kuenstler, Album, Cover ja/nein)
        $table->addColumn('data', Types::TEXT, ['notnull' => false]);
        // Kurzer eigener Name, siehe Version1300 (Grenze bis Nextcloud 32)
        $table->setPrimaryKey(['file_id'], 'audioarchive_meta_pk');
        return $schema;
    }
}
