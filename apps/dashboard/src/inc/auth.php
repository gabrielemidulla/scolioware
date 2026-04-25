<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SV_TOKEN_TTL_HOURS = 24;
const SV_MIN_PASSWORD_LEN = 6;
const SV_DEFAULT_ADMIN_USER = 'admin';
const SV_DEFAULT_ADMIN_PASSWORD = 'admin';

function sv_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SV_SESSION');
    session_start();
}

function sv_csrf_token(): string
{
    sv_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sv_csrf_check(): void
{
    sv_session_start();
    $tok = $_POST['csrf_token'] ?? '';
    if (!is_string($tok) || $tok === '' || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $tok)) {
        if (!function_exists('__')) {
            require_once __DIR__ . '/Locale.php';
            Locale::init();
        }
        http_response_code(403);
        echo htmlspecialchars(__('error.csrf'), ENT_QUOTES, 'UTF-8')
            . ' <a href="' . htmlspecialchars(sv_current_url()) . '">'
            . htmlspecialchars(__('error.csrf_reload'), ENT_QUOTES, 'UTF-8')
            . '</a>.';
        exit;
    }
}

function sv_current_url(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
}

function sv_ensure_admin(): void
{
    $pdo = db();
    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM physicians WHERE deleted_at IS NULL AND is_admin = 1'
    )->fetchColumn();
    if ($count > 0) {
        return;
    }
    $hash = password_hash(SV_DEFAULT_ADMIN_PASSWORD, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        "INSERT INTO physicians (username, password_hash, is_admin, display_name)
         VALUES (?, ?, 1, 'Administrator')"
    );
    $stmt->execute([SV_DEFAULT_ADMIN_USER, $hash]);
}

function sv_find_physician_by_username(string $username): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM physicians WHERE username = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sv_find_physician(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM physicians WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sv_login_user(array $physician): void
{
    sv_session_start();
    session_regenerate_id(true);
    $_SESSION['physician_id'] = (int) $physician['id'];
    $_SESSION['username'] = (string) $physician['username'];
    $_SESSION['is_admin'] = (int) $physician['is_admin'] === 1;
    $_SESSION['logged_in_at'] = time();
}

function sv_logout(): void
{
    sv_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

function sv_current_user(): ?array
{
    sv_session_start();
    $id = isset($_SESSION['physician_id']) ? (int) $_SESSION['physician_id'] : 0;
    if ($id <= 0) {
        return null;
    }
    $row = sv_find_physician($id);
    if (!$row || $row['deleted_at'] !== null) {
        sv_logout();
        return null;
    }
    return $row;
}

function sv_is_admin(): bool
{
    $u = sv_current_user();
    return $u !== null && (int) $u['is_admin'] === 1;
}

function sv_require_auth(): array
{
    sv_session_start();
    $u = sv_current_user();
    if ($u !== null) {
        return $u;
    }
    $next = $_SERVER['REQUEST_URI'] ?? '';
    $qs = $next !== '' ? '?next=' . urlencode($next) : '';
    header('Location: login.php' . $qs);
    exit;
}

function sv_require_admin(): array
{
    $u = sv_require_auth();
    if ((int) $u['is_admin'] !== 1) {
        if (!function_exists('__')) {
            require_once __DIR__ . '/Locale.php';
            Locale::init();
        }
        http_response_code(403);
        echo htmlspecialchars(__('error.forbidden_admin'), ENT_QUOTES, 'UTF-8');
        exit;
    }
    return $u;
}

function sv_set_password(int $physicianId, string $newPassword): void
{
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = db()->prepare('UPDATE physicians SET password_hash = ? WHERE id = ?');
    $stmt->execute([$hash, $physicianId]);
}

function sv_create_reset_token(int $physicianId, ?int $createdByAdminId): string
{
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+' . SV_TOKEN_TTL_HOURS . ' hours'))
        ->format('Y-m-d H:i:s');
    $stmt = db()->prepare(
        'INSERT INTO password_reset_tokens (physician_id, token, created_by_physician_id, expires_at)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$physicianId, $token, $createdByAdminId, $expires]);
    return $token;
}

function sv_consume_reset_token(string $token): ?array
{
    $stmt = db()->prepare(
        'SELECT t.*, p.username, p.deleted_at, p.is_admin
         FROM password_reset_tokens t
         JOIN physicians p ON p.id = t.physician_id
         WHERE t.token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($row['used_at'] !== null) {
        return null;
    }
    if ($row['deleted_at'] !== null) {
        return null;
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return null;
    }
    return $row;
}

function sv_mark_reset_token_used(int $tokenId): void
{
    $stmt = db()->prepare(
        'UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $stmt->execute([$tokenId]);
}

function sv_validate_password(string $pw): ?string
{
    if (strlen($pw) < SV_MIN_PASSWORD_LEN) {
        if (!function_exists('__')) {
            require_once __DIR__ . '/Locale.php';
            Locale::init();
        }
        return __('auth.password_min', ['min' => (string) SV_MIN_PASSWORD_LEN]);
    }
    return null;
}

function sv_reset_link(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return $scheme . '://' . $host . $path . '/reset_password.php?token=' . urlencode($token);
}
