<?php

/**
 * @package     WebG.Component
 * @subpackage  com_cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Component\Cfpurge\Api\Serializer;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Tobscure\JsonApi\AbstractSerializer;

/**
 * Shows a plain array as a JSON:API resource of type "cfpurge": ['id' => ..., 'attributes' => [...]].
 * Nothing else is added: the answers of this API carry only the facts built by the controller.
 *
 * @since  2.1.0
 */
final class ResultSerializer extends AbstractSerializer
{
    /**
     * @var    string
     * @since  2.1.0
     */
    protected $type = 'cfpurge';

    /**
     * @param   array  $model  The resource.
     *
     * @return  string
     *
     * @since   2.1.0
     */
    public function getId($model)
    {
        return (string) $model['id'];
    }

    /**
     * @param   array       $model   The resource.
     * @param   array|null  $fields  Not used.
     *
     * @return  array
     *
     * @since   2.1.0
     */
    public function getAttributes($model, ?array $fields = null)
    {
        return (array) $model['attributes'];
    }
}
