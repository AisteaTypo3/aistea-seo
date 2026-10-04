/* Aistea SEO-Checker frontend: starts an audit and drives it step by step via the JSON endpoint. */
(() => {
  'use strict';

  const root = document.querySelector('[data-aseo]');
  if (!root) {
    return;
  }

  const endpoint = root.dataset.endpoint || '/_seo-checker/';
  const pageUrl = root.dataset.pageUrl || window.location.pathname;
  let labels = {};
  try {
    labels = JSON.parse(root.dataset.labels || '{}');
  } catch (e) {
    labels = {};
  }
  const PHASES = ['site', 'crawl', 'links', 'performance', 'finalize'];

  const post = async (action, data) => {
    const response = await fetch(endpoint + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
      body: new URLSearchParams(data),
      credentials: 'same-origin',
    });
    let payload = {};
    try {
      payload = await response.json();
    } catch (e) {
      payload = { error: 'generic', message: labels.generic };
    }
    if (!response.ok && !payload.message) {
      payload.message = labels.generic;
    }
    return { ok: response.ok, status: response.status, data: payload };
  };

  const sleep = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

  // ── Form ────────────────────────────────────────────
  const form = root.querySelector('[data-aseo-form]');
  if (form) {
    const input = form.querySelector('input[name="url"]');
    const button = form.querySelector('button[type="submit"]');
    const errorBox = form.querySelector('[data-aseo-error]');
    const submitLabel = form.querySelector('[data-aseo-submit-label]');
    const originalLabel = submitLabel ? submitLabel.textContent : '';

    const showError = (message) => {
      errorBox.textContent = message;
      errorBox.hidden = false;
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      errorBox.hidden = true;
      const value = input.value.trim();
      if (value.length < 4 || !/\./.test(value)) {
        showError(labels.invalidUrl);
        input.focus();
        return;
      }

      form.classList.add('is-loading');
      button.disabled = true;
      input.readOnly = true;
      if (submitLabel) {
        submitLabel.textContent = labels.starting;
      }

      try {
        const result = await post('start', {
          url: value,
          formToken: form.elements.formToken.value,
          website: form.elements.website.value,
        });
        if (!result.ok || !result.data.token) {
          throw new Error(result.data.message || labels.generic);
        }
        window.location.href = pageUrl + '?audit=' + encodeURIComponent(result.data.token);
      } catch (error) {
        showError(error instanceof TypeError ? labels.network : error.message);
        form.classList.remove('is-loading');
        button.disabled = false;
        input.readOnly = false;
        if (submitLabel) {
          submitLabel.textContent = originalLabel;
        }
      }
    });
  }

  // ── Progress ────────────────────────────────────────
  const progress = root.querySelector('[data-aseo-progress]');
  if (progress && root.dataset.token) {
    const bar = progress.querySelector('[data-aseo-bar]');
    const barWrap = progress.querySelector('[role="progressbar"]');
    const phaseLabel = progress.querySelector('[data-aseo-phase]');
    const percent = progress.querySelector('[data-aseo-percent]');
    const current = progress.querySelector('[data-aseo-current]');
    const errorBox = progress.querySelector('[data-aseo-error]');
    const phaseItems = progress.querySelectorAll('[data-phase]');

    const render = (state) => {
      const value = Math.max(3, Math.min(100, Number(state.progress) || 0));
      bar.style.width = value + '%';
      barWrap.setAttribute('aria-valuenow', String(value));
      percent.textContent = value + ' %';
      if (state.phaseLabel) {
        phaseLabel.textContent = state.phaseLabel;
      }
      if (typeof state.current === 'string') {
        current.textContent = state.current;
      }
      const index = PHASES.indexOf(state.phase === 'done' ? 'finalize' : state.phase);
      phaseItems.forEach((item, i) => {
        item.classList.toggle('is-done', i < index || state.status === 'done');
        item.classList.toggle('is-active', i === index && state.status !== 'done');
      });
    };

    render({ progress: root.dataset.progress, phase: root.dataset.phase || 'site', status: 'running' });

    const run = async () => {
      let failures = 0;
      for (;;) {
        let result;
        try {
          result = await post('step', { token: root.dataset.token });
        } catch (e) {
          result = null;
        }

        if (!result || (!result.ok && result.status >= 500)) {
          failures++;
          if (failures >= 5) {
            errorBox.textContent = labels.network;
            errorBox.hidden = false;
            return;
          }
          await sleep(2000 * failures);
          continue;
        }
        failures = 0;

        if (!result.ok) {
          errorBox.textContent = result.data.message || labels.generic;
          errorBox.hidden = false;
          return;
        }

        const state = result.data;
        if (state.status === 'busy') {
          current.textContent = labels.busy;
          await sleep(2000);
          continue;
        }
        render(state);

        if (state.status === 'done') {
          phaseLabel.textContent = labels.finishing;
          await sleep(400);
          window.location.reload();
          return;
        }
        if (state.status === 'failed') {
          errorBox.textContent = state.error || labels.generic;
          errorBox.hidden = false;
          return;
        }
        await sleep(250);
      }
    };
    run();
  }

  // ── Report actions ──────────────────────────────────
  root.querySelectorAll('[data-aseo-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const label = button.querySelector('[data-aseo-copy-label]');
      const original = label.textContent;
      try {
        await navigator.clipboard.writeText(button.dataset.aseoCopy);
        label.textContent = labels.copied;
      } catch (e) {
        label.textContent = labels.copyFailed;
      }
      window.setTimeout(() => {
        label.textContent = original;
      }, 2200);
    });
  });

  const openAll = () => root.querySelectorAll('details').forEach((details) => {
    details.open = true;
  });
  root.querySelectorAll('[data-aseo-print]').forEach((button) => {
    button.addEventListener('click', () => {
      openAll();
      window.print();
    });
  });
  window.addEventListener('beforeprint', openAll);

  // Jump links (priorities, deep links) open the targeted check.
  const openTarget = () => {
    const id = decodeURIComponent(window.location.hash.slice(1));
    const target = id ? document.getElementById(id) : null;
    if (target && target.tagName === 'DETAILS') {
      target.open = true;
    }
  };
  window.addEventListener('hashchange', openTarget);
  openTarget();
})();
