document.addEventListener('DOMContentLoaded', function () {
	var toggle = document.querySelector('.itu-header__search-toggle');
	var search = document.getElementById('itu-search');
	var input = search ? search.querySelector('.itu-header__search-input') : null;

	if (!toggle || !search) return;

	toggle.addEventListener('click', function () {
		search.classList.toggle('is-open');
		if (search.classList.contains('is-open') && input) {
			input.focus();
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && search.classList.contains('is-open')) {
			search.classList.remove('is-open');
		}
	});
});
