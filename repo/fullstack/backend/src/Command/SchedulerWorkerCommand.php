<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SchedulerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running scheduler worker. All scheduling logic lives in SchedulerService;
 * this command only handles the loop, --once flag, and console output.
 */
#[AsCommand(
    name: 'app:scheduler:run',
    description: 'Run the continuous scheduler worker',
)]
class SchedulerWorkerCommand extends Command
{
    private const TICK_INTERVAL = 30;

    public function __construct(
        private readonly SchedulerService $schedulerService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run one full cycle and exit (test/dev mode)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $once = (bool) $input->getOption('once');
        $schedule = $this->schedulerService->getSchedule();

        $output->writeln(sprintf(
            '<info>[scheduler]</info> Starting%s — tick every %ds, %d tasks registered',
            $once ? ' (once mode)' : '',
            self::TICK_INTERVAL,
            count($schedule),
        ));

        $this->schedulerService->initLastRun();

        if ($once) {
            $results = $this->schedulerService->runCycle(force: true);
            $this->writeResults($output, $results);
            $output->writeln('<info>[scheduler]</info> Single cycle complete — exiting.');
            return Command::SUCCESS;
        }

        while (true) {
            $results = $this->schedulerService->runCycle();
            $this->writeResults($output, $results);
            sleep(self::TICK_INTERVAL);
        }

        // @codeCoverageIgnoreStart
        return Command::SUCCESS;
        // @codeCoverageIgnoreEnd
    }

    /** @param array<string, array{status: string, result: string, duration_ms: float}> $results */
    private function writeResults(OutputInterface $output, array $results): void
    {
        foreach ($results as $name => $r) {
            if ($r['status'] === 'ok') {
                $output->writeln(sprintf(
                    '<info>[scheduler]</info> %s — OK (result: %s, %.1fms)',
                    $name, $r['result'], $r['duration_ms'],
                ));
            } else {
                $output->writeln(sprintf(
                    '<error>[scheduler]</error> %s — FAILED: %s (%.1fms)',
                    $name, $r['result'], $r['duration_ms'],
                ));
            }
        }
    }
}
