<?php
declare(strict_types=1);

/**
 * Configuration access with dot notation: Config::get('db.host').
 */
final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException(
                'Missing configuration file config/config.php – copy config/config.example.php.'
            );
        }
        $data = require $file;
        if (!is_array($data)) {
            throw new RuntimeException('config/config.php must return an array.');
        }
        self::$data = $data;
    }

    /** @param mixed $default */
    public static function get(string $key, $default = null)
    {
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                return $default;
            }
        }
        return $value;
    }

    /** Default UI language for users who have not chosen one yet ('cs' or 'en'; anything else → 'en'). */
    public static function defaultLanguage(): string
    {
        return self::get('app.default_language') === 'cs' ? 'cs' : 'en';
    }

    public static function all(): array
    {
        return self::$data;
    }
}
