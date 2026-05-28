window.SapiencialField = window.SapiencialField || {
  init(config) {
    const input = document.querySelector(config.searchInputSelector);
    const hidden = document.querySelector(config.hiddenInputSelector);
    const results = document.querySelector(config.resultsSelector);
    const preview = document.querySelector(config.previewSelector);
    if (!input || !hidden || !results || !preview) return;

    let timer = null;

    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) {
        results.innerHTML = '';
        return;
      }

      timer = setTimeout(async () => {
        try {
          const body = new FormData();
          body.append(config.csrfTokenName, config.csrfTokenValue);
          body.append('type', config.type);
          body.append('q', q);
          body.append('site', config.site);

          const res = await fetch(config.actionUrl, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
          });

          const json = await res.json();
          if (!res.ok || json.error) {
            const errorMessage = json.error || `HTTP ${res.status}`;
            results.innerHTML = `<div class="warning" style="margin-top:6px;">Search error: ${errorMessage}</div>`;
            return;
          }

          const items = json.items || [];
          if (!items.length) {
            results.innerHTML = '<div class="light" style="margin-top:6px;">No results.</div>';
            return;
          }

          results.innerHTML = items.map((item, index) => (
            `<button type="button" class="btn small" data-index="${index}" style="margin:0 6px 6px 0;">${item.title} (#${item.id})</button>`
          )).join('');

          results.querySelectorAll('button[data-index]').forEach((btn) => {
            btn.addEventListener('click', () => {
              const item = items[Number(btn.getAttribute('data-index'))] || null;
              if (!item) return;
              const value = {
                type: config.type,
                remoteId: item.id,
                slug: item.slug || '',
                title: item.title || '',
                site: config.site || '',
              };
              hidden.value = JSON.stringify(value);
              preview.textContent = `${value.title} (#${value.remoteId})`;
              results.innerHTML = '';
              input.value = '';
            });
          });
        } catch (error) {
          results.innerHTML = `<div class="warning" style="margin-top:6px;">Search request failed.</div>`;
        }
      }, 250);
    });
  }
};
