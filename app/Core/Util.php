<?php

namespace App\Core;

class Util
{
    /**
     * Get HTML attributes as a string
     *
     * @param array $attributes
     * @return string|false
     */
    public static function getHtmlAttributes($attributes = array())
    {
        $result = array();

        if (empty($attributes)) {
            return false;
        }

        foreach ($attributes as $name => $value) {
            if (!empty($value) || $value === '0') {
                $result[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        return ' ' . implode(' ', $result) . ' ';
    }

    /**
     * Get HTML class attribute
     *
     * @param array $classes
     * @param bool $full
     * @return string
     */
    public static function getHtmlClass($classes, $full = true)
    {
        if (is_array($classes)) {
            $classes = implode(' ', $classes);
        }

        if ($full === true) {
            return ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '" ';
        } else {
            return ' ' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . ' ';
        }
    }

    /**
     * Check if URL is external
     *
     * @param string $url
     * @return bool
     */
    public static function isExternalURL($url)
    {
        $url = trim(strtolower($url));

        if (substr($url, 0, 2) == '//') {
            return true;
        }

        if (substr($url, 0, 7) == 'http://') {
            return true;
        }

        if (substr($url, 0, 8) == 'https://') {
            return true;
        }

        return false;
    }
}
