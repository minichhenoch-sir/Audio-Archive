<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Migration;

use Closure;
use OCA\AudioArchive\Service\AdminLinkMigration;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Frueheren Administrator-Link als Freigabe uebernehmen (ab 1.2.0,
 * Vikunja #68) - siehe AdminLinkMigration. Keine Aenderung am
 * Datenbankaufbau.
 */
class Version10200Date20261117200000 extends SimpleMigrationStep {

    public function __construct(
        private AdminLinkMigration $migration,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        try {
            $output->info('Audio Archive: ' . $this->migration->run());
        } catch (\Throwable $e) {
            // Ein Fehler hier darf das Update nicht abbrechen; der Link
            // bleibt dann vorerst in der Konfiguration stehen
            $output->warning('Audio Archive: Administrator-Link nicht übernommen – ' . $e->getMessage());
        }
    }
}
