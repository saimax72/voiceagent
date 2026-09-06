/* VoiceAgent dashboard helpers (no build step required) */
(function () {
  'use strict';
  const VA = window.VA || (window.VA = {});

  VA.toast = function (message, type) {
    const stack = document.getElementById('toasts');
    if (!stack) return;
    const el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.textContent = message;
    stack.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, 3500);
  };

  VA.api = async function (path, options) {
    options = options || {};
    const headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': VA.csrf }, options.headers || {});
    let body = options.body;
    if (body && !(body instanceof FormData) && typeof body === 'object') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    const res = await fetch((path.startsWith('http') ? path : VA.base + path), { method: options.method || (body ? 'POST' : 'GET'), headers, body, credentials: 'same-origin' });
    let data = null;
    const text = await res.text();
    try { data = text ? JSON.parse(text) : null; } catch (e) { data = { error: text || 'Unexpected response' }; }
    if (!res.ok) {
      const err = new Error((data && data.error) || ('Request failed (' + res.status + ')'));
      err.status = res.status; err.data = data;
      throw err;
    }
    return data;
  };

  VA.copy = async function (text, button) {
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      const ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (err) { /* ignore */ }
      ta.remove();
    }
    if (button) {
      const original = button.innerHTML;
      button.innerHTML = 'Copied!';
      button.classList.add('btn-success');
      setTimeout(() => { button.innerHTML = original; button.classList.remove('btn-success'); }, 1600);
    }
    VA.toast('Copied to clipboard', 'success');
  };

  VA.confirmForms = function () {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      form.addEventListener('submit', (e) => {
        if (!window.confirm(form.dataset.confirm)) e.preventDefault();
      });
    });
    document.querySelectorAll('[data-copy]').forEach((btn) => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const target = btn.dataset.copy.startsWith('#') ? document.querySelector(btn.dataset.copy) : null;
        const text = target ? (target.value !== undefined && target.tagName !== 'PRE' ? target.value : target.textContent) : btn.dataset.copy;
        VA.copy(text.trim(), btn);
      });
    });
  };

  /* Poll a background job and update a progress UI. onDone(job) is called when finished. */
  VA.pollJob = function (jobId, handlers) {
    handlers = handlers || {};
    let stopped = false;
    let ticking = false;
    const tick = async () => {
      if (stopped) return;
      try {
        const job = await VA.api('/api/jobs/' + jobId);
        if (handlers.onProgress) handlers.onProgress(job);
        if (job.status === 'completed') { stopped = true; if (handlers.onDone) handlers.onDone(job); return; }
        if (job.status === 'failed' || job.status === 'cancelled') { stopped = true; if (handlers.onError) handlers.onError(job); return; }
        // No cron worker? Drive the job from the browser.
        if (job.needs_tick && !ticking) {
          ticking = true;
          try { await VA.api('/api/jobs/' + jobId + '/tick', { method: 'POST', body: {} }); } catch (e) { /* retry on next poll */ }
          ticking = false;
          setTimeout(tick, 400);
          return;
        }
      } catch (e) {
        if (handlers.onError) handlers.onError({ last_error: e.message });
      }
      setTimeout(tick, 2500);
    };
    tick();
    return { stop: () => { stopped = true; } };
  };

  VA.debounce = function (fn, wait) {
    let t; return function () { clearTimeout(t); const args = arguments; t = setTimeout(() => fn.apply(this, args), wait); };
  };

  VA.escapeHtml = function (s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  };

  document.addEventListener('DOMContentLoaded', VA.confirmForms);
})();
