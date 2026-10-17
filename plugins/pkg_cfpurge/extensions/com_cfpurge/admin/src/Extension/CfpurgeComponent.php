<?php

/**
 * @package     WebG.Component
 * @subpackage  com_cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Component\Cfpurge\Administrator\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Extension\MVCComponent;

/**
 * The component has no screens of its own: it only answers the Joomla Web Services API
 * (see api/) and holds the permissions that say who may use it.
 *
 * @since  2.1.0
 */
class CfpurgeComponent extends MVCComponent
{
}
