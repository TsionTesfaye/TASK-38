<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

class HealthService
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function checkHealth(): array
    {
        $databaseStatus = 'ok';

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $databaseStatus = 'fail';
        }

        $overallStatus = $databaseStatus === 'ok' ? 'ok' : 'degraded';

        return [
            'status' => $overallStatus,
            'checks' => [
                'database' => $databaseStatus,
            ],
        ];
    }
}
