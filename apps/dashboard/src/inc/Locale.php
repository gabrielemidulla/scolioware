<?php

declare(strict_types=1);

/**
 * UI strings: load catalog for the active language (cookie {@see self::COOKIE_NAME}).
 */
final class Locale
{
    public const COOKIE_NAME = 'sv_locale';

    /** @var list<string> */
    public const SUPPORTED = ['en', 'it'];

    private const DEFAULT = 'en';

    /** @var array<string, string> */
    private static array $messages = [];

    private static string $code = self::DEFAULT;

    public static function init(): void
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? self::DEFAULT;
        $raw = is_string($raw) ? strtolower(trim($raw)) : self::DEFAULT;
        self::$code = in_array($raw, self::SUPPORTED, true) ? $raw : self::DEFAULT;
        $path = dirname(__DIR__) . '/locales/' . self::$code . '.php';
        if (!is_file($path)) {
            $path = dirname(__DIR__) . '/locales/' . self::DEFAULT . '.php';
        }
        /** @var array<string, string> $data */
        $data = require $path;
        self::$messages = $data;
    }

    public static function current(): string
    {
        return self::$code;
    }

    public static function htmlLang(): string
    {
        return self::$code;
    }

    public static function t(string $key, array $params = []): string
    {
        $s = self::$messages[$key] ?? $key;
        foreach ($params as $k => $v) {
            $s = str_replace('{' . $k . '}', (string) $v, $s);
        }
        return $s;
    }

    public static function requestUriForReturn(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/index.php');
        if ($uri === '' || $uri[0] !== '/') {
            return '/index.php';
        }
        return $uri;
    }

    /**
     * Flag + language select; on change, navigates to set_language.php (sets cookie) and returns to the current page.
     */
    public static function renderLanguageSwitcher(): void
    {
        $cur = self::current();
        $return = rawurlencode(self::requestUriForReturn());
        $lang = htmlspecialchars(self::htmlLang(), ENT_QUOTES, 'UTF-8');
        $aria = htmlspecialchars(self::t('lang.switcher_aria'), ENT_QUOTES, 'UTF-8');
        $lab = htmlspecialchars(self::t('lang.label'), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars(self::t('lang.title'), ENT_QUOTES, 'UTF-8');
        $en = htmlspecialchars(self::t('lang.en'), ENT_QUOTES, 'UTF-8');
        $it = htmlspecialchars(self::t('lang.it'), ENT_QUOTES, 'UTF-8');
        $curEsc = htmlspecialchars($cur, ENT_QUOTES, 'UTF-8');
        $returnAttr = htmlspecialchars($return, ENT_QUOTES, 'UTF-8');
        $on = 'onchange="window.location.href=\'set_language.php?lang=\'+encodeURIComponent(this.value)+\'&return='
            . $returnAttr . '\'"';
        echo '<div class="sv-locale-switcher" role="navigation" lang="' . $lang . '" aria-label="' . $aria . '">';
        echo '<img class="sv-locale-flag" src="assets/img/flags/' . $curEsc . '.png" width="20" height="20" alt="">';
        echo '<div class="sv-locale-select-wrap">';
        echo '<label class="visually-hidden" for="sv-locale-select">' . $lab . '</label>';
        echo '<select id="sv-locale-select" class="form-select form-select-sm" title="'
            . $title . '" ' . $on . '>';
        echo '<option value="en"' . ($cur === 'en' ? ' selected' : '') . '>' . $en . '</option>';
        echo '<option value="it"' . ($cur === 'it' ? ' selected' : '') . '>' . $it . '</option>';
        echo '</select></div></div>';
    }
}

function __(string $key, array $params = []): string
{
    return Locale::t($key, $params);
}
