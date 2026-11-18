<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Migration;

use Closure;
use OCA\AudioArchive\Service\AdminSettingsMigration;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Quellen, Darstellung und Text der Verwaltung in Freigaben uebernehmen
 * (ab 1.3.0) - siehe AdminSettingsMigration. Keine Aenderung am
 * Datenbankaufbau.
 */
class Version10300Date20261118100000 extends SimpleMigrationStep {

    public function __construct(
        private AdminSettingsMigration $migration,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        try {
            foreach ($this->migration->run() as $line) {
                $output->info('Audio Archive: ' . $line);
            }
        } catch (\Throwable $e) {
            // Ein Fehler darf das Update nicht abbrechen; die alten
            // Einstellungen bleiben dann vorerst stehen
            $output->warning('Audio Archive: Einstellungen der Verwaltung nicht übernommen – ' . $e->getMessage());
        }
    }
}
