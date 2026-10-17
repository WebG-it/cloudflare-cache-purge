<?php

/**
 * @package     WebG.Package
 * @subpackage  pkg_cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * Installation script of the Cloudflare Cache Purge package: checks PHP and Joomla, turns
 * on what is new (the plugins, the box on the administrator home page) and removes the
 * log files when the package is uninstalled. Installing over the old standalone plugin
 * 1.1.4 is an in-place upgrade: Zone ID, token and switches are kept.
 *
 * @since  2.0.0
 */
class Pkg_CfpurgeInstallerScript extends InstallerScript
{
    /**
     * @var    string
     * @since  2.0.0
     */
    protected $minimumPhp = '8.1.0';

    /**
     * @var    string
     * @since  2.0.0
     */
    protected $minimumJoomla = '5.0.0';

    /**
     * What was installed before this run: only what is new gets switched on.
     *
     * @var    array
     * @since  2.0.0
     */
    private array $had = [];

    /**
     * @param   string            $type    install, update or discover_install.
     * @param   InstallerAdapter  $parent  The installer adapter.
     *
     * @return  boolean
     *
     * @since   2.0.0
     */
    public function preflight($type, $parent)
    {
        if (!parent::preflight($type, $parent)) {
            return false;
        }

        if ($type !== 'uninstall') {
            $this->had = [
                'content' => $this->extensionId('plugin', 'cfpurge', 'content') > 0,
                'system'  => $this->extensionId('plugin', 'cfpurge', 'system') > 0,
                'module'  => $this->extensionId('module', 'mod_cfpurge') > 0,
                'webservices' => $this->extensionId('plugin', 'cfpurge', 'webservices') > 0,
            ];
        }

        return true;
    }

    /**
     * @param   string            $type    install, update or discover_install.
     * @param   InstallerAdapter  $parent  The installer adapter.
     *
     * @return  boolean
     *
     * @since   2.0.0
     */
    public function postflight($type, $parent)
    {
        if ($type === 'uninstall') {
            return true;
        }

        // Switch on what is new; an extension that was already there keeps its state.
        if (empty($this->had['content'])) {
            $this->enable('plugin', 'cfpurge', 'content');
        }

        if (empty($this->had['system'])) {
            $this->enable('plugin', 'cfpurge', 'system');
        }

        // The API plugin is inert until a Super User gives somebody the permission in the options of
        // com_cfpurge and that person has a Joomla API token: switching it on is safe.
        if (empty($this->had['webservices'])) {
            $this->enable('plugin', 'cfpurge', 'webservices');
        }

        if (empty($this->had['module'])) {
            $this->createModule();
        }

        $app = Factory::getApplication();
        $app->getLanguage()->load('pkg_cfpurge.sys', JPATH_ADMINISTRATOR);

        $key  = !empty($this->had['content']) ? 'PKG_CFPURGE_POSTINSTALL_UPGRADE' : 'PKG_CFPURGE_POSTINSTALL_NEW';
        $text = Text::_($key);

        $app->enqueueMessage($text !== $key ? $text : 'Cloudflare Cache Purge is installed. Open System > Plugins > Content - Cloudflare Cache Purge and click Verify connection.', 'message');

        return true;
    }

    /**
     * Removes the log files of the plugin: the uninstall leaves nothing behind.
     *
     * @param   InstallerAdapter  $parent  The installer adapter.
     *
     * @return  boolean
     *
     * @since   2.0.0
     */
    public function uninstall($parent)
    {
        $dir = JPATH_ADMINISTRATOR . '/logs';

        try {
            $dir = (string) Factory::getApplication()->get('log_path', $dir);
        } catch (\Throwable $e) {
            // Keep the default folder.
        }

        foreach (['plg_content_cfpurge.php', 'plg_content_cfpurge.1.php'] as $name) {
            $file = rtrim($dir, '/\\') . '/' . $name;

            if (is_file($file)) {
                @unlink($file);
            }
        }

        return true;
    }

    /**
     * @param   string  $type     plugin or module.
     * @param   string  $element  The element.
     * @param   string  $folder   The plugin group.
     *
     * @return  integer  The extension id, 0 when not installed.
     *
     * @since   2.0.0
     */
    private function extensionId(string $type, string $element, string $folder = ''): int
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote($type))
            ->where($db->quoteName('element') . ' = ' . $db->quote($element));

        if ($folder !== '') {
            $query->where($db->quoteName('folder') . ' = ' . $db->quote($folder));
        }

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * @param   string  $type     plugin.
     * @param   string  $element  The element.
     * @param   string  $folder   The plugin group.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function enable(string $type, string $element, string $folder): void
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote($type))
            ->where($db->quoteName('element') . ' = ' . $db->quote($element))
            ->where($db->quoteName('folder') . ' = ' . $db->quote($folder));

        $db->setQuery($query)->execute();
    }

    /**
     * Puts the box on the administrator home page: position cpanel, published, on every page.
     * The Joomla installer already adds an unpublished instance without position for an
     * administrator module: that one is used; if there is none, one is created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createModule(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_cfpurge'))
            ->where($db->quoteName('client_id') . ' = 1')
            ->order($db->quoteName('id'));

        $id = (int) $db->setQuery($query, 0, 1)->loadResult();

        $values = [
            'title'     => 'Cloudflare Cache Purge',
            'note'      => '',
            'ordering'  => 0,
            'position'  => 'cpanel',
            'published' => 1,
            'access'    => 3,
            'showtitle' => 1,
            'params'    => '{"layout":"_:default","moduleclass_sfx":"","cache":"0","bootstrap_size":"12","header_tag":"h2"}',
            'language'  => '*',
        ];

        if ($id > 0) {
            $values['id'] = $id;
            $row          = (object) $values;
            $db->updateObject('#__modules', $row, 'id');
        } else {
            $row = (object) array_merge($values, ['content' => '', 'module' => 'mod_cfpurge', 'client_id' => 1]);
            $db->insertObject('#__modules', $row, 'id');
            $id = (int) $row->id;
        }

        // On every page (menu id 0 = all pages).
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__modules_menu'))
            ->where($db->quoteName('moduleid') . ' = ' . $id);

        if ((int) $db->setQuery($query)->loadResult() === 0) {
            $link = (object) ['moduleid' => $id, 'menuid' => 0];
            $db->insertObject('#__modules_menu', $link);
        }
    }
}
