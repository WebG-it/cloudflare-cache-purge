<?php

/**
 * Dev-only: runs the JED Checker "develop" branch (installed as com_jedchecker, it has
 * a CheckerApi class) on an extension zip from the command line and prints the
 * counts per rule, then every issue that is not plain information.
 *   php /dev-scripts/jedchecker_cli.php /var/www/html/tmp/plg_x.zip
 */

const _JEXEC = 1;
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/administrator/index.php';
$_SERVER['SCRIPT_NAME'] = '/administrator/index.php';
define('JPATH_BASE', '/var/www/html/administrator');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session.web', 'session.web.administrator')
    ->alias('session', 'session.web.administrator')
    ->alias('JSession', 'session.web.administrator')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.web.administrator')
    ->alias(\Joomla\Session\Session::class, 'session.web.administrator')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.administrator');
$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
\Joomla\CMS\Factory::$application = $app;
$lang = \Joomla\CMS\Language\Language::getInstance('en-GB');
$app->loadLanguage($lang);
\Joomla\CMS\Factory::$language = $lang;
$lang->load('com_jedchecker', JPATH_ADMINISTRATOR);
\JLoader::registerNamespace('Joomla\Component\Jedchecker\Administrator', JPATH_ADMINISTRATOR . '/components/com_jedchecker/src');

$r = \Joomla\Component\Jedchecker\Administrator\Api\CheckerApi::check($argv[1]);

if (!$r['success']) {
    echo 'ERROR ', $r['error'], "\n";
    exit(1);
}

$tot = [];
foreach ($r['rules'] as $rule) {
    $c = $rule['data']['count'] ?? [];
    foreach ($c as $k => $v) {
        $tot[$k] = ($tot[$k] ?? 0) + $v;
    }
    printf("%-5s %-55s %s\n", $rule['id'], \Joomla\CMS\Language\Text::_($rule['title']), json_encode($c));
}
echo 'TOTAL ', json_encode($tot), "\n";
foreach ($r['rules'] as $rule) {
    foreach ($rule['data']['issues'] ?? [] as $issue) {
        $type = $issue['type'] ?? '';
        if ($type !== 'info') {
            echo $rule['id'], ' ', $type, ' ', strip_tags(json_encode($issue, JSON_UNESCAPED_SLASHES)), "\n";
        }
    }
}
