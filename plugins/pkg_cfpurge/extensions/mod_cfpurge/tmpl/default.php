<?php

/**
 * @package     WebG.Module
 * @subpackage  mod_cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\Text;

/** @var string $state     ok, degraded, unverified, invalid, unconfigured or disabled */
/** @var bool   $canPurge  The buttons are enabled */
/** @var string $lastText  One line about the last purge */
/** @var string $settings  Link to the plugin options */
/** @var string $host      Host name of the public site */

$stateClass = match ($state) {
    'ok'                  => 'success',
    'degraded'            => 'warning',
    'unverified'          => 'warning',
    'invalid'             => 'danger',
    default               => 'secondary',
};
?>
<div class="mod-cfpurge" data-state="<?php echo htmlspecialchars($state, ENT_QUOTES); ?>" data-settings="<?php echo htmlspecialchars($settings, ENT_QUOTES); ?>">
    <p class="mod-cfpurge__state alert alert-<?php echo $stateClass; ?>" role="status">
        <span class="mod-cfpurge__dot" aria-hidden="true"></span>
        <strong class="mod-cfpurge__state-title"><?php echo Text::_('MOD_CFPURGE_STATE_' . strtoupper($state) . '_TITLE'); ?></strong>
        <span class="mod-cfpurge__state-text"><?php echo Text::_('MOD_CFPURGE_STATE_' . strtoupper($state) . '_TEXT'); ?></span>
    </p>

    <div class="mod-cfpurge__block">
        <button type="button" class="btn btn-danger mod-cfpurge__btn" data-action="purge_all"<?php echo $canPurge ? '' : ' disabled'; ?>>
            <?php echo Text::_('MOD_CFPURGE_BUTTON_ALL'); ?>
        </button>
    </div>

    <div class="mod-cfpurge__block">
        <label for="mod-cfpurge-urls-<?php echo (int) $module->id; ?>" class="form-label"><?php echo Text::_('MOD_CFPURGE_URLS_LABEL'); ?></label>
        <textarea id="mod-cfpurge-urls-<?php echo (int) $module->id; ?>" class="form-control mod-cfpurge__urls" rows="3" spellcheck="false" autocomplete="off"
            placeholder="<?php echo htmlspecialchars("/" . Text::_('MOD_CFPURGE_URLS_PLACEHOLDER_PATH') . "\nhttps://" . $host . "/...", ENT_QUOTES); ?>"<?php echo $canPurge ? '' : ' disabled'; ?>></textarea>
        <div class="form-text"><?php echo Text::sprintf('MOD_CFPURGE_URLS_HINT', htmlspecialchars($host, ENT_QUOTES)); ?></div>
        <button type="button" class="btn btn-primary mod-cfpurge__btn mt-2" data-action="purge_urls"<?php echo $canPurge ? '' : ' disabled'; ?>>
            <?php echo Text::_('MOD_CFPURGE_BUTTON_URLS'); ?>
        </button>
    </div>

    <p class="mod-cfpurge__result" role="status" aria-live="polite" hidden></p>

    <p class="mod-cfpurge__last small text-muted">
        <strong><?php echo Text::_('MOD_CFPURGE_LAST_LABEL'); ?></strong>
        <span class="mod-cfpurge__last-text"><?php echo $lastText !== '' ? htmlspecialchars($lastText, ENT_QUOTES) : Text::_('MOD_CFPURGE_LAST_NONE'); ?></span>
    </p>

    <p class="mod-cfpurge__footer">
        <button type="button" class="btn btn-secondary btn-sm mod-cfpurge__btn" data-action="verify"<?php echo $state === 'disabled' || $state === 'unconfigured' ? ' disabled' : ''; ?>>
            <?php echo Text::_('MOD_CFPURGE_BUTTON_VERIFY'); ?>
        </button>
        <a class="btn btn-link btn-sm" href="<?php echo htmlspecialchars($settings, ENT_QUOTES); ?>"><?php echo Text::_('MOD_CFPURGE_SETTINGS'); ?></a>
    </p>
</div>
