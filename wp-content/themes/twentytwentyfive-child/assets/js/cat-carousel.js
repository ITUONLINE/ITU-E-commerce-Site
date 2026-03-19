document.addEventListener('DOMContentLoaded', function () {
	var rows = document.querySelectorAll('.itu-cat-row');

	rows.forEach(function (row) {
		var carousel = row.querySelector('.itu-cat-row__carousel');
		var prevBtn = row.querySelector('.itu-cat-row__arrow--prev');
		var nextBtn = row.querySelector('.itu-cat-row__arrow--next');

		if (!carousel || !prevBtn || !nextBtn) return;

		var scrollAmount = 300;

		nextBtn.addEventListener('click', function () {
			carousel.scrollBy({ left: scrollAmount, behavior: 'smooth' });
		});

		prevBtn.addEventListener('click', function () {
			carousel.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
		});
	});
});
