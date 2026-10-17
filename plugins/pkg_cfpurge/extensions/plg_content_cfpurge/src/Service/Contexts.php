<?php

/**
 * @package     WebG.Plugin
 * @subpackage  Content.cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Plugin\Content\Cfpurge\Service;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Maps the context of a Joomla event to the kind of item the plugin knows.
 *
 * @since  2.0.0
 */
final class Contexts
{
    public const ARTICLE  = 'article';
    public const CATEGORY = 'category';
    public const MENU     = 'menu';
    public const MODULE   = 'module';
    public const FIELD    = 'field';
    public const TAG      = 'tag';

    /**
     * @param   string  $context  The event context, for example com_content.article.
     *
     * @return  string|null  One of the class constants, null for contexts to ignore.
     *
     * @since   2.0.0
     */
    public static function kind(string $context): ?string
    {
        return match (true) {
            str_starts_with($context, 'com_content.article'), str_starts_with($context, 'com_content.form') => self::ARTICLE,
            $context === 'com_categories.category', $context === 'com_content.category' => self::CATEGORY,
            $context === 'com_menus.item' => self::MENU,
            $context === 'com_modules.module' => self::MODULE,
            str_starts_with($context, 'com_fields.') => self::FIELD,
            $context === 'com_tags.tag' => self::TAG,
            default => null,
        };
    }

    /**
     * Name of the parameter that switches the events of a kind (articles have four).
     *
     * @param   string  $kind  A kind.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public static function param(string $kind): string
    {
        return 'on_' . $kind;
    }
}
