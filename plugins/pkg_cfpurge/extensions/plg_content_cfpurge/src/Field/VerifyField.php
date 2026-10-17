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
use Joomla\CMS\Session\Session;
use WebG\Plugin\Content\Cfpurge\Service\Config;

/**
 * The "Verify connection" panel of the plugin options: the saved result of the last check
 * (it travels in a hidden field), the button and the list of checks that the button fills.
 *
 * @since  2.0.0
 */
class VerifyField extends FormField
{
    /**
     * @var    string
     * @since  2.0.0
     */
    protected $type = 'Verify';

    /**
     * The panel has its own captions: no label column.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    protected function getLabel()
    {
        return '';
    }

    /**
     * @return  string
     *
     * @since   2.0.0
     */
    protected function getInput()
    {
        $language = Factory::getApplication()->getLanguage();
        $language->load('plg_content_cfpurge', JPATH_ADMINISTRATOR) || $language->load('plg_content_cfpurge', JPATH_PLUGINS . '/content/cfpurge');

        $document = Factory::getApplication()->getDocument();
        $wa       = $document->getWebAssetManager();

        $wa->getRegistry()->addExtensionRegistryFile('plg_content_cfpurge');
        $wa->useScript('plg_content_cfpurge.verify')->useStyle('plg_content_cfpurge.verify');

        foreach (['STATUS_OK', 'STATUS_WARN', 'STATUS_FAIL', 'JS_WORKING', 'JS_ERROR', 'JS_ZONE_FILLED'] as $key) {
            Text::script('PLG_CONTENT_CFPURGE_' . $key);
        }

        $document->addScriptOptions('plg_content_cfpurge', [
            'url'   => 'index.php?option=com_ajax&group=content&plugin=cfpurge&format=json',
            'token' => Session::getFormToken(),
        ]);

        [$kind, $message] = $this->savedState();

        $zoneField  = str_replace('verify_state', 'zone_id', $this->id);
        $tokenField = str_replace('verify_state', 'api_token', $this->id);

        return '<div class="cfpurge-verify" data-zone-field="' . htmlspecialchars($zoneField, ENT_QUOTES) . '" data-token-field="' . htmlspecialchars($tokenField, ENT_QUOTES) . '">'
            . '<input type="hidden" name="' . htmlspecialchars($this->name, ENT_QUOTES) . '" id="' . htmlspecialchars($this->id, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $this->value, ENT_QUOTES) . '">'
            . '<div class="cfpurge-state alert alert-' . $kind . '" role="status">' . htmlspecialchars($message, ENT_QUOTES) . '</div>'
            . '<button type="button" class="btn btn-primary cfpurge-verify-btn">' . htmlspecialchars(Text::_('PLG_CONTENT_CFPURGE_VERIFY_BUTTON'), ENT_QUOTES) . '</button> '
            . '<button type="button" class="btn btn-secondary cfpurge-use-zone" hidden>' . htmlspecialchars(Text::_('PLG_CONTENT_CFPURGE_USE_ZONE_BUTTON'), ENT_QUOTES) . '</button>'
            . '<ul class="cfpurge-checks" aria-live="polite"></ul>'
            . '<p class="form-text">' . htmlspecialchars(Text::_('PLG_CONTENT_CFPURGE_VERIFY_HINT'), ENT_QUOTES) . '</p>'
            . '</div>';
    }

    /**
     * What the saved settings say: verified, failed, or never checked.
     *
     * @return  array  Alert class and text.
     *
     * @since   2.0.0
     */
    private function savedState(): array
    {
        $config = Config::loadFromDatabase();

        if ($config === null || !$config->isConfigured()) {
            return ['secondary', Text::_('PLG_CONTENT_CFPURGE_STATE_UNCONFIGURED')];
        }

        $state = $config->verifyState();

        if ($config->isVerified()) {
            return ['success', Text::sprintf('PLG_CONTENT_CFPURGE_STATE_VERIFIED', (string) ($state['at'] ?? ''), (string) ($state['user'] ?? ''))];
        }

        if (!empty($state['hash']) && hash_equals((string) $state['hash'], $config->fingerprint()) && empty($state['ok'])) {
            return ['danger', Text::sprintf('PLG_CONTENT_CFPURGE_STATE_FAILED', (string) ($state['at'] ?? ''))];
        }

        return ['warning', Text::_('PLG_CONTENT_CFPURGE_STATE_UNVERIFIED')];
    }
}
