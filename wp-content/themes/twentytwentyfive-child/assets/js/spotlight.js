document.addEventListener('DOMContentLoaded', function () {
  var section = document.querySelector('.itu-spotlight');
  if (!section) return;

  var cards = section.querySelectorAll('.itu-certs__card');
  var logo = section.querySelector('.itu-spotlight__logo img');
  var logoWrap = section.querySelector('.itu-spotlight__logo');
  var title = section.querySelector('.itu-spotlight__title');
  var desc = section.querySelector('.itu-spotlight__description');

  if (!cards.length || !title || !desc) return;

  var defaultLogo = logo ? logo.src : '';
  var defaultTitle = title.textContent;
  var defaultDesc = desc.textContent;

  function updatePanel(cardLogo, cardTitle, cardDesc) {
    if (logo && cardLogo) {
      logo.style.opacity = '0';
      setTimeout(function () {
        logo.src = cardLogo;
        logo.alt = cardTitle;
        logo.style.opacity = '1';
      }, 150);
      if (logoWrap) logoWrap.style.display = '';
    } else if (logoWrap && !cardLogo) {
      logoWrap.style.display = 'none';
    }
    title.textContent = cardTitle;
    desc.textContent = cardDesc;
  }

  cards.forEach(function (card) {
    card.addEventListener('mouseenter', function () {
      cards.forEach(function (c) { c.classList.remove('is-active'); });
      card.classList.add('is-active');
      updatePanel(card.dataset.spotlightLogo, card.dataset.spotlightTitle, card.dataset.spotlightDesc);
    });
  });

  section.addEventListener('mouseleave', function () {
    cards.forEach(function (c) { c.classList.remove('is-active'); });
    if (logo) {
      logo.style.opacity = '0';
      setTimeout(function () {
        logo.src = defaultLogo;
        logo.alt = '';
        logo.style.opacity = '1';
      }, 150);
      if (logoWrap) logoWrap.style.display = defaultLogo ? '' : 'none';
    }
    title.textContent = defaultTitle;
    desc.textContent = defaultDesc;
  });

  // Auto-scroll
  var track = section.querySelector('.itu-spotlight__track');
  if (!track || cards.length < 4) return;

  var trackHTML = track.innerHTML;
  track.innerHTML = trackHTML + trackHTML;

  var scrollSpeed = 0.5;
  var scrollPos = 0;
  var halfWidth = track.scrollWidth / 2;
  var isPaused = false;

  function autoScroll() {
    if (!isPaused) {
      scrollPos += scrollSpeed;
      if (scrollPos >= halfWidth) scrollPos = 0;
      track.style.transform = 'translateX(-' + scrollPos + 'px)';
    }
    requestAnimationFrame(autoScroll);
  }

  track.addEventListener('mouseenter', function () { isPaused = true; });
  track.addEventListener('mouseleave', function () { isPaused = false; });

  // Re-bind hover to duplicated cards
  var allCards = track.querySelectorAll('.itu-certs__card');
  allCards.forEach(function (card) {
    card.addEventListener('mouseenter', function () {
      allCards.forEach(function (c) { c.classList.remove('is-active'); });
      card.classList.add('is-active');
      updatePanel(card.dataset.spotlightLogo, card.dataset.spotlightTitle, card.dataset.spotlightDesc);
    });
  });

  requestAnimationFrame(autoScroll);
});
