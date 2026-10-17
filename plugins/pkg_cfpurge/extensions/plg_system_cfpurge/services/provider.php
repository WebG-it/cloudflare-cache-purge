<?php

/**
 * @package     WebG.Plugin
 * @subpackage  System.cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use WebG\Plugin\System\Cfpurge\Extension\Cfpurge;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the plugin (Joomla 5 and 6 service provider).
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Cfpurge(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'cfpurge')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
