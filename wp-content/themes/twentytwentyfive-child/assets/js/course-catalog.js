(function () {
  const container = document.querySelector('.itu-catalog__content');
  if (!container) return;

  const checkboxes = document.querySelectorAll('.itu-catalog__filter-checkbox');
  const loaded = {};   // slug -> HTML element (cached)
  const loading = {};  // slug -> true while fetching

  // Index server-rendered carousels by their category slug
  container.querySelectorAll('.itu-cat-row').forEach(function (row) {
    // Find the carousel's category from its "View All" link or eyebrow text
    const viewAll = row.querySelector('.itu-certs__card--viewall');
    const eyebrow = row.querySelector('.itu-cat-row__eyebrow');
    let slug = '';

    if (viewAll) {
      const match = viewAll.getAttribute('href').match(/\/product-category\/([^/]+)\//);
      if (match) slug = match[1];
    }

    if (!slug && eyebrow) {
      // Fallback: match by checkbox data-title
      const text = eyebrow.textContent.replace(/[\[\]]/g, '').trim();
      checkboxes.forEach(function (cb) {
        if (cb.dataset.title.trim() === text) slug = cb.value;
      });
    }

    if (slug) {
      loaded[slug] = row;
      row.dataset.catSlug = slug;
    }
  });

  checkboxes.forEach(function (cb) {
    cb.addEventListener('change', function () {
      var slug = cb.value;
      var title = cb.dataset.title;

      if (cb.checked) {
        show(slug, title);
      } else {
        hide(slug);
      }
    });
  });

  function scrollToRow(el) {
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function show(slug, title) {
    // Already in DOM — just reveal it at the top
    if (loaded[slug]) {
      loaded[slug].style.display = '';
      container.prepend(loaded[slug]);
      scrollToRow(loaded[slug]);
      return;
    }

    // Already fetching
    if (loading[slug]) return;
    loading[slug] = true;

    // Create a placeholder at the top
    var placeholder = document.createElement('div');
    placeholder.className = 'itu-cat-row itu-cat-row--loading';
    placeholder.dataset.catSlug = slug;
    placeholder.innerHTML = '<div class="itu-cat-row__header"><div><span class="itu-cat-row__eyebrow">[ ' + title + ' ]</span></div></div><div class="itu-cat-row__loading-spinner"></div>';
    container.prepend(placeholder);
    scrollToRow(placeholder);

    var url = (ituCatalog.restUrl || ituCatalog.ajaxUrl + '?action=itu_load_carousel') +
      (ituCatalog.restUrl ? '?' : '&') + 'category=' + encodeURIComponent(slug) + '&title=' + encodeURIComponent(title);
    fetch(url)
      .then(function (res) { return res.json(); })
      .then(function (data) {
        loading[slug] = false;
        if (data.success && data.data) {
          var temp = document.createElement('div');
          temp.innerHTML = data.data;
          var row = temp.querySelector('.itu-cat-row');
          if (row) {
            row.dataset.catSlug = slug;
            placeholder.replaceWith(row);
            loaded[slug] = row;
            initCarouselArrows(row);
            scrollToRow(row);
          } else {
            placeholder.remove();
          }
        } else {
          placeholder.remove();
        }
      })
      .catch(function () {
        loading[slug] = false;
        placeholder.remove();
      });
  }

  function hide(slug) {
    if (loaded[slug]) {
      loaded[slug].style.display = 'none';
    }
  }

  // Keep carousels in the same order as the sidebar checkboxes
  function reorder() {
    var slugOrder = [];
    checkboxes.forEach(function (cb) {
      if (cb.checked) slugOrder.push(cb.value);
    });

    slugOrder.forEach(function (slug) {
      var el = container.querySelector('[data-cat-slug="' + slug + '"]');
      if (el) container.appendChild(el);
    });
  }

  // Mobile slide-out drawer
  var toggle = document.querySelector('.itu-catalog__filter-toggle');
  var sidebar = document.querySelector('.itu-catalog__sidebar');
  var overlay = document.querySelector('.itu-catalog__overlay');
  var closeBtn = document.querySelector('.itu-catalog__sidebar-close');

  function openDrawer() {
    sidebar.classList.add('is-open');
    overlay.classList.add('is-active');
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    sidebar.classList.remove('is-open');
    overlay.classList.remove('is-active');
    document.body.style.overflow = '';
  }

  if (toggle) toggle.addEventListener('click', openDrawer);
  if (overlay) overlay.addEventListener('click', closeDrawer);
  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);

  // Re-init arrow listeners for AJAX-loaded carousels (mirrors cat-carousel.js logic)
  function initCarouselArrows(row) {
    var carousel = row.querySelector('.itu-cat-row__carousel');
    var prevBtn = row.querySelector('.itu-cat-row__arrow--prev');
    var nextBtn = row.querySelector('.itu-cat-row__arrow--next');
    if (!carousel || !prevBtn || !nextBtn) return;

    var card = carousel.querySelector('.itu-certs__card');
    if (!card) return;

    function getScrollAmount() {
      return card.offsetWidth + 24; // card width + gap
    }

    prevBtn.addEventListener('click', function () {
      carousel.scrollBy({ left: -getScrollAmount(), behavior: 'smooth' });
    });

    nextBtn.addEventListener('click', function () {
      carousel.scrollBy({ left: getScrollAmount(), behavior: 'smooth' });
    });
  }
})();
