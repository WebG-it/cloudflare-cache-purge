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

use Joomla\CMS\User\User;

/**
 * Who may use the dashboard buttons. Joomla plugins have no permission screen, so the
 * rule is: Super Users, plus the user groups chosen in the plugin options (default
 * Administrator and Super Users). Child groups inherit the permission of their parents.
 *
 * @since  2.0.0
 */
final class Access
{
    /**
     * @param   User|null  $user    The user (null = nobody).
     * @param   Config     $config  The plugin settings.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    public static function canPurge(?User $user, Config $config): bool
    {
        if ($user === null || $user->guest) {
            return false;
        }

        if ($user->authorise('core.admin')) {
            return true;
        }

        return array_intersect($config->allowedGroups(), array_map('intval', $user->getAuthorisedGroups())) !== [];
    }
}
