/* global BdrvwAdmin, BdrvwGoogleReviews, google, jQuery */
/**
 * Google Reviews admin tab.
 *
 * Flow: type → dropdown → click a suggestion → auto-connect (settings saved,
 * UI swaps to connected card, preview refreshes). Layout/template radios
 * update the preview pane and the shortcode card live.
 */
(function ($) {
	'use strict';

	$(function () {
		var $root = $('.bdrvw-gr');
		if (!$root.length) { return; }

		var SEARCH_DEBOUNCE = 280;

		// Search side
		var $query        = $root.find('[data-bdrvw-gr-query]');
		var $results      = $root.find('[data-bdrvw-gr-results]');
		var $searchBlock  = $root.find('[data-bdrvw-gr-search]');
		var $connected    = $root.find('[data-bdrvw-gr-connected]');

		// Hidden inputs that persist on Save
		var $placeInput     = $root.find('[data-bdrvw-gr-place]');
		var $bizNameInput   = $root.find('[data-bdrvw-gr-bizname-input]');
		var $bizAddrInput   = $root.find('[data-bdrvw-gr-bizaddress-input]');
		var $bizRateInput   = $root.find('[data-bdrvw-gr-bizrating-input]');
		var $bizTotalInput  = $root.find('[data-bdrvw-gr-biztotal-input]');
		var $bizUrlInput    = $root.find('[data-bdrvw-gr-bizurl-input]');
		var $bizIconInput   = $root.find('[data-bdrvw-gr-bizicon-input]');
		var $isDemoInput    = $root.find('[data-bdrvw-gr-isdemo-input]');

		// API key field — sent with search/connect so a freshly-typed (unsaved)
		// key works immediately, without a Save + reload first.
		var $apiKey         = $root.find('#bdrvw-gr-api-key[name]');
		function currentApiKey() { return String($apiKey.val() || '').trim(); }

		// Preview + shortcode
		var $preview        = $root.find('[data-bdrvw-gr-preview]');
		var $previewState   = $root.find('[data-bdrvw-gr-preview-state]');
		var $shortcode      = $root.find('[data-bdrvw-gr-shortcode]');

		// Guard against double-fires while a connect is in flight.
		var connecting = false;

		// === Search ==========================================================
		var debounceTimer = null;
		var lastQuery     = '';

		$query.on('input', function () {
			var q = String($(this).val() || '').trim();
			if (q === lastQuery) { return; }
			lastQuery = q;
			clearTimeout(debounceTimer);
			if (q.length < 2) {
				$results.attr('hidden', 'hidden').empty();
				return;
			}
			$searchBlock.addClass('is-loading');
			debounceTimer = setTimeout(function () { runSearch(q); }, SEARCH_DEBOUNCE);
		});

		// Hide dropdown on outside click
		$(document).on('click', function (e) {
			if (!$.contains($searchBlock.get(0) || document.body, e.target)) {
				$results.attr('hidden', 'hidden');
			}
		});

		// === Google Maps JS API plumbing ====================================
		var autocompleteSvc = null;
		var sessionToken    = null;

		function mapsPlacesReady() {
			return !!(window.google && window.google.maps && window.google.maps.places && window.google.maps.places.AutocompleteService);
		}
		function getAutocompleteService() {
			if (!autocompleteSvc && mapsPlacesReady()) {
				autocompleteSvc = new google.maps.places.AutocompleteService();
			}
			return autocompleteSvc;
		}
		function getSessionToken() {
			if (!sessionToken && mapsPlacesReady()) {
				sessionToken = new google.maps.places.AutocompleteSessionToken();
			}
			return sessionToken;
		}

		function runSearch(q) {
			var useMapsJs = (typeof BdrvwGoogleReviews !== 'undefined') && BdrvwGoogleReviews.hasMapsJs && mapsPlacesReady();
			if (useMapsJs) {
				runMapsJsSearch(q);
			} else {
				runAjaxSearch(q);
			}
		}

		function runMapsJsSearch(q) {
			var svc = getAutocompleteService();
			if (!svc) { runAjaxSearch(q); return; }
			svc.getPlacePredictions(
				{ input: q, sessionToken: getSessionToken() },
				function (predictions, status) {
					$searchBlock.removeClass('is-loading');
					if (status !== google.maps.places.PlacesServiceStatus.OK || !predictions || !predictions.length) {
						renderEmpty();
						return;
					}
					var items = predictions.map(function (p) {
						var main = (p.structured_formatting && p.structured_formatting.main_text) || p.description || '';
						var sec  = (p.structured_formatting && p.structured_formatting.secondary_text) || '';
						return {
							place_id: p.place_id,
							name:     main,
							address:  sec,
							types:    p.types || []
						};
					});
					renderResults(items, false);
				}
			);
		}

		function runAjaxSearch(q) {
			$.ajax({
				url: BdrvwAdmin.ajaxUrl,
				method: 'GET',
				data: {
					action: 'bdrvw_google_search',
					_nonce: BdrvwAdmin.nonce,
					q: q,
					api_key: currentApiKey()
				}
			}).done(function (resp) {
				$searchBlock.removeClass('is-loading');
				if (!resp || !resp.success || !resp.data || !resp.data.items || !resp.data.items.length) {
					renderEmpty();
					return;
				}
				renderResults(resp.data.items, !!resp.data.demo);
			}).fail(function () {
				$searchBlock.removeClass('is-loading');
				renderEmpty('Search failed. Please try again.');
			});
		}

		function renderResults(items, isDemo) {
			var html = items.map(function (item, idx) {
				return '<li class="bdrvw-gr__result' + (isDemo ? ' is-demo' : '') + '" role="option" data-idx="' + idx + '">' +
					'<div class="bdrvw-gr__result-icon" aria-hidden="true">' +
						'<svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 4.97 6.27 12.46 6.53 12.78a.6.6 0 0 0 .94 0C12.73 21.46 19 13.97 19 9a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>' +
					'</div>' +
					'<div class="bdrvw-gr__result-body">' +
						'<strong>' + escapeHtml(item.name) + '</strong>' +
						'<span>' + escapeHtml(item.address) + '</span>' +
					'</div>' +
				'</li>';
			}).join('');
			html += '<li class="bdrvw-gr__powered" aria-hidden="true">powered by ' +
				'<span class="bdrvw-gr__powered-g">' +
					'<span style="color:#4285F4">G</span>' +
					'<span style="color:#EA4335">o</span>' +
					'<span style="color:#FBBC04">o</span>' +
					'<span style="color:#4285F4">g</span>' +
					'<span style="color:#34A853">l</span>' +
					'<span style="color:#EA4335">e</span>' +
				'</span>' +
			'</li>';
			$results.html(html).data('items', items).removeAttr('hidden');
		}

		function renderEmpty(text) {
			var msg = text || (BdrvwAdmin.i18n && BdrvwAdmin.i18n.noResults) || 'No results found';
			$results.html('<li class="bdrvw-gr__result is-empty">' + escapeHtml(msg) + '</li>').removeAttr('hidden');
		}

		// === Select a result → auto-connect =================================
		$root.on('click', '.bdrvw-gr__result:not(.is-empty)', function () {
			if (connecting) { return; }
			var idx   = parseInt($(this).attr('data-idx'), 10);
			var items = $results.data('items') || [];
			var item  = items[idx];
			if (!item || !item.place_id) { return; }

			$query.val(item.name);
			$results.attr('hidden', 'hidden');
			connectPlace(item);
		});

		function connectPlace(item) {
			var placeId = String(item.place_id);
			connecting = true;
			$searchBlock.addClass('is-loading');
			$query.prop('disabled', true);

			$.ajax({
				url: BdrvwAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'bdrvw_google_connect',
					_nonce: BdrvwAdmin.nonce,
					place_id: placeId,
					name: String(item.name || ''),
					address: String(item.address || ''),
					api_key: currentApiKey()
				}
			}).done(function (resp) {
				if (!resp || !resp.success) {
					var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not connect. Check your API key.';
					window.alert(msg);
					resetSearchState();
					return;
				}
				var biz = resp.data.business || {};
				$placeInput.val(placeId);
				$bizNameInput.val(biz.name || '');
				$bizAddrInput.val(biz.address || '');
				$bizRateInput.val(biz.rating || 0);
				$bizTotalInput.val(biz.total || 0);
				$bizUrlInput.val(biz.url || '');
				$bizIconInput.val(biz.icon || '');
				$isDemoInput.val(placeId.indexOf('demo-') === 0 ? '1' : '0');

				$root.find('[data-bdrvw-gr-bizname]').text(biz.name || '');
				$root.find('[data-bdrvw-gr-bizaddress]').text(biz.address || '');
				var $bizUrl = $root.find('[data-bdrvw-gr-bizurl]');
				$bizUrl.attr('href', biz.url || '#').text(biz.url || '');
				var $icon = $root.find('[data-bdrvw-gr-connected-icon]');
				if (biz.icon) {
					$icon.html('<img src="' + escapeAttr(biz.icon) + '" alt="" referrerpolicy="no-referrer" />');
				}

				$searchBlock.addClass('is-hidden').removeClass('is-loading');
				$connected.removeClass('is-hidden');
				$query.val('').prop('disabled', false);
				$results.attr('hidden', 'hidden').empty();
				connecting = false;
				sessionToken = null;
				lastQuery = '';

				$previewState.text('Showing connected business data.');
				refreshPreview(getCurrentLayout(), getCurrentTemplate());
			}).fail(function (xhr) {
				var msg = 'Could not connect. Check your API key.';
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				window.alert(msg);
				resetSearchState();
			});
		}

		function resetSearchState() {
			connecting = false;
			$searchBlock.removeClass('is-loading');
			$query.prop('disabled', false);
		}

		// === Disconnect =====================================================
		$root.on('click', '[data-bdrvw-gr-disconnect]', function () {
			$.ajax({
				url: BdrvwAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'bdrvw_google_disconnect',
					_nonce: BdrvwAdmin.nonce
				}
			}).done(function () {
				$placeInput.val('');
				$bizNameInput.val(''); $bizAddrInput.val('');
				$bizRateInput.val(0);  $bizTotalInput.val(0);
				$bizUrlInput.val('');  $bizIconInput.val('');
				$isDemoInput.val('0');
				$connected.addClass('is-hidden');
				$searchBlock.removeClass('is-hidden');
				$previewState.text('Showing demo data. Connect a Google Business Profile above to see real reviews here.');
				refreshPreview(getCurrentLayout(), getCurrentTemplate());
			});
		});

		// Show only the template styles the current layout offers. Each template
		// radio carries a data-layouts list (e.g. "list" exposes one style only).
		// If the checked template isn't valid for the layout, fall back to the
		// first visible one so the preview/shortcode stay consistent.
		function filterTemplates(layout) {
			// The Popup layout renders each style as a review badge, and the List
			// and Grid layouts restyle Style 3/4 — swap the template cards to the
			// matching layout-specific thumbnails.
			var $tplWrap = $root.find('[data-bdrvw-gr-templates]');
			$tplWrap.toggleClass('is-layout-popup', layout === 'popup');
			$tplWrap.toggleClass('is-layout-list', layout === 'list');
			$tplWrap.toggleClass('is-layout-grid', layout === 'grid');
			var $first = $();
			var movedOff = false;
			// Visible styles are renumbered serially per layout (e.g. the List drops
			// a few styles, so its remaining cards read Style 1..N with no gaps).
			var serial = 0;
			$root.find('.bdrvw-gr__template').each(function () {
				var $lbl   = $(this);
				var $input = $lbl.find('[data-bdrvw-gr-template]');
				var list   = String($input.attr('data-layouts') || '').split(/\s+/).filter(Boolean);
				var show   = !list.length || list.indexOf(layout) !== -1;
				$lbl.toggleClass('is-hidden', !show);
				if (show) {
					if (!$first.length) { $first = $lbl; }
					// Serial position within this layout — drives both the renumbered
					// label AND the shortcode's style number so they always agree.
					serial += 1;
					$input.attr('data-serial', serial);
					var $no = $lbl.find('.bdrvw-gr__template-no');
					if ($no.length) {
						// Capture the word part ("Style") once, then renumber.
						if (typeof $no.attr('data-label-base') === 'undefined') {
							$no.attr('data-label-base', $.trim($no.text()).replace(/\s*\d+\s*$/, ''));
						}
						$no.text($no.attr('data-label-base') + ' ' + serial);
					}
				} else if ($input.is(':checked')) {
					movedOff = true;
				}
			});
			if (movedOff && $first.length) {
				$root.find('.bdrvw-gr__template').removeClass('is-active');
				$first.addClass('is-active').find('[data-bdrvw-gr-template]').prop('checked', true);
			}
		}

		// Sync template visibility with whatever layout is selected on load.
		filterTemplates(getCurrentLayout());

		// === Layout / template radios → live preview + shortcode ============
		$root.on('change', '[data-bdrvw-gr-layout]', function () {
			$root.find('.bdrvw-gr__layout').removeClass('is-active');
			$(this).closest('.bdrvw-gr__layout').addClass('is-active');
			filterTemplates(getCurrentLayout());
			syncShortcode();
			refreshPreview(getCurrentLayout(), getCurrentTemplate());
		});

		$root.on('change', '[data-bdrvw-gr-template]', function () {
			$root.find('.bdrvw-gr__template').removeClass('is-active');
			$(this).closest('.bdrvw-gr__template').addClass('is-active');
			syncShortcode();
			refreshPreview(getCurrentLayout(), getCurrentTemplate());
		});

		// === Shortcode copy-to-clipboard ====================================
		$root.on('click', '[data-bdrvw-gr-copy]', function (e) {
			e.preventDefault();
			var $btn       = $(this);
			var text       = String($shortcode.text() || '').trim();
			var labelOk    = $btn.attr('data-label-copied') || 'Copied!';
			var labelCopy  = $btn.attr('data-label-copy')   || 'Copy';
			if ('' === text) { return; }

			var done = function () {
				$btn.text(labelOk).addClass('is-copied');
				clearTimeout($btn.data('br-copy-timer'));
				$btn.data('br-copy-timer', setTimeout(function () {
					$btn.text(labelCopy).removeClass('is-copied');
				}, 1500));
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, fallback);
			} else {
				fallback();
			}

			function fallback() {
				// Pre-Clipboard-API fallback: select the <code> contents and execCommand('copy').
				var range = document.createRange();
				range.selectNodeContents($shortcode.get(0));
				var sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
				try { document.execCommand('copy'); done(); } catch (err) { /* noop */ }
				sel.removeAllRanges();
			}
		});

		function getCurrentLayout()   { return String($root.find('[data-bdrvw-gr-layout]:checked').val() || 'grid'); }
		function getCurrentTemplate() { return String($root.find('[data-bdrvw-gr-template]:checked').val() || 'template_1'); }
		function getCurrentColumns()  {
			var c = parseInt($root.find('[data-bdrvw-gr-columns]').val(), 10);
			if (!isFinite(c) || c < 1) { c = 3; }
			if (c > 4) { c = 4; }
			return c;
		}

		function syncShortcode() {
			var $layout   = $root.find('[data-bdrvw-gr-layout]:checked');
			var $template = $root.find('[data-bdrvw-gr-template]:checked');
			var $card     = $root.find('[data-bdrvw-gr-shortcode-card]');
			var $copyBtn  = $root.find('[data-bdrvw-gr-copy]');

			if ($card.length)    { $card.removeAttr('hidden'); }
			$shortcode.removeClass('is-locked');
			if ($copyBtn.length) { $copyBtn.removeAttr('hidden'); }

			var slug      = String($layout.attr('data-shortcode') || 'bdrvw_google_grid');
			// Use the per-layout serial number (set in filterTemplates), not the
			// template's global data-style, so the shortcode matches the label.
			var serial    = parseInt($template.attr('data-serial'), 10);
			if (!isFinite(serial) || serial < 1) { serial = 1; }
			var style     = 'style' + serial;
			var limit     = parseInt($root.find('#bdrvw-gr-limit').val(), 10);
			if (!isFinite(limit) || limit < 1) { limit = 0; }
			if (limit > 50) { limit = 50; }
			var sc = '[' + slug + ' style="' + style + '"';
			sc += ' columns="' + getCurrentColumns() + '"';
			if (limit > 0) { sc += ' max_reviews="' + limit + '"'; }
			$shortcode.text(sc + ']');
		}

		// Keep the shortcode's max_reviews in sync when the limit field changes
		// (it lives on the Display tab but shares the same .bdrvw-gr root).
		$root.on('input change', '#bdrvw-gr-limit', syncShortcode);

		// Cards-per-row drives both the shortcode and the live preview (grid columns
		// / slider slides-per-view), so refresh both when it changes.
		$root.on('change', '[data-bdrvw-gr-columns]', function () {
			syncShortcode();
			refreshPreview(getCurrentLayout(), getCurrentTemplate());
		});

		var previewReq = null;
		function refreshPreview(layout, template) {
			if (previewReq) { previewReq.abort(); }
			$preview.addClass('is-loading');
			previewReq = $.ajax({
				url: BdrvwAdmin.ajaxUrl,
				method: 'GET',
				data: {
					action: 'bdrvw_google_preview',
					_nonce: BdrvwAdmin.nonce,
					layout: layout,
					template: template,
					columns: getCurrentColumns()
				}
			}).done(function (resp) {
				$preview.removeClass('is-loading');
				if (resp && resp.success && resp.data && resp.data.html) {
					$preview.html(resp.data.html);
					// Re-init Swiper on the freshly injected slider markup so the
					// preview is draggable just like the frontend.
					if (window.BdrvwGR && window.BdrvwGR.initSliders) {
						window.BdrvwGR.initSliders($preview.get(0));
					}
					if (window.BdrvwGR && window.BdrvwGR.initLikes) {
						window.BdrvwGR.initLikes($preview.get(0));
					}
					// Re-measure the "Read more" truncation on the fresh markup so the
					// toggle expands/collapses correctly in the preview.
					if (window.BdrvwGR && window.BdrvwGR.initReadMore) {
						window.BdrvwGR.initReadMore($preview.get(0));
					}
					// Notify add-ons (e.g. a Pro slider layout) that fresh preview
					// markup was injected so they can (re)initialise their widgets.
					document.dispatchEvent(new CustomEvent("bdrvw-gr-preview-rendered", { detail: { root: $preview.get(0) } }));
				}
			}).fail(function (jq, status) {
				if (status !== 'abort') { $preview.removeClass('is-loading'); }
			});
		}

		// === Helpers ========================================================
		function escapeHtml(s) {
			return String(s == null ? '' : s)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}
		function escapeAttr(s) { return escapeHtml(s); }
	});
})(jQuery);
