<?php

/**
 * @package     WebG.Component
 * @subpackage  com_cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use WebG\Component\Cfpurge\Administrator\Extension\CfpurgeComponent;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the component (Joomla 4, 5 and 6 service provider).
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   2.1.0
     */
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\WebG\\Component\\Cfpurge'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\WebG\\Component\\Cfpurge'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new CfpurgeComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));

                return $component;
            }
        );
    }
};
