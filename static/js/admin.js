/**
 * Unyson+ -> Post Types admin screen.
 *
 * Two jobs: the native nav-tab switching, and a confirmation in front of the
 * addable-popup delete control.
 */
(function ($) {
	'use strict';

	var L = window.fwExtPostTypes || {};

	$(function () {
		var $wrap = $('.fw-ext-post-types');

		if (!$wrap.length) {
			return;
		}

		/* Tabs ------------------------------------------------------------- */

		$wrap.on('click', '.fw-post-types-tabs .nav-tab', function (e) {
			e.preventDefault();

			var tab = $(this).data('tab');

			$wrap.find('.fw-post-types-tabs .nav-tab').removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');

			$wrap.find('.fw-pt-panel').removeClass('is-active')
				.filter('[data-panel="' + tab + '"]').addClass('is-active');

			// Keep the URL in step so a reload (and the post-save redirect) lands
			// back on the tab the user was on.
			if (window.history && window.history.replaceState) {
				var url = new URL(window.location.href);
				url.searchParams.set('fw-tab', tab);
				window.history.replaceState({}, '', url.toString());
			}
		});

		/* Delete confirmation ---------------------------------------------- */

		// The addable-popup binds its own delegated click handler on the items
		// wrapper, which removes the row immediately. A delegated handler of ours
		// would run after it, so this listens in the CAPTURE phase — that fires
		// before any bubbling handler and can cancel the removal outright.
		if (L.confirmRemove) {
			$wrap.each(function () {
				this.addEventListener('click', function (e) {
					var target = e.target;

					if (!target || !target.classList || !target.classList.contains('delete-item')) {
						return;
					}

					if (!window.confirm(L.confirmRemove)) {
						e.preventDefault();
						e.stopImmediatePropagation();
					}
				}, true);
			});
		}

		/* Destructive tool confirmations ----------------------------------- */

		$wrap.on('submit', '.fw-pt-import-form', function (e) {
			if (L.confirmImport && $(this).find('.fw-pt-replace').is(':checked') && !window.confirm(L.confirmImport)) {
				e.preventDefault();
			}
		});

		$wrap.on('submit', '.fw-pt-rename-form', function (e) {
			if (L.confirmRename && !window.confirm(L.confirmRename)) {
				e.preventDefault();
			}
		});
	});
})(jQuery);
