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

use Joomla\CMS\Categories\Categories;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Builds the public addresses to purge in the targeted mode: article, category and its
 * parents, home page and the fixed extra addresses. Every method returns null when an
 * address cannot be worked out: the caller then purges everything.
 *
 * @since  2.0.0
 */
final class Targets
{
    /**
     * Site root with the trailing slash, for example https://www.example.com/.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public static function root(): string
    {
        return rtrim(Uri::root(), '/') . '/';
    }

    /**
     * The host name of the public site.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public static function host(): string
    {
        return strtolower((string) Uri::getInstance(self::root())->getHost());
    }

    /**
     * Turns what the user typed into an absolute address of this site: a path such as
     * /sitemap.xml is completed with the domain, an absolute address must be on this
     * site (same host), anything else is refused.
     *
     * @param   string  $input  The address as typed.
     *
     * @return  string|null  The address, or null when it is not valid for this site.
     *
     * @since   2.0.0
     */
    public static function normalise(string $input): ?string
    {
        $input = trim($input);

        if ($input === '' || strlen($input) > 2000 || preg_match('/[\s[:cntrl:]<>"\']/', $input)) {
            return null;
        }

        // Fragments are never sent to the server.
        $input = (string) preg_replace('/#.*$/', '', $input);

        if ($input === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $input)) {
            $uri = Uri::getInstance($input);

            if (strtolower((string) $uri->getHost()) !== self::host() || $uri->getUser() !== null && $uri->getUser() !== '') {
                return null;
            }

            return $input;
        }

        if ($input[0] === '/' && !str_starts_with($input, '//')) {
            return rtrim(self::root(), '/') . $input;
        }

        return null;
    }

    /**
     * Fixed extra addresses configured by the administrator.
     *
     * @param   Config  $config  The plugin settings.
     *
     * @return  string[]
     *
     * @since   2.0.0
     */
    public static function extras(Config $config): array
    {
        $urls = [];

        foreach ($config->extraUrls() as $line) {
            $url = self::normalise($line);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Public address of an article.
     *
     * The alias is passed to the router together with the id (1:my-alias): the router then does not
     * read it again and does not reuse an address it built earlier in the same request (it caches
     * them), so the old and the new address of a renamed article are both right.
     *
     * @param   int     $id        The article id.
     * @param   int     $catid     The category id.
     * @param   string  $language  The article language.
     * @param   string  $alias     The article alias (empty = let the router read it).
     *
     * @return  string|null
     *
     * @since   2.0.0
     */
    public static function article(int $id, int $catid, string $language = '*', string $alias = ''): ?string
    {
        $slug = $alias !== '' && preg_match('/^[^\s:&?#\/]+$/u', $alias) ? $id . ':' . $alias : $id;

        return self::route(RouteHelper::getArticleRoute($slug, $catid, $language === '' ? '*' : $language));
    }

    /**
     * Public addresses of a category and of its parents (without the root category).
     *
     * @param   int     $catid     The category id.
     * @param   string  $language  The language.
     *
     * @return  string[]|null
     *
     * @since   2.0.0
     */
    public static function categories(int $catid, string $language = '*'): ?array
    {
        $urls = [];

        try {
            $category = Categories::getInstance('Content', ['access' => false])->get($catid);
        } catch (\Throwable $e) {
            return null;
        }

        // The category may be unpublished or trashed (not loaded by the helper): then it
        // has no public page and only the lists that show it matter, handled by the caller.
        // The root node has the id "root" (not a number): stop there.
        while ($category && is_numeric($category->id) && (int) $category->id > 1) {
            $url = self::route(RouteHelper::getCategoryRoute((int) $category->id, $language === '' ? '*' : $language));

            if ($url === null) {
                return null;
            }

            $urls[]   = $url;
            $category = $category->getParent();
        }

        return $urls;
    }

    /**
     * Public addresses of the articles with the given ids (current state in the database).
     *
     * @param   int[]  $ids  Article ids.
     *
     * @return  string[]|null  Null when an address cannot be built.
     *
     * @since   2.0.0
     */
    public static function articlesById(array $ids): ?array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = Db::query($db)
            ->select($db->quoteName(['id', 'catid', 'language', 'alias']))
            ->from($db->quoteName('#__content'))
            ->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER);
        $rows = $db->setQuery($query)->loadObjectList();

        $urls = [];

        foreach ($rows as $row) {
            $url = self::article((int) $row->id, (int) $row->catid, (string) $row->language, (string) $row->alias);

            if ($url === null) {
                return null;
            }

            $urls[] = $url;

            $cats = self::categories((int) $row->catid, (string) $row->language);

            if ($cats === null) {
                return null;
            }

            $urls = array_merge($urls, $cats);
        }

        return $urls;
    }

    /**
     * Calculates the public address of a Joomla route. Returns null when it is not an
     * address of this site, so the caller falls back to purging everything.
     *
     * @param   string  $route  Internal route, for example index.php?option=com_content&view=article&id=1.
     *
     * @return  string|null
     *
     * @since   2.0.0
     */
    private static function route(string $route): ?string
    {
        try {
            $url = (string) Route::link('site', $route, false, Route::TLS_IGNORE, true);
        } catch (\Throwable $e) {
            return null;
        }

        // The address must be on the public site and never in the administrator.
        if ($url === '' || !str_starts_with($url, 'http') || str_contains($url, '/administrator')) {
            return null;
        }

        if (strtolower((string) Uri::getInstance($url)->getHost()) !== self::host()) {
            return null;
        }

        return $url;
    }
}
