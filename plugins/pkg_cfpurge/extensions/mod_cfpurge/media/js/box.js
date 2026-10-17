/**
 * Cloudflare Cache Purge: dashboard box. The buttons talk to the "Content - Cloudflare Cache
 * Purge" plugin through com_ajax (POST with the form token). Text is always inserted with
 * textContent, never as HTML.
 *
 * @copyright  (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
(() => {
  'use strict';

  const text = (key, fallback) => (window.Joomla && Joomla.Text && Joomla.Text._(key)) || fallback;

  const init = () => {
    const options = (window.Joomla && Joomla.getOptions('mod_cfpurge')) || {};

    document.querySelectorAll('.mod-cfpurge').forEach((box) => {
      const result = box.querySelector('.mod-cfpurge__result');
      const urls = box.querySelector('.mod-cfpurge__urls');
      const lastText = box.querySelector('.mod-cfpurge__last-text');
      const stateBox = box.querySelector('.mod-cfpurge__state');
      const buttons = box.querySelectorAll('[data-action]');
      const actionButtons = box.querySelectorAll('[data-action="purge_all"], [data-action="purge_urls"]');

      if (!options.url) {
        return;
      }

      const say = (kind, message) => {
        result.hidden = false;
        result.className = 'mod-cfpurge__result alert alert-' + kind;
        result.textContent = message;
      };

      const verifyButton = box.querySelector('[data-action="verify"]');
      const verifyLocked = verifyButton ? verifyButton.disabled : true;
      const isVerified = () => (box.dataset.verified ? box.dataset.verified === '1' : ['ok', 'degraded'].includes(box.dataset.state));

      // Buttons that purge stay off until the connection is verified.
      const settle = () => {
        actionButtons.forEach((button) => { button.disabled = !isVerified(); });
        urls.disabled = !isVerified();

        if (verifyButton) {
          verifyButton.disabled = verifyLocked;
        }
      };

      const call = async (fields) => {
        const body = new URLSearchParams();

        body.set(options.token, '1');
        Object.entries(fields).forEach(([key, value]) => body.set(key, value));

        const response = await fetch(options.url, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const json = await response.json();

        if (!json.success) {
          throw new Error(json.message || text('MOD_CFPURGE_JS_ERROR', 'The request failed.'));
        }

        return Array.isArray(json.data) ? json.data[0] : json.data;
      };

      const showLast = (data) => {
        if (data && data.lastText) {
          lastText.textContent = data.lastText;
        }
      };

      buttons.forEach((button) => {
        button.addEventListener('click', async () => {
          const action = button.dataset.action;

          if (action === 'purge_all' && !window.confirm(text('MOD_CFPURGE_JS_CONFIRM_ALL', 'Clear the whole Cloudflare cache?'))) {
            return;
          }

          buttons.forEach((b) => { b.disabled = true; });
          say('info', text('MOD_CFPURGE_JS_WORKING', 'Working...'));

          try {
            const fields = { action };

            if (action === 'purge_urls') {
              fields.urls = urls.value;
            }

            const data = await call(fields);

            if (action === 'verify') {
              say(data.ok ? 'success' : 'danger', data.summary || '');
              stateBox.className = 'mod-cfpurge__state alert alert-' + (data.ok ? 'success' : 'danger');
              stateBox.querySelector('.mod-cfpurge__state-title').textContent = data.ok ? text('MOD_CFPURGE_JS_STATE_OK', 'Ready') : text('MOD_CFPURGE_JS_STATE_INVALID', 'Not working');
              stateBox.querySelector('.mod-cfpurge__state-text').textContent = data.summary || '';
              box.dataset.verified = data.ok ? '1' : '0';
            } else {
              say(data.ok ? 'success' : 'danger', data.message || '');
              showLast(data);
            }
          } catch (error) {
            say('danger', error.message || text('MOD_CFPURGE_JS_ERROR', 'The request failed.'));
          } finally {
            settle();
          }
        });
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
