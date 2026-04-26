<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

const SW_TOKEN_TTL_HOURS = 24;
const SW_MIN_PASSWORD_LEN = 12;
const SW_SEED_ADMIN_PASSWORD_MIN_LEN = 12;

const SW_LOGIN_MAX_ATTEMPTS = 5;
const SW_LOGIN_LOCK_WINDOW_SEC = 300;

const SW_SESSION_IDLE_DEFAULT_SEC = 1800;
const SW_SESSION_MAX_AGE_DEFAULT_SEC = 43200;

function sw_env_int(string $name, int $default): int
{
    $v = getenv($name);
    if (!is_string($v) || $v === '' || !ctype_digit($v)) {
        return $default;
    }

    return (int) $v;
}

function sw_session_cookie_secure(): bool
{
    $v = getenv('SW_COOKIE_SECURE');
    if ($v === false || $v === '') {
        return true;
    }

    return $v === '1' || strtolower((string) $v) === 'true';
}

function sw_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => sw_session_cookie_secure(),
    ]);
    session_name('SW_SESSION');
    session_start();
}

function sw_csrf_token(): string
{
    sw_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function sw_csrf_check_token(string $token): bool
{
    if (!class_exists(\App\Auth\CsrfProtector::class, false)) {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
    if (class_exists(\App\Auth\CsrfProtector::class, false)) {
        return \App\Auth\CsrfProtector::verifyToken($token);
    }

    sw_session_start();

    return $token !== ''
        && !empty($_SESSION['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function sw_csrf_check(): void
{
    $tok = $_POST['csrf_token'] ?? '';
    $tok = is_string($tok) ? $tok : '';
    if (!sw_csrf_check_token($tok)) {
        http_response_code(403);
        echo htmlspecialchars(__('error.csrf'), ENT_QUOTES, 'UTF-8')
            . ' <a href="' . htmlspecialchars(sw_current_url()) . '">'
            . htmlspecialchars(__('error.csrf_reload'), ENT_QUOTES, 'UTF-8')
            . '</a>.';
        exit;
    }
}

function sw_current_url(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
}

function sw_login_is_locked_out(string $ip, string $username): bool
{
    $ip = trim($ip);
    $u = mb_strtolower(trim($username), 'UTF-8');
    if ($ip === '' && $u === '') {
        return false;
    }
    $window = SW_LOGIN_LOCK_WINDOW_SEC;
    $max = SW_LOGIN_MAX_ATTEMPTS;
    $since = (new DateTimeImmutable())->modify('-' . $window . ' seconds')->format('Y-m-d H:i:s');
    try {
        $pdo = \App\Infrastructure\Database::pdo();
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
    } catch (Throwable $e) {
        error_log('sw_login_is_locked_out: ' . $e->getMessage());

        return false;
    }

    return false;
}

function sw_login_record_failure(string $ip, string $username): void
{
    $ip = trim($ip);
    $u = mb_strtolower(trim($username), 'UTF-8');
    try {
        $pdo = \App\Infrastructure\Database::pdo();
        if ($ip !== '') {
            $pdo->prepare('INSERT INTO login_throttle (scope, identity) VALUES (?, ?)')->execute(['ip', $ip]);
        }
        if ($u !== '') {
            $pdo->prepare('INSERT INTO login_throttle (scope, identity) VALUES (?, ?)')->execute(['user', $u]);
        }
    } catch (Throwable $e) {
        error_log('sw_login_record_failure: ' . $e->getMessage());
    }
}

function sw_login_clear_throttle(string $ip, string $username): void
{
    $ip = trim($ip);
    $u = mb_strtolower(trim($username), 'UTF-8');
    try {
        $pdo = \App\Infrastructure\Database::pdo();
        if ($ip !== '') {
            $pdo->prepare('DELETE FROM login_throttle WHERE scope = ? AND identity = ?')
                ->execute(['ip', $ip]);
        }
        if ($u !== '') {
            $pdo->prepare('DELETE FROM login_throttle WHERE scope = ? AND identity = ?')
                ->execute(['user', $u]);
        }
    } catch (Throwable $e) {
        error_log('sw_login_clear_throttle: ' . $e->getMessage());
    }
}

function sw_find_physician_by_username(string $username): ?array
{
    $stmt = \App\Infrastructure\Database::pdo()->prepare(
        'SELECT * FROM physicians WHERE username = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function sw_find_physician(int $id): ?array
{
    $stmt = \App\Infrastructure\Database::pdo()->prepare('SELECT * FROM physicians WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function sw_login_user(array $physician): void
{
    sw_session_start();
    session_unset();
    session_regenerate_id(true);
    $now = time();
    $_SESSION['physician_id'] = (int) $physician['id'];
    $_SESSION['username'] = (string) $physician['username'];
    $_SESSION['is_admin'] = (int) $physician['is_admin'] === 1;
    $_SESSION['logged_in_at'] = $now;
    $_SESSION['last_activity'] = $now;
}

function sw_logout(): void
{
    sw_session_start();
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

function sw_session_apply_timeouts(int $physicianId): int
{
    if ($physicianId <= 0) {
        return 0;
    }
    $idle = sw_env_int('SW_SESSION_IDLE_SEC', SW_SESSION_IDLE_DEFAULT_SEC);
    $max = sw_env_int('SW_SESSION_MAX_AGE_SEC', SW_SESSION_MAX_AGE_DEFAULT_SEC);
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
        sw_logout();

        return 0;
    }
    if ($loginAt > 0 && $now - $loginAt > $max) {
        sw_logout();

        return 0;
    }
    $_SESSION['last_activity'] = $now;

    return $physicianId;
}

function sw_password_change_exempt_pages(): array
{
    return [
        'account.php',
        'logout.php',
        'set_language.php',
        'inference_session_auth.php',
    ];
}

function sw_user_must_set_password_on_this_request(array $u): bool
{
    if (!isset($u['password_must_change']) || (int) $u['password_must_change'] !== 1) {
        return false;
    }
    $p = sw_current_url();

    return !in_array($p, sw_password_change_exempt_pages(), true);
}

function sw_current_user(): ?array
{
    sw_session_start();
    $id = isset($_SESSION['physician_id']) ? (int) $_SESSION['physician_id'] : 0;
    if ($id <= 0) {
        return null;
    }
    $id = sw_session_apply_timeouts($id);
    if ($id <= 0) {
        return null;
    }
    $row = sw_find_physician($id);
    if (!$row || $row['deleted_at'] !== null) {
        sw_logout();

        return null;
    }

    return $row;
}

function sw_is_admin(): bool
{
    $u = sw_current_user();

    return $u !== null && (int) $u['is_admin'] === 1;
}

function sw_require_auth(): array
{
    sw_session_start();
    $u = sw_current_user();
    if ($u === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        $qs = $next !== '' ? '?next=' . urlencode($next) : '';
        if (defined('SW_SYMFONY_HTTP') && SW_SYMFONY_HTTP) {
            throw new \App\Exception\AuthRedirectException('login.php' . $qs);
        }
        header('Location: login.php' . $qs);
        exit;
    }
    if (sw_user_must_set_password_on_this_request($u)) {
        if (defined('SW_SYMFONY_HTTP') && SW_SYMFONY_HTTP) {
            throw new \App\Exception\AuthRedirectException('account.php?must_change=1');
        }
        header('Location: account.php?must_change=1');
        exit;
    }

    return $u;
}

function sw_require_admin(): array
{
    $u = sw_require_auth();
    if ((int) $u['is_admin'] !== 1) {
        if (defined('SW_SYMFONY_HTTP') && SW_SYMFONY_HTTP) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                (string) __('error.forbidden_admin')
            );
        }
        http_response_code(403);
        echo htmlspecialchars(__('error.forbidden_admin'), ENT_QUOTES, 'UTF-8');
        exit;
    }

    return $u;
}

function sw_set_password(int $physicianId, string $newPassword): void
{
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = \App\Infrastructure\Database::pdo()->prepare(
        'UPDATE physicians SET password_hash = ?, password_must_change = 0 WHERE id = ?'
    );
    $stmt->execute([$hash, $physicianId]);
    sw_session_start();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function sw_create_reset_token(int $physicianId, ?int $createdByAdminId): string
{
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+' . SW_TOKEN_TTL_HOURS . ' hours'))
        ->format('Y-m-d H:i:s');
    $stmt = \App\Infrastructure\Database::pdo()->prepare(
        'INSERT INTO password_reset_tokens (physician_id, token, created_by_physician_id, expires_at)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$physicianId, $token, $createdByAdminId, $expires]);

    return $token;
}

function sw_lookup_reset_token(string $token): ?array
{
    $stmt = \App\Infrastructure\Database::pdo()->prepare(
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

/** @deprecated Use sw_lookup_reset_token() */
function sw_consume_reset_token(string $token): ?array
{
    return sw_lookup_reset_token($token);
}

function sw_mark_reset_token_used(int $tokenId): void
{
    $stmt = \App\Infrastructure\Database::pdo()->prepare(
        'UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $stmt->execute([$tokenId]);
}

/** HIBP range check; SW_HIBP_PASSWORD_CHECK=0 disables. */
function sw_password_pwned_error(string $pw): ?string
{
    $off = getenv('SW_HIBP_PASSWORD_CHECK');
    if (is_string($off) && ($off === '0' || strtolower($off) === 'false')) {
        return null;
    }
    $hash = strtoupper(sha1($pw));
    $prefix = substr($hash, 0, 5);
    $suffix = substr($hash, 5);
    $url = 'https://api.pwnedpasswords.com/range/' . rawurlencode($prefix);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header' => "Add-Padding: true\r\nUser-Agent: Scolioware-Dashboard\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if (!is_string($body) || $body === '') {
        return null;
    }
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$pfx] = explode(':', $line, 2);
        $pfx = strtoupper(trim($pfx));
        if ($pfx === $suffix) {
            return __('auth.password_pwned');
        }
    }

    return null;
}

function sw_validate_password(string $pw): ?string
{
    if (strlen($pw) < SW_MIN_PASSWORD_LEN) {
        return __('auth.password_min', ['min' => (string) SW_MIN_PASSWORD_LEN]);
    }
    $pwned = sw_password_pwned_error($pw);
    if ($pwned !== null) {
        return $pwned;
    }

    return null;
}

function sw_reset_link(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

    return $scheme . '://' . $host . $path . '/reset_password.php?token=' . urlencode($token);
}
