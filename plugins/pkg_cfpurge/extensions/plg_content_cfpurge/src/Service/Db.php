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

use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

/**
 * New query on Joomla 5.0 and later: createQuery() exists from 5.2, getQuery(true) is
 * kept until Joomla 7.
 *
 * @since  2.0.0
 */
final class Db
{
    /**
     * @param   DatabaseInterface  $db  The database.
     *
     * @return  QueryInterface
     *
     * @since   2.0.0
     */
    public static function query(DatabaseInterface $db): QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
