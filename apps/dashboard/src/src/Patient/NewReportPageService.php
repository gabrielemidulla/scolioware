<?php

declare(strict_types=1);

namespace App\Patient;

use Doctrine\DBAL\Connection;

final class NewReportPageService
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPatientsForSelect(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, first_name, last_name, tax_code FROM patients ORDER BY last_name, first_name'
        );

        return $rows;
    }
}
