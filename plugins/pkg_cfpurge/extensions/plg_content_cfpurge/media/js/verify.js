/**
 * Cloudflare Cache Purge: "Verify connection" button of the plugin options.
 * Sends the typed Zone ID and API token (or the saved ones) to the plugin through com_ajax
 * and shows the result. Text is always inserted with textContent, never as HTML.
 *
 * @copyright  (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
(() => {
  'use strict';

  const text = (key, fallback) => (window.Joomla && Joomla.Text && Joomla.Text._(key)) || fallback;

  const init = () => {
    const options = (window.Joomla && Joomla.getOptions('plg_content_cfpurge')) || {};

    document.querySelectorAll('.cfpurge-verify').forEach((box) => {
      const button = box.querySelector('.cfpurge-verify-btn');
      const list = box.querySelector('.cfpurge-checks');
      const state = box.querySelector('.cfpurge-state');
      const hidden = box.querySelector('input[type=hidden]');
      const useZone = box.querySelector('.cfpurge-use-zone');
      const zoneInput = document.getElementById(box.dataset.zoneField);
      const tokenInput = document.getElementById(box.dataset.tokenField);

      if (!button || !options.url) {
        return;
      }

      const labels = {
        ok: text('PLG_CONTENT_CFPURGE_STATUS_OK', 'OK'),
        warn: text('PLG_CONTENT_CFPURGE_STATUS_WARN', 'Note'),
        fail: text('PLG_CONTENT_CFPURGE_STATUS_FAIL', 'Error'),
      };

      const setState = (kind, message) => {
        state.className = 'cfpurge-state alert alert-' + kind;
        state.textContent = message;
      };

      const render = (data) => {
        list.textContent = '';
        (data.checks || []).forEach((check) => {
          const item = document.createElement('li');
          const badge = document.createElement('span');
          const title = document.createElement('strong');
          const message = document.createElement('span');

          item.className = 'cfpurge-check';
          badge.className = 'badge ' + (check.status === 'ok' ? 'bg-success' : check.status === 'warn' ? 'bg-warning text-dark' : 'bg-danger');
          badge.textContent = labels[check.status] || check.status;
          title.textContent = ' ' + check.title + ': ';
          message.textContent = check.message;
          item.append(badge, title, message);
          list.append(item);
        });

        setState(data.ok ? 'success' : 'danger', data.summary || '');

        if (hidden && data.state) {
          hidden.value = JSON.stringify(data.state);
        }

        useZone.hidden = true;

        if (data.suggested_zone_id && zoneInput) {
          if (zoneInput.value.trim() === '') {
            zoneInput.value = data.suggested_zone_id;
            zoneInput.dispatchEvent(new Event('change', { bubbles: true }));
          } else if (zoneInput.value.trim() !== data.suggested_zone_id) {
            useZone.hidden = false;
            useZone.onclick = () => {
              zoneInput.value = data.suggested_zone_id;
              zoneInput.dispatchEvent(new Event('change', { bubbles: true }));
              useZone.hidden = true;
              setState('info', text('PLG_CONTENT_CFPURGE_JS_ZONE_FILLED', 'Zone ID filled in. Save the plugin, then verify again.'));
            };
          }
        }
      };

      button.addEventListener('click', async () => {
        const body = new URLSearchParams();

        body.set(options.token, '1');
        body.set('action', 'verify');
        body.set('typed', '1');
        body.set('zone_id', zoneInput ? zoneInput.value : '');
        body.set('api_token', tokenInput ? tokenInput.value : '');

        button.disabled = true;
        list.textContent = '';
        setState('info', text('PLG_CONTENT_CFPURGE_JS_WORKING', 'Checking the connection with Cloudflare...'));

        try {
          const response = await fetch(options.url, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
          });
          const json = await response.json();

          if (!json.success) {
            setState('danger', json.message || text('PLG_CONTENT_CFPURGE_JS_ERROR', 'The check could not run.'));
          } else {
            render(Array.isArray(json.data) ? json.data[0] : json.data);
          }
        } catch (error) {
          setState('danger', text('PLG_CONTENT_CFPURGE_JS_ERROR', 'The check could not run.'));
        } finally {
          button.disabled = false;
        }
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
