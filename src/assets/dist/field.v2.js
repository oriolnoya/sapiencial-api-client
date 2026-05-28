window.SapiencialField = window.SapiencialField || {
  init(config) {
    const input = document.querySelector(config.searchInputSelector);
    const hidden = document.querySelector(config.hiddenInputSelector);
    const results = document.querySelector(config.resultsSelector);
    const preview = document.querySelector(config.previewSelector);
    if (!input || !hidden || !results || !preview) return;

    let allItems = [];
    let isLoading = false;
    let didLoad = false;

    const renderItems = (items) => {
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
          input.value = value.title;
          renderFiltered();
        });
      });
    };

    const renderFiltered = () => {
      const q = input.value.trim().toLowerCase();
      if (!q) {
        renderItems(allItems);
        return;
      }

      const filtered = allItems.filter((item) => {
        const title = (item.title || '').toLowerCase();
        const slug = (item.slug || '').toLowerCase();
        const id = String(item.id || '');
        return title.includes(q) || slug.includes(q) || id.includes(q);
      });
      renderItems(filtered);
    };

    const loadItems = async () => {
      if (didLoad || isLoading) return;
      isLoading = true;
      results.innerHTML = '<div class="light" style="margin-top:6px;">Loading items...</div>';

      try {
        const body = new FormData();
        body.append(config.csrfTokenName, config.csrfTokenValue);
        body.append('type', config.type);
        body.append('q', '');
        body.append('site', config.site);
        body.append('limit', '500');

        const res = await fetch(config.actionUrl, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        const json = await res.json();
        if (!res.ok || json.error) {
          const errorMessage = json.error || `HTTP ${res.status}`;
          results.innerHTML = `<div class="warning" style="margin-top:6px;">List load error: ${errorMessage}</div>`;
          return;
        }

        allItems = json.items || [];
        didLoad = true;
        renderFiltered();
      } catch (error) {
        results.innerHTML = '<div class="warning" style="margin-top:6px;">List request failed.</div>';
      } finally {
        isLoading = false;
      }
    };

    input.addEventListener('focus', () => {
      loadItems();
    });

    input.addEventListener('input', () => {
      if (!didLoad) {
        loadItems();
        return;
      }
      renderFiltered();
    });
  }
};
