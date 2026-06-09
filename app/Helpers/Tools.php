<?php

namespace App\Helpers;

class Tools
{
    /**
     * Dynamically include route files from a directory
     *
     * @param string $directory The directory path relative to routes/ folder
     * @param bool $recursive Whether to include subdirectories recursively
     * @return void
     */
    public static function includeRoutes(string $directory, bool $recursive = true): void
    {
        $routesPath = base_path("routes/{$directory}");

        if (!is_dir($routesPath)) {
            return;
        }

        $files = scandir($routesPath);

        foreach ($files as $file) {
            // Skip current and parent directory references
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $routesPath . '/' . $file;

            // If it's a directory and recursive is enabled, include routes from subdirectory
            if (is_dir($filePath) && $recursive) {
                self::includeRoutes($directory . '/' . $file, $recursive);
                continue;
            }

            // If it's a PHP file, include it.
            // Use require (not include_once) so test suites that refresh the app
            // between tests re-register routes from this file each time.
            if (is_file($filePath) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                require $filePath;
            }
        }
    }
}
