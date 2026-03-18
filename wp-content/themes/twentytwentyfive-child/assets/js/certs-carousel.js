document.addEventListener('DOMContentLoaded', function () {
	var cards = document.querySelectorAll('.itu-certs__card');
	var logo = document.querySelector('.itu-certs__logo img');
	var title = document.querySelector('.itu-certs__title');
	var desc = document.querySelector('.itu-certs__description');
	var track = document.querySelector('.itu-certs__track');

	if (!cards.length || !logo || !title || !desc || !track) return;

	var defaultLogo = logo.src;
	var defaultTitle = title.textContent;
	var defaultDesc = desc.textContent;

	// --- Hover interaction ---
	cards.forEach(function (card) {
		card.addEventListener('mouseenter', function () {
			cards.forEach(function (c) { c.classList.remove('is-active'); });
			card.classList.add('is-active');

			logo.style.opacity = '0';
			setTimeout(function () {
				logo.src = card.dataset.logo;
				logo.alt = card.dataset.cert;
				logo.style.opacity = '1';
			}, 150);

			title.textContent = card.dataset.title;
			desc.textContent = card.dataset.desc;
		});
	});

	var certsSection = document.querySelector('.itu-certs');
	if (certsSection) {
		certsSection.addEventListener('mouseleave', function () {
			cards.forEach(function (c) { c.classList.remove('is-active'); });

			logo.style.opacity = '0';
			setTimeout(function () {
				logo.src = defaultLogo;
				logo.alt = 'Default certification';
				logo.style.opacity = '1';
			}, 150);

			title.textContent = defaultTitle;
			desc.textContent = defaultDesc;
		});
	}

	// --- Auto-scroll carousel ---
	// Duplicate cards for seamless loop
	var trackCards = track.innerHTML;
	track.innerHTML = trackCards + trackCards;

	var scrollSpeed = 0.5; // pixels per frame
	var scrollPos = 0;
	var halfWidth = track.scrollWidth / 2;
	var isPaused = false;

	function autoScroll() {
		if (!isPaused) {
			scrollPos += scrollSpeed;
			if (scrollPos >= halfWidth) {
				scrollPos = 0;
			}
			track.style.transform = 'translateX(-' + scrollPos + 'px)';
		}
		requestAnimationFrame(autoScroll);
	}

	// Pause on hover
	track.addEventListener('mouseenter', function () {
		isPaused = true;
	});

	track.addEventListener('mouseleave', function () {
		isPaused = false;
	});

	// Re-bind hover events to duplicated cards
	var allCards = track.querySelectorAll('.itu-certs__card');
	allCards.forEach(function (card) {
		card.addEventListener('mouseenter', function () {
			allCards.forEach(function (c) { c.classList.remove('is-active'); });
			card.classList.add('is-active');

			logo.style.opacity = '0';
			setTimeout(function () {
				logo.src = card.dataset.logo;
				logo.alt = card.dataset.cert;
				logo.style.opacity = '1';
			}, 150);

			title.textContent = card.dataset.title;
			desc.textContent = card.dataset.desc;
		});
	});

	requestAnimationFrame(autoScroll);
});
