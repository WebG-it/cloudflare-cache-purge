<?php

/**
 * @package     WebG.Plugin
 * @subpackage  WebServices.cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Plugin\WebServices\Cfpurge\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;

/**
 * Registers the routes of Cloudflare Cache Purge in the Joomla Web Services API. The
 * routes are answered by the component com_cfpurge, which checks the permissions.
 *
 * @since  2.1.0
 */
final class Cfpurge extends CMSPlugin implements SubscriberInterface
{
    /**
     * @return  array
     *
     * @since   2.1.0
     */
    public static function getSubscribedEvents(): array
    {
        return ['onBeforeApiRoute' => 'onBeforeApiRoute'];
    }

    /**
     * @param   Event  $event  The onBeforeApiRoute event.
     *
     * @return  void
     *
     * @since   2.1.0
     */
    public function onBeforeApiRoute(Event $event): void
    {
        // Joomla 5 and 6 pass an event object with getRouter(); Joomla 4 passes the router as the first argument.
        $router = method_exists($event, 'getRouter') ? $event->getRouter() : ($event->getArgument('router') ?? $event->getArgument(0));

        if (!\is_object($router)) {
            return;
        }

        $get  = ['component' => 'com_cfpurge', 'public' => false];
        $post = ['component' => 'com_cfpurge'];

        $router->addRoutes([
            new Route(['GET'], 'v1/cfpurge/status', 'purge.status', [], $get),
            new Route(['POST'], 'v1/cfpurge/all', 'purge.all', [], $post),
            new Route(['POST'], 'v1/cfpurge/urls', 'purge.urls', [], $post),
        ]);
    }
}
