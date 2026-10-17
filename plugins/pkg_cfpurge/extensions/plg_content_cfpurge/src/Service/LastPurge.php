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

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * The one-line description of the last purge shown in the dashboard box, in the language of
 * the logged-in user. Used by the module (page load) and by the plugin (after a button).
 *
 * @since  2.0.0
 */
final class LastPurge
{
    /**
     * @param   array|null  $last  The log entry (see PurgeLog::last()).
     *
     * @return  string  Empty when there is no purge in the log.
     *
     * @since   2.0.0
     */
    public static function describe(?array $last): string
    {
        if ($last === null) {
            return '';
        }

        $language = Factory::getApplication()->getLanguage();
        $language->load('plg_content_cfpurge', JPATH_ADMINISTRATOR) || $language->load('plg_content_cfpurge', JPATH_PLUGINS . '/content/cfpurge');

        try {
            $when = (string) HTMLHelper::_('date', (string) ($last['at'] ?? ''), Text::_('DATE_FORMAT_LC6'));
        } catch (\Throwable $e) {
            $when = (string) ($last['at'] ?? '');
        }

        $scope = ($last['scope'] ?? 'all') === 'all'
            ? Text::_('PLG_CONTENT_CFPURGE_LAST_SCOPE_ALL')
            : Text::sprintf('PLG_CONTENT_CFPURGE_LAST_SCOPE_URLS', (int) ($last['n'] ?? 0));

        $sources = [];

        foreach (explode(',', (string) ($last['src'] ?? '')) as $source) {
            $key       = 'PLG_CONTENT_CFPURGE_LAST_SRC_' . strtoupper(str_replace('-', '_', trim($source)));
            $sources[] = Text::_($key) !== $key ? Text::_($key) : trim($source);
        }

        $outcome = !empty($last['ok']) ? Text::_('PLG_CONTENT_CFPURGE_LAST_OUTCOME_OK') : Text::sprintf('PLG_CONTENT_CFPURGE_LAST_OUTCOME_FAIL', (string) ($last['msg'] ?? ''));
        $user    = (string) ($last['user'] ?? '') !== '' ? (string) $last['user'] : Text::_('PLG_CONTENT_CFPURGE_LAST_USER_SYSTEM');

        return Text::sprintf('PLG_CONTENT_CFPURGE_LAST_LINE', $when, $user, $scope, implode(', ', array_filter($sources)), $outcome);
    }
}
