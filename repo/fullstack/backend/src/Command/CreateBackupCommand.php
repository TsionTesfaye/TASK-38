<?php
declare(strict_types=1);
namespace App\Command;

use App\Repository\UserRepository;
use App\Service\BackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:create-backup', description: 'Create a system backup for all organisations')]
class CreateBackupCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $orgIds = $this->userRepository->findDistinctOrganizationIds();

        if (empty($orgIds)) {
            $output->writeln('No organisations found — skipping backup.');
            return Command::SUCCESS;
        }

        $success = true;
        foreach ($orgIds as $orgId) {
            try {
                $result = $this->backupService->createSystemBackup($orgId);
                $output->writeln(sprintf('Backup created for org %s: %s', $orgId, $result['filename']));
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Backup FAILED for org %s: %s</error>', $orgId, $e->getMessage()));
                $success = false;
            }
        }

        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
