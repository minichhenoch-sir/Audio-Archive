<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Henoch Minich
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioArchive\Command;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes all data of the app before uninstalling it: own tables, their
 * migration entries (so a later reinstall recreates them), app and user
 * settings, and app data (background images, cover thumbnails, icons).
 *
 * Deliberately a manual command: Nextcloud runs "uninstall" repair steps
 * whenever an app is disabled - also automatically after a server upgrade -
 * which would silently delete shares and settings.
 *
 * Comments are regular Nextcloud file comments and stay with the files.
 */
class RemoveData extends Command {

    private const TABLES = ['audioarchive_share_members', 'audioarchive_shares', 'audioarchive_meta'];

    public function __construct(
        private IDBConnection $db,
        private IAppConfig $appConfig,
        private IConfig $config,
        private IAppDataFactory $appDataFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('audioarchive:remove-data')
            ->setDescription('Delete all Audio Archive data (shares, settings, cached data) before removing the app')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Really delete (without this option nothing is changed)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if (!$input->getOption('force')) {
            $output->writeln('This deletes all shares, settings and cached data of Audio Archive.');
            $output->writeln('Recordings and comments are not touched. Run again with --force to continue.');
            return 1;
        }

        foreach (self::TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->dropTable($table);
                $output->writeln('Dropped table ' . $table);
            }
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('migrations')
            ->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)))
            ->executeStatement();

        $this->config->deleteAppFromAllUsers(Application::APP_ID);

        // "enabled", "installed_version" and "types" are managed by Nextcloud itself
        foreach ($this->appConfig->getKeys(Application::APP_ID) as $key) {
            if (!in_array($key, ['enabled', 'installed_version', 'types'], true)) {
                $this->appConfig->deleteKey(Application::APP_ID, $key);
            }
        }

        try {
            $appData = $this->appDataFactory->get(Application::APP_ID);
            foreach ($appData->getDirectoryListing() as $folder) {
                $folder->delete();
            }
        } catch (\Throwable $e) {
            // no app data
        }

        $output->writeln('Audio Archive data removed. Now disable and remove the app:');
        $output->writeln('  occ app:disable audioarchive && occ app:remove audioarchive');
        return 0;
    }
}
