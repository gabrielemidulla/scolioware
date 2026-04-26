<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SV_TOKEN_TTL_HOURS = 24;
const SV_MIN_PASSWORD_LEN = 6;

/** Max login failures per 5 minutes (per IP and per username). */
const SV_LOGIN_MAX_ATTEMPTS = 5;
const SV_LOGIN_LOCK_WINDOW_SEC = 300;

/** Default: 30 min idle; set SV_SESSION_IDLE_SEC. */
const SV_SESSION_IDLE_DEFAULT_SEC = 1800;
/** Default: 12 h absolute; set SV_SESSION_MAX_AGE_SEC. */
const SV_SESSION_MAX_AGE_DEFAULT_SEC = 43200;

function sv_env_int(string $name, int $default): int
{
    $v = getenv($name);
    if (!is_string($v) || $v === '' || !ctype_digit($v)) {
        return $default;
    }
    return (int) $v;
}

function sv_session_cookie_secure(): bool
{
    $v = getenv('SV_COOKIE_SECURE');
    if ($v === false || $v === '') {
        return true;
    }
    return $v === '1' || strtolower((string) $v) === 'true';
}

function sv_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => sv_session_cookie_secure(),
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

/**
 * Return true if this IP (or username) is rate-limited.
 */
function sv_login_is_locked_out(string $ip, string $username): bool
{
    $ip = trim($ip);
    $u = mb_strtolower(trim($username), 'UTF-8');
    if ($ip === '' && $u === '') {
        return false;
    }
    $window = SV_LOGIN_LOCK_WINDOW_SEC;
    $max = SV_LOGIN_MAX_ATTEMPTS;
    $since = (new DateTimeImmutable())->modify('-' . $window . ' seconds')->format('Y-m-d H:i:s');
    try {
        $pdo = db();
        if ($ip !== '') {
            $s = $pdo->prepare('SELECT COUNT(*) FROM login_throttle WHERE scope = ? AND identity = ? AND attempted_at >= ?');
            $s->execute(['ip', $ip, $since]);
            if ((int) $s->fetchColumn() >= $max) {
                return true;
            }
        }
        if ($u !== '') {
            $s = $pdo->prepare('SELECT COUNT(*) FROM login_throttle WHERE scope = ? AND identity = ? AND attempted_at >= ?');
            $s->execute(['user', $u, $since]);
            if ((int) $s->fetchColumn() >= $max) {
                return true;
            }
        }
    } catch (Throwable) {
        return false;
    }
    return false;
}

function sv_login_record_failure(string $ip, string $username): void
{
    $ip = trim($ip);
    $u = mb_strtolower(trim($username), 'UTF-8');
    try {
        $pdo = db();
        if ($ip !== '') {
            $pdo->prepare('INSERT INTO login_throttle (scope, identity) VALUES (?, ?)')->execute(['ip', $ip]);
        }
        if ($u !== '') {
            $pdo->prepare('INSERT INTO login_throttle (scope, identity) VALUES (?, ?)')->execute(['user', $u]);
        }
    } catch (Throwable) {
    }
}

function sv_login_clear_throttle(string $ip, string $username): void
{
    $ip = trim($ip);
    $u = mb_strtolower(trim($username), 'UTF-8');
    try {
        $pdo = db();
        if ($ip !== '') {
            $pdo->prepare('DELETE FROM login_throttle WHERE scope = ? AND identity = ?')
                ->execute(['ip', $ip]);
        }
        if ($u !== '') {
            $pdo->prepare('DELETE FROM login_throttle WHERE scope = ? AND identity = ?')
                ->execute(['user', $u]);
        }
    } catch (Throwable) {
    }
}

/**
 * @deprecated No longer used — run `php seed_admin.php` (CLI) once. Kept for grep compatibility.
 */
function sv_ensure_admin(): void
{
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
    $now = time();
    $_SESSION['physician_id'] = (int) $physician['id'];
    $_SESSION['username'] = (string) $physician['username'];
    $_SESSION['is_admin'] = (int) $physician['is_admin'] === 1;
    $_SESSION['logged_in_at'] = $now;
    $_SESSION['last_activity'] = $now;
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

function sv_session_timeouts(int &$physicianId): void
{
    $idle = sv_env_int('SV_SESSION_IDLE_SEC', SV_SESSION_IDLE_DEFAULT_SEC);
    $max = sv_env_int('SV_SESSION_MAX_AGE_SEC', SV_SESSION_MAX_AGE_DEFAULT_SEC);
    if ($idle < 60) {
        $idle = 60;
    }
    if ($max < $idle) {
        $max = $idle;
    }
    $now = time();
    $la = (int) ($_SESSION['last_activity'] ?? 0);
    $loginAt = (int) ($_SESSION['logged_in_at'] ?? 0);
    if ($la > 0 && $now - $la > $idle) {
        sv_logout();
        $physicianId = 0;
        return;
    }
    if ($loginAt > 0 && $now - $loginAt > $max) {
        sv_logout();
        $physicianId = 0;
        return;
    }
    $_SESSION['last_activity'] = $now;
}

/**
 * Pages reachable before forced password change completes.
 *
 * @return list<string>
 */
function sv_password_change_exempt_pages(): array
{
    return [
        'account.php',
        'logout.php',
        'set_language.php',
        'inference_session_auth.php',
    ];
}

/**
 * @param array $u Row from {@see sv_find_physician} / {@see sv_current_user}
 */
function sv_user_must_set_password_on_this_request(array $u): bool
{
    if (!isset($u['password_must_change']) || (int) $u['password_must_change'] !== 1) {
        return false;
    }
    $p = sv_current_url();
    return !in_array($p, sv_password_change_exempt_pages(), true);
}

function sv_current_user(): ?array
{
    sv_session_start();
    $id = isset($_SESSION['physician_id']) ? (int) $_SESSION['physician_id'] : 0;
    if ($id <= 0) {
        return null;
    }
    sv_session_timeouts($id);
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
    if ($u === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        $qs = $next !== '' ? '?next=' . urlencode($next) : '';
        header('Location: login.php' . $qs);
        exit;
    }
    if (sv_user_must_set_password_on_this_request($u)) {
        header('Location: account.php?must_change=1');
        exit;
    }
    return $u;
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
    $stmt = db()->prepare(
        'UPDATE physicians SET password_hash = ?, password_must_change = 0 WHERE id = ?'
    );
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
