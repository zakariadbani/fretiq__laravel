<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Util;

class Menu
{
    // Menu config
    public $items;

    // Current page path
    private $path;

    // Default menu tag
    private $parentTag = 'div';

    // Default menu item tag
    private $itemTag = 'div';

    // Default icon visibility
    private $displayIcons = true;

    // Default item link class
    private $itemLinkClass = '';

    // Default icon type in menu config
    private $iconType = 'svg';

    // Iteration level
    private $linkLevel = 0;

    /**
     * Constructor
     *
     * @param array $items
     * @param string $path
     */
    public function __construct($items, $path = '')
    {
        $this->items = $items;
        $this->path = $path;

        return $this;
    }

    /**
     * Build the menu
     */
    public function build()
    {
        foreach ($this->items as $item) {
            $this->_generateItem($item);
        }
    }

    /**
     * Generate menu item
     */
    private function _generateItem($item, $level = 0)
    {
        $classes = array('menu-item');
        $attributes = array();

        // Set iteration level
        $this->linkLevel = $level;

        // Exit if item is null
        if ($item === null) {
            return;
        }

        // Overcome recursive infinite loop
        if ($level > 100) {
            return;
        }

        // Check if item has submenu and matches current path
        if (isset($item['sub']) && $this->_matchParentItemByPath($item) === true) {
            $classes[] = 'here show';
        }

        if (isset($item['attributes']) && isset($item['attributes']['item'])) {
            $attributes = $item['attributes']['item'];
        } elseif (isset($item['attributes']) && isset($item['attributes']['link']) === false) {
            $attributes = $item['attributes'];
        }

        if (isset($item['classes']) && isset($item['classes']['item'])) {
            $classes[] = $item['classes']['item'];
        }

        echo '<!--begin:Menu item-->';
        echo '<' . $this->itemTag . ' ' . Util::getHtmlAttributes($attributes) . Util::getHtmlClass($classes) . '>';

        if (isset($item['content'])) {
            $this->_generateItemContent($item);
        }

        if (isset($item['title'])) {
            $this->_generateItemLink($item);
        }

        if (isset($item['sub'])) {
            $this->_generateItemSub($item['sub'], $level + 1);
        }

        echo '</' . $this->itemTag . '>';
        echo '<!--end:Menu item-->';
    }

    /**
     * Generate menu item link
     */
    private function _generateItemLink($item)
    {
        $classes = array('menu-link');
        $attributes = array();
        $tag = 'a';

        // Construct link attributes
        if (isset($item['path'])) {
            // Assign the page URL
            if (Util::isExternalURL($item['path'])) {
                $attributes['href'] = $item['path'];
            } else {
                $attributes['href'] = url($item['path']);
            }

            // Handle open in new tab mode
            if (isset($item['new-tab']) && $item['new-tab'] === true) {
                $attributes['target'] = '_blank';
            }
        } else {
            $tag = 'span';
        }

        if (isset($item['attributes']) && isset($item['attributes']['link'])) {
            $attributes = array_merge($attributes, $item['attributes']['link']);
        }

        if ($this->_matchItemByPath($item) === true) {
            $classes[] = 'active';
        }

        if (!empty($this->itemLinkClass)) {
            $classes[] = $this->itemLinkClass;
        }

        if (isset($item['classes']) && isset($item['classes']['link'])) {
            $classes[] = $item['classes']['link'];
        }

        echo '<!--begin:Menu link-->';
        echo '<' . $tag . Util::getHtmlClass($classes) . Util::getHtmlAttributes($attributes) . '>';

        if ($this->displayIcons !== false) {
            $this->_generateItemLinkIcon($item);
        }

        $this->_generateItemLinkBullet($item);

        if (isset($item['title'])) {
            $this->_generateItemLinkTitle($item);
        }

        $this->_generateItemLinkBadge($item);

        if (isset($item['sub'])) {
            $this->_generateItemLinkArrow($item);
        }

        echo '</' . $tag . '>';
        echo '<!--end:Menu link-->';
    }

    /**
     * Generate menu item title
     */
    private function _generateItemLinkTitle($item)
    {
        $classes = array('menu-title');

        if (isset($item['classes']) && isset($item['classes']['title'])) {
            $classes[] = $item['classes']['title'];
        }

        $title = $item['title'];

        echo '<span ' . Util::getHtmlClass($classes) . '>';
        echo __($title);
        echo '</span>';
    }

    /**
     * Generate menu item icon
     */
    private function _generateItemLinkIcon($item)
    {
        $classes = array('menu-icon');

        if (isset($item['classes']) && isset($item['classes']['icon'])) {
            $classes[] = $item['classes']['icon'];
        }

        if (isset($item['icon'])) {
            echo '<span ' . Util::getHtmlClass($classes) . '>';

            if (is_array($item['icon']) && isset($item['icon'][$this->iconType])) {
                // Use getIcon helper function if available
                if ($this->iconType === 'svg' && function_exists('getIcon')) {
                    echo getIcon($item['icon'][$this->iconType], 'fs-2');
                } else {
                    echo $item['icon'][$this->iconType];
                }
            } else {
                echo $item['icon'];
            }

            echo '</span>';

            return;
        }
    }

    /**
     * Generate menu item bullet
     */
    private function _generateItemLinkBullet($item)
    {
        if (isset($item['icon']) === true && $this->displayIcons !== false) {
            return;
        }

        $classes = array('menu-bullet');

        if (isset($item['classes']) && isset($item['classes']['bullet'])) {
            $classes[] = $item['classes']['bullet'];
        }

        echo '<span ' . Util::getHtmlClass($classes) . '>';
        echo '<span class="bullet bullet-dot"></span>';
        echo '</span>';
    }

    /**
     * Generate menu item badge
     */
    private function _generateItemLinkBadge($item)
    {
        $classes = array('menu-badge');

        if (isset($item['classes']) && isset($item['classes']['badge'])) {
            $classes[] = $item['classes']['badge'];
        }

        if (isset($item['badge'])) {
            echo '<span ' . Util::getHtmlClass($classes) . '>';
            echo $item['badge'];
            echo '</span>';
        }
    }

    /**
     * Generate menu item arrow
     */
    private function _generateItemLinkArrow($item)
    {
        $classes = array('menu-arrow');

        if (isset($item['classes']['arrow'])) {
            $classes[] = $item['classes']['arrow'];
        }

        echo '<span ' . Util::getHtmlClass($classes) . '>';
        echo '</span>';
    }

    /**
     * Generate submenu
     */
    private function _generateItemSub($sub, $level)
    {
        $classes = array('menu-sub', 'menu-sub-accordion');

        if (isset($sub['class'])) {
            $classes[] = $sub['class'];
        }

        echo '<!--begin:Menu sub-->';
        echo '<' . $this->parentTag . ' ' . Util::getHtmlClass($classes) . '>';

        if (is_array($sub)) {
            foreach ($sub as $item) {
                $this->_generateItem($item, $level);
            }
        }

        echo '</' . $this->parentTag . '>';
        echo '<!--end:Menu sub-->';
    }

    /**
     * Generate menu content (section header)
     */
    private function _generateItemContent($item)
    {
        $classes = array('menu-content');

        if (isset($item['classes']) && isset($item['classes']['content'])) {
            $classes[] = $item['classes']['content'];
        }

        if (isset($item['content'])) {
            echo '<!--begin:Menu content-->';
            echo '<div ' . Util::getHtmlClass($classes) . '>';
            echo '<span class="menu-heading fw-bold text-uppercase fs-7">';
            echo __($item['content']);
            echo '</span>';
            echo '</div>';
            echo '<!--end:Menu content-->';
        }
    }

    /**
     * Match parent item by path
     */
    private function _matchParentItemByPath($item, $level = 0)
    {
        if ($level > 100) {
            return false;
        }

        if ($this->_matchItemByPath($item) === true) {
            return true;
        } else {
            if (isset($item['sub']) && is_array($item['sub'])) {
                foreach ($item['sub'] as $currentItem) {
                    if ($this->_matchParentItemByPath($currentItem, $level + 1) === true) {
                        return true;
                    }
                }
            }

            return false;
        }
    }

    /**
     * Match item by path
     */
    private function _matchItemByPath($item)
    {
        if (isset($item['path'])) {
            $currentPath = trim(request()->path(), '/');
            $itemPath = trim($item['path'], '/');

            return $currentPath === $itemPath;
        }

        return false;
    }

    /**
     * Set icon type
     */
    public function setIconType($type)
    {
        $this->iconType = $type;
    }

    /**
     * Set item link class
     */
    public function setItemLinkClass($class)
    {
        $this->itemLinkClass = $class;
    }

    /**
     * Display icons flag
     */
    public function displayIcons($flag)
    {
        $this->displayIcons = $flag;
    }
}
