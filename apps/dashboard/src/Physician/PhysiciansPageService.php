<?php

declare(strict_types=1);

namespace App\Physician;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final class PhysiciansPageService
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPhysicians(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, username, display_name, is_admin, created_at, deleted_at
             FROM physicians
             ORDER BY deleted_at IS NOT NULL ASC, is_admin DESC, username ASC'
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $admin Current admin row (needs id)
     *
     * @return array{
     *     flash: string,
     *     flash_type: string,
     *     generated_reset: ?array{username: string, url: string, ttl_hours: int}
     * }
     */
    public function handlePost(Request $request, array $admin): array
    {
        $out = [
            'flash' => '',
            'flash_type' => 'info',
            'generated_reset' => null,
        ];

        $action = (string) $request->request->get('action', '');
        $adminId = (int) ($admin['id'] ?? 0);

        try {
            if ($action === 'create') {
                $username = strtolower(trim((string) $request->request->get('username', '')));
                $display = trim((string) $request->request->get('display_name', ''));
                $password = (string) $request->request->get('password', '');
                $isAdmin = $request->request->has('is_admin') ? 1 : 0;

                if (!preg_match('/^[a-z0-9_.-]{3,64}$/', $username)) {
                    throw new \RuntimeException((string) __('physicians.err_user'));
                }
                $pwErr = sw_validate_password($password);
                if ($pwErr !== null) {
                    throw new \RuntimeException($pwErr);
                }

                $n = (int) $this->conn->fetchOne(
                    'SELECT COUNT(*) FROM physicians WHERE username = ? AND deleted_at IS NULL',
                    [$username]
                );
                if ($n > 0) {
                    throw new \RuntimeException((string) __('physicians.err_in_use'));
                }

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $this->conn->insert('physicians', [
                    'username' => $username,
                    'password_hash' => $hash,
                    'is_admin' => $isAdmin,
                    'display_name' => $display !== '' ? $display : null,
                ]);

                $out['flash'] = (string) __('physicians.ok_created', ['name' => $username]);
                $out['flash_type'] = 'success';
            } elseif ($action === 'delete') {
                $id = (int) $request->request->get('id', 0);
                if ($id <= 0) {
                    throw new \RuntimeException((string) __('physicians.err_id'));
                }
                if ($id === $adminId) {
                    throw new \RuntimeException((string) __('physicians.err_self_del'));
                }
                $target = $this->fetchPhysicianRow($id);
                if ($target === null || $target['deleted_at'] !== null) {
                    throw new \RuntimeException((string) __('physicians.err_not_found'));
                }
                if ((int) $target['is_admin'] === 1) {
                    $remaining = (int) $this->conn->fetchOne(
                        'SELECT COUNT(*) FROM physicians WHERE deleted_at IS NULL AND is_admin = 1'
                    );
                    if ($remaining <= 1) {
                        throw new \RuntimeException((string) __('physicians.err_last_admin'));
                    }
                }
                $this->conn->executeStatement(
                    'UPDATE physicians SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?',
                    [$id]
                );
                $out['flash'] = (string) __('physicians.ok_deleted', ['name' => (string) $target['username']]);
                $out['flash_type'] = 'success';
            } elseif ($action === 'restore') {
                $id = (int) $request->request->get('id', 0);
                $target = $this->fetchPhysicianRow($id);
                if ($target === null) {
                    throw new \RuntimeException((string) __('physicians.err_not_found'));
                }
                if ($target['deleted_at'] === null) {
                    throw new \RuntimeException((string) __('physicians.err_not_deleted'));
                }
                $clashId = $this->conn->fetchOne(
                    'SELECT id FROM physicians WHERE username = ? AND deleted_at IS NULL LIMIT 1',
                    [(string) $target['username']]
                );
                if ($clashId !== false && $clashId !== null) {
                    throw new \RuntimeException(
                        (string) __('physicians.err_restore', ['name' => (string) $target['username']])
                    );
                }
                $this->conn->update('physicians', ['deleted_at' => null], ['id' => $id]);
                $out['flash'] = (string) __('physicians.ok_restored', ['name' => (string) $target['username']]);
                $out['flash_type'] = 'success';
            } elseif ($action === 'reset_link') {
                $id = (int) $request->request->get('id', 0);
                if ($id === $adminId) {
                    throw new \RuntimeException((string) __('physicians.err_reset_self'));
                }
                $target = $this->fetchPhysicianRow($id);
                if ($target === null || $target['deleted_at'] !== null) {
                    throw new \RuntimeException((string) __('physicians.err_not_found'));
                }
                $token = sw_create_reset_token((int) $target['id'], $adminId);
                $out['generated_reset'] = [
                    'username' => (string) $target['username'],
                    'url' => sw_reset_link($token),
                    'ttl_hours' => SW_TOKEN_TTL_HOURS,
                ];
                $out['flash'] = (string) __('physicians.ok_reset', ['name' => (string) $target['username']]);
                $out['flash_type'] = 'success';
            } else {
                throw new \RuntimeException((string) __('physicians.err_unknown'));
            }
        } catch (Throwable $e) {
            $out['flash'] = $e->getMessage();
            $out['flash_type'] = 'danger';
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function fetchPhysicianRow(int $id): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->conn->fetchAssociative('SELECT * FROM physicians WHERE id = ? LIMIT 1', [$id]);

        return $row !== false ? $row : null;
    }
}
