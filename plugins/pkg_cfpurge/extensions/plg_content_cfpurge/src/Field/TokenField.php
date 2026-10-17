<?php

/**
 * @package     WebG.Plugin
 * @subpackage  Content.cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Plugin\Content\Cfpurge\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use WebG\Plugin\Content\Cfpurge\Service\Config;

/**
 * The Cloudflare API token field. The saved token is never printed back into the page (not even
 * as the value of a password field): the field is empty and says that a token is saved. Saving
 * the form with the field empty keeps the saved token; typing a new one replaces it.
 *
 * @since  2.1.1
 */
class TokenField extends FormField
{
    /**
     * @var    string
     * @since  2.1.1
     */
    protected $type = 'Token';

    /**
     * @return  string
     *
     * @since   2.1.1
     */
    protected function getInput()
    {
        $language = Factory::getApplication()->getLanguage();
        $language->load('plg_content_cfpurge', JPATH_ADMINISTRATOR) || $language->load('plg_content_cfpurge', JPATH_PLUGINS . '/content/cfpurge');

        $saved = (Config::loadFromDatabase()?->token() ?? '') !== '';
        $hint  = Text::_($saved ? 'PLG_CONTENT_CFPURGE_TOKEN_SAVED' : 'PLG_CONTENT_CFPURGE_TOKEN_EMPTY');

        return '<input type="password" name="' . htmlspecialchars($this->name, ENT_QUOTES) . '" id="' . htmlspecialchars($this->id, ENT_QUOTES)
            . '" value="" size="50" class="form-control" autocomplete="new-password" spellcheck="false" placeholder="' . htmlspecialchars($hint, ENT_QUOTES) . '">'
            . '<p class="form-text">' . htmlspecialchars($hint, ENT_QUOTES) . '</p>';
    }

    /**
     * Joomla calls this when the form data is filtered, before it is saved.
     *
     * @param   mixed          $value  What the form sent.
     * @param   string|null    $group  The field group.
     * @param   Registry|null  $input  All the data.
     *
     * @return  string
     *
     * @since   2.1.1
     */
    public function filter($value, $group = null, ?Registry $input = null)
    {
        return self::keep($value);
    }

    /**
     * An empty field keeps the saved token, a typed one replaces it (control
     * characters removed, they would end up in a header).
     *
     * @param   mixed  $value  What the form sent.
     *
     * @return  string
     *
     * @since   2.1.1
     */
    public static function keep($value): string
    {
        $new = trim((string) preg_replace('/[[:cntrl:]]+/', '', (string) $value));

        if ($new !== '') {
            return $new;
        }

        return Config::loadFromDatabase()?->token() ?? '';
    }
}
