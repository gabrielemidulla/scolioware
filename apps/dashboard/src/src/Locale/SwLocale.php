<?php

declare(strict_types=1);

namespace App\Locale;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class SwLocale
{
    public const COOKIE_NAME = 'sw_locale';

    /** @var list<string> */
    public const SUPPORTED = ['en', 'it'];

    private const DEFAULT = 'en';

    private const DOMAIN = 'messages';

    private static ?Translator $translator = null;

    private static string $code = self::DEFAULT;

    public static function init(): void
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? self::DEFAULT;
        $raw = is_string($raw) ? strtolower(trim($raw)) : self::DEFAULT;
        self::$code = in_array($raw, self::SUPPORTED, true) ? $raw : self::DEFAULT;

        if (self::$translator === null) {
            $translator = new Translator(self::DEFAULT);
            $translator->setFallbackLocales([self::DEFAULT]);
            $translator->addLoader('yaml', new YamlFileLoader());
            $dir = dirname(__DIR__, 2) . '/translations';
            foreach (self::SUPPORTED as $loc) {
                $path = $dir . '/' . self::DOMAIN . '.' . $loc . '.yaml';
                if (is_file($path)) {
                    $translator->addResource('yaml', $path, $loc, self::DOMAIN);
                }
            }
            self::$translator = $translator;
        }

        self::$translator->setLocale(self::$code);
    }

    public static function translator(): Translator
    {
        if (self::$translator === null) {
            self::init();
        }

        return self::$translator;
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
        if (self::$translator === null) {
            self::init();
        }

        return self::$translator->trans($key, self::messagePlaceholders($params), self::DOMAIN);
    }

    /**
     * @param array<string|int, string|int|float> $params
     *
     * @return array<string, string>
     */
    private static function messagePlaceholders(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $ks = (string) $k;
            if ($ks !== '' && str_starts_with($ks, '%') && str_ends_with($ks, '%')) {
                $out[$ks] = (string) $v;
            } else {
                $out['%' . $ks . '%'] = (string) $v;
            }
        }

        return $out;
    }

    public static function requestUriForReturn(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/index.php');
        if ($uri === '' || $uri[0] !== '/') {
            return '/index.php';
        }

        return $uri;
    }

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
        echo '<div class="sw-locale-switcher" role="navigation" lang="' . $lang . '" aria-label="' . $aria . '">';
        echo '<img class="sw-locale-flag" src="assets/img/flags/' . $curEsc . '.png" width="20" height="20" alt="">';
        echo '<div class="sw-locale-select-wrap">';
        echo '<label class="visually-hidden" for="sw-locale-select">' . $lab . '</label>';
        echo '<select id="sw-locale-select" class="form-select form-select-sm" title="'
            . $title . '" ' . $on . '>';
        echo '<option value="en"' . ($cur === 'en' ? ' selected' : '') . '>' . $en . '</option>';
        echo '<option value="it"' . ($cur === 'it' ? ' selected' : '') . '>' . $it . '</option>';
        echo '</select></div></div>';
    }
}
