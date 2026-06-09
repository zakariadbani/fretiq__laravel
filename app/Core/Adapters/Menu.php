<?php

namespace App\Core\Adapters;

/**
 * Adapter class to make the Metronic core lib compatible with the Laravel functions
 *
 * Class Menu
 *
 * @package App\Core\Adapters
 */
class Menu extends \App\Core\Menu
{
    public function build()
    {
        ob_start();

        parent::build();

        return ob_get_clean();
    }

    /**
     * Filter menu item based on the user permission using Spatie plugin
     *
     * @param $array
     */
    public static function filterMenuPermissions(&$array)
    {
        if (!is_array($array)) {
            return;
        }

        $user = auth()->user();

        $checkPermission = false;
        if (auth()->check()) {
            // check if the spatie plugin functions exist
            $checkPermission = method_exists($user, 'hasAnyPermission');
        }

        $checkRole = false;
        if (auth()->check()) {
            $checkRole = method_exists($user, 'hasAnyRole');
        }

        foreach ($array as $key => &$value) {
            if (is_callable($value)) {
                continue;
            }

            if ($checkPermission && isset($value['permission']) && !$user->hasAnyPermission((array) $value['permission'])) {
                unset($array[$key]);
                continue;
            }

            if ($checkRole && isset($value['role']) && !$user->hasAnyRole((array) $value['role'])) {
                unset($array[$key]);
                continue;
            }

            // Recursively filter submenu items
            if (isset($value['sub']) && is_array($value['sub'])) {
                self::filterMenuPermissions($value['sub']);

                // Remove parent if all sub items are filtered out
                if (empty($value['sub'])) {
                    unset($array[$key]);
                }
            }
        }
    }
}
