<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Freigaben an Nextcloud-Nutzer und -Gruppen (ab 0.13).
 *
 * - Spalte 'kind' in audioarchive_shares: 'link' (bisherige oeffentliche
 *   Links, Vorgabe fuer alle bestehenden Zeilen) oder 'internal' (nur fuer
 *   ausgewaehlte Nutzer/Gruppen, nur innerhalb der App sichtbar).
 * - Tabelle audioarchive_share_members: Empfaenger einer internen
 *   Freigabe, je Zeile ein Nutzer oder eine Gruppe. Eigene Tabelle statt
 *   JSON-Spalte, damit sich "was ist mit mir geteilt" per Abfrage finden
 *   laesst, ohne alle Freigaben zu durchlaufen.
 */
class Version1300Date20260918150000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('audioarchive_shares')) {
            $table = $schema->getTable('audioarchive_shares');
            if (!$table->hasColumn('kind')) {
                $table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'link']);
                $changed = true;
            }
        }

        if (!$schema->hasTable('audioarchive_share_members')) {
            $table = $schema->createTable('audioarchive_share_members');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('share_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            // 'user' oder 'group'
            $table->addColumn('member_type', Types::STRING, ['notnull' => true, 'length' => 8]);
            // Nutzerkennung bzw. Gruppenkennung
            $table->addColumn('member', Types::STRING, ['notnull' => true, 'length' => 64]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['share_id'], 'audioarchive_members_share');
            $table->addIndex(['member_type', 'member'], 'audioarchive_members_who');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
