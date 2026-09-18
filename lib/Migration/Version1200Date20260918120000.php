<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tabelle fuer die Freigaben, die Nutzer selbst anlegen (ab 0.12).
 *
 * Der Ordner wird ueber seine Datei-ID gespeichert, nicht ueber den Pfad:
 * So bleibt ein Link gueltig, auch wenn der Ordner umbenannt oder
 * verschoben wird. Der Pfad steht nur zur Anzeige dabei.
 */
class Version1200Date20260918120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('audioarchive_shares')) {
            return null;
        }

        $table = $schema->createTable('audioarchive_shares');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
        // Wer die Freigabe angelegt hat und sie verwalten darf
        $table->addColumn('creator', Types::STRING, ['notnull' => true, 'length' => 64]);
        // Ueber wessen Dateien der Ordner aufgeloest wird
        $table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'home']);
        $table->addColumn('path', Types::STRING, ['notnull' => false, 'length' => 4000]);
        $table->addColumn('password_hash', Types::STRING, ['notnull' => false, 'length' => 255]);
        // Ablauf als Unix-Zeitstempel; leer = unbegrenzt
        $table->addColumn('expires', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        // Aussehen, Funktionen, Beta-Hinweis als JSON
        $table->addColumn('settings', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['token'], 'audioarchive_shares_token');
        $table->addIndex(['creator'], 'audioarchive_shares_creator');

        return $schema;
    }
}
