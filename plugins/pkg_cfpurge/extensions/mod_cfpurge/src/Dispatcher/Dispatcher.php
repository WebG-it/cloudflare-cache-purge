<?php

/**
 * @package     WebG.Module
 * @subpackage  mod_cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Module\Cfpurge\Administrator\Dispatcher;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use WebG\Plugin\Content\Cfpurge\Service\Access;
use WebG\Plugin\Content\Cfpurge\Service\Config;
use WebG\Plugin\Content\Cfpurge\Service\LastPurge;
use WebG\Plugin\Content\Cfpurge\Service\PurgeLog;
use WebG\Plugin\Content\Cfpurge\Service\Targets;

/**
 * Dashboard box of Cloudflare Cache Purge: traffic light, "clear everything" button, field
 * for some addresses, last purge. Nothing is printed for users who may not purge.
 *
 * @since  2.0.0
 */
class Dispatcher extends AbstractModuleDispatcher
{
    /**
     * @return  void
     *
     * @since   2.0.0
     */
    public function dispatch()
    {
        if (!class_exists(Config::class)) {
            return;
        }

        $config = Config::loadFromDatabase();

        if ($config === null || !Access::canPurge($this->getApplication()->getIdentity(), $config)) {
            return;
        }

        parent::dispatch();
    }

    /**
     * @return  array
     *
     * @since   2.0.0
     */
    protected function getLayoutData()
    {
        $data   = parent::getLayoutData();
        $config = Config::loadFromDatabase();
        $app    = $this->getApplication();

        $state = 'ok';
        $last  = PurgeLog::last('purge');

        if (!$config->isEnabled()) {
            $state = 'disabled';
        } elseif (!$config->isConfigured()) {
            $state = 'unconfigured';
        } elseif (!$config->isVerified()) {
            $saved = $config->verifyState();
            $state = (!empty($saved['hash']) && hash_equals((string) $saved['hash'], $config->fingerprint()) && empty($saved['ok'])) ? 'invalid' : 'unverified';
        } elseif ($last !== null && empty($last['ok'])) {
            $state = 'degraded';
        }

        $data['state']     = $state;
        $data['canPurge']  = $config->isEnabled() && $config->isVerified();
        $data['last']      = $last;
        $data['lastText']  = LastPurge::describe($last);
        $data['settings']  = 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $config->getExtensionId();
        $data['host']      = Targets::host();

        $document = $app->getDocument();
        $wa       = $document->getWebAssetManager();

        $wa->getRegistry()->addExtensionRegistryFile('mod_cfpurge');
        $wa->useScript('mod_cfpurge.box')->useStyle('mod_cfpurge.box');

        foreach (['CONFIRM_ALL', 'WORKING', 'ERROR', 'STATE_OK', 'STATE_INVALID'] as $key) {
            Text::script('MOD_CFPURGE_JS_' . $key);
        }

        $document->addScriptOptions('mod_cfpurge', [
            'url'   => 'index.php?option=com_ajax&group=content&plugin=cfpurge&format=json',
            'token' => Session::getFormToken(),
        ]);

        return $data;
    }
}
