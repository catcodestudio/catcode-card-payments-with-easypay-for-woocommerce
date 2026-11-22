/**
 * Admin glue for the EasyPay settings screen: the "Check keys" button.
 */
(() => {
  const cfg = window.catcodeEasyPayAdmin;
  if (!cfg) return;

  const post = (action, body) => {
    const params = new URLSearchParams(Object.assign({ action: 'catcode_easypay_' + action, nonce: cfg.nonce }, body || {}));
    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params
    }).then((r) => r.text().then((text) => {
      // A PHP fatal comes back as HTML: show it as a failure with the HTTP
      // code instead of a silent "network error".
      try {
        return JSON.parse(text);
      } catch (e) {
        return { success: false, data: { message: 'HTTP ' + r.status + ': ' + text.replace(/<[^>]*>/g, ' ').trim().slice(0, 200) } };
      }
    }));
  };

  const say = (selector, text, ok) => {
    const node = document.querySelector(selector);
    if (!node) return;
    node.textContent = text;
    node.className = 'description ' + (ok ? 'ezpy-ok' : 'ezpy-err');
  };

  const busy = (btn, on) => {
    if (!btn) return;
    btn.disabled = on;
    btn.classList.toggle('disabled', on);
  };

  const check = document.getElementById('ezpy-check');
  if (check) {
    check.addEventListener('click', () => {
      busy(check, true);
      post('check', {}).then((r) => {
        const data = r.data || {};
        say('#ezpy-check-result', data.message || '', !!r.success);
      }).catch(() => say('#ezpy-check-result', cfg.i18n.network, false))
        .finally(() => busy(check, false));
    });
  }
})();
