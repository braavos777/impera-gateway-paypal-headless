<?php

/**
 * @author Artem <gammaironak@gmail.com>
 * @date 29.05.2026
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Core\Support;

class StringUtil
{
    /**
     * The cache of snake-cased words.
     *
     * @var array
     */
    protected static $snakeCache = [];

    /**
     * The cache of camel-cased words.
     *
     * @var array
     */
    protected static $camelCache = [];

    /**
     * The cache of studly-cased words.
     *
     * @var array
     */
    protected static $studlyCache = [];


    /**
     * Convert a value to camel case.
     *
     * @param  string  $value
     * @return string
     */
    public static function camel($value)
    {
        if (isset(static::$camelCache[$value])) {
            return static::$camelCache[$value];
        }

        return static::$camelCache[$value] = lcfirst(static::studly($value));
    }

    /**
     * Convert a string to kebab case.
     *
     * @param  string  $value
     * @return string
     */
    public static function kebab($value)
    {
        return static::snake($value, '-');
    }


    /**
     * Convert a string to snake case.
     *
     * @param  string  $value
     * @param  string  $delimiter
     * @return string
     */
    public static function snake($value, $delimiter = '_')
    {
        $key = $value;

        if (isset(static::$snakeCache[$key][$delimiter])) {
            return static::$snakeCache[$key][$delimiter];
        }

        if (! ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));

            $value = static::lower(preg_replace('/(.)(?=[A-Z])/u', '$1'.$delimiter, $value));
        }

        return static::$snakeCache[$key][$delimiter] = $value;
    }


    /**
     * Convert a value to studly caps case.
     * @param string $value
     * @return string
     * @example
     * Utils::studly('save_domain')         => 'SaveDomain'
     * Utils::studly('rotate-mode-route')   => 'RotateModeRoute'
     * Utils::studly('payment')             => 'Payment'
     *
     */
    public static function studly($value)
    {
        $key = $value;

        if (isset(static::$studlyCache[$key])) {
            return static::$studlyCache[$key];
        }

        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return static::$studlyCache[$key] = str_replace(' ', '', $value);
    }

    /**
     * Convert the given string to lower-case.
     *
     * @param  string  $value
     * @return string
     */
    public static function lower($value)
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Return the length of the given string.
     *
     * @param  string  $value
     * @param  string|null  $encoding
     * @return int
     */
    public static function length($value, $encoding = null)
    {
        if ($encoding) {
            return mb_strlen($value, $encoding);
        }

        return mb_strlen($value);
    }

    /**
     * Limit the number of characters in a string.
     *
     * @param  string  $value
     * @param  int  $limit
     * @param  string  $end
     * @return string
     */
    public static function limit($value, $limit = 100, $end = '...')
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')).$end;
    }

    /**
     * Limit the number of words in a string.
     *
     * @param  string  $value
     * @param  int  $words
     * @param  string  $end
     * @return string
     */
    public static function words($value, $words = 100, $end = '...')
    {
        preg_match('/^\s*+(?:\S++\s*+){1,'.$words.'}/u', $value, $matches);

        if (! isset($matches[0]) || static::length($value) === static::length($matches[0])) {
            return $value;
        }

        return rtrim($matches[0]).$end;
    }

    /**
     * Generate a URL friendly "slug" from a given string.
     *
     * @param  string  $title
     * @param  string  $separator
     * @return string
     */
    public static function slug($title, $separator = '-')
    {
        // Convert all dashes/underscores into separator
        $flip = $separator === '-' ? '_' : '-';

        $title = preg_replace('/[' . preg_quote($flip, '/') . ']+/u', $separator, $title);

        // Replace @ with the word 'at'
        $title = str_replace('@', $separator.'at'.$separator, $title);

        // Remove all characters that are not the separator, letters, numbers, or whitespace.
        $title = preg_replace('![^'.preg_quote($separator, '!').'\pL\pN\s]+!u', '', static::lower($title));

        // Replace all separator characters and whitespace by a single separator
        $title = preg_replace('!['.preg_quote($separator, '!').'\s]+!u', $separator, $title);


        return trim($title, $separator);
    }


    /**
     * Returns the portion of string specified by the start and length parameters.
     *
     * @param  string  $string
     * @param  int  $start
     * @param  int|null  $length
     * @return string
     */
    public static function substr($string, $start, $length = null)
    {
        return mb_substr($string, $start, $length, 'UTF-8');
    }

    /**
     * Make a string's first character uppercase.
     *
     * @param  string  $string
     * @return string
     */
    public static function ucfirst($string)
    {
        return static::upper(static::substr($string, 0, 1)).static::substr($string, 1);
    }


    /**
     * Convert the given string to upper-case.
     *
     * @param  string  $value
     * @return string
     */
    public static function upper($value)
    {
        return mb_strtoupper($value, 'UTF-8');
    }


    /**
     * Convert the given string to ClassName.
     *
     * @param  string  $value
     * @return string
     */
    public static function className($value)
    {
        return static::ucfirst(static::camel($value));
    }

    /**
     * Checks if the method name is a getter (whether it starts with "get").
     *
     * @example Utils::isGetter('getId')      => true
     * @example Utils::isGetter('changeName') => false
     *
     * @param  string  $methodName
     * @return bool
     */
    public static function isGetter(string $methodName): bool
    {
        return str_starts_with($methodName, 'get');
    }

    /**
     * Checks if the method name is a setter (whether it starts with "set").
     *
     * @example Utils::isSetter('setId')      => true
     * @example Utils::isSetter('updateData') => false
     *
     * @param  string  $methodName
     * @return bool
     */
    public static function isSetter(string $methodName): bool
    {
        return str_starts_with($methodName, 'set');
    }

    /**
     * Extracts the name of the property in camelCase format from the name of the method (getter or setter).
     *
     * The method cuts off the first 3 characters of the prefix (get/set) and converts
     * the remainder of the lowercase string for the first letter.
     *
     * @example Utils::propertyCamelFromMethod('getDomainName') => 'domainName'
     * @example Utils::propertyCamelFromMethod('setTimeLimit') => 'timeLimit'
     *
     * @param  string  $methodName
     * @return string
     */
    public static function propertyCamelFromMethod(string $methodName): string
    {
        $rawPropertyName = substr($methodName, 3);

        return self::camel($rawPropertyName);
    }

    public static function generateRandomString($length = 16)
    {
        $length = $length - 1;
        $characters = '012345678901234567890123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return random_int(0, 9) . $randomString;
    }

}
