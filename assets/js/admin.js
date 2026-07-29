/* global BdrvwAdmin, jQuery */
(function ($) {
	'use strict';

	$(function () {
		// Highlight selected template card.
		$(document).on('change', '.bdrvw-template input[type="radio"]', function () {
			$('.bdrvw-template').removeClass('is-selected');
			$(this).closest('.bdrvw-template').addClass('is-selected');
		});

		// Advanced Settings: each "Limit reviews by" mode brings its own list —
		// a whitelist for email, a blocklist for IP — and only that one shows.
		function bdrvwSyncLimitBy() {
			var mode = $('[data-bdrvw-limit-by]').val() || '';
			$('[data-bdrvw-limit-field]').each(function () {
				$(this).prop('hidden', $(this).data('bdrvw-limit-field') !== mode);
			});
		}
		bdrvwSyncLimitBy();
		$(document).on('change', '[data-bdrvw-limit-by]', bdrvwSyncLimitBy);

		// Row descriptions become tooltips on the label, so a settings card reads
		// as a list of choices rather than a wall of explanation. Done here rather
		// than in the markup so every row gets it, including the ones add-ons
		// render. Without JS the hints simply stay where they are.
		$('.bdrvw-row__label').each(function () {
			var $label = $(this);
			var $hint = $label.children('.bdrvw-row__hint').first();
			var $anchor = $label.children('label, .bdrvw-pro-row__title').first();
			if (!$hint.length || !$anchor.length || $label.find('.bdrvw-tip').length) {
				return;
			}

			var $tip = $(
				'<span class="bdrvw-tip" tabindex="0" role="button" aria-expanded="false">' +
					'<span class="bdrvw-tip__icon" aria-hidden="true">?</span>' +
					'<span class="bdrvw-tip__bubble" role="tooltip"></span>' +
				'</span>'
			);
			// html(), not text(): a few hints carry a <code> shortcode or a link.
			$tip.children('.bdrvw-tip__bubble').html($hint.html());
			$tip.attr('aria-label', $hint.text());
			// Before the label, not after it: the marks then line up down the
			// left edge of the card instead of drifting with each label's length.
			$anchor.prepend($tip);
			$hint.remove();
		});

		// The tip sits inside a <label>, so a plain click would toggle the field
		// it points at. Tapping opens the bubble instead — hover handles the rest.
		$(document).on('click', '.bdrvw-tip', function (e) {
			// A click inside the bubble is meant for what is in it — usually a
			// link to the service being configured. Let it through untouched.
			if ($(e.target).closest('.bdrvw-tip__bubble').length) {
				e.stopPropagation();
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			var $tip = $(this);
			var open = $tip.hasClass('is-open');
			$('.bdrvw-tip').removeClass('is-open').attr('aria-expanded', 'false');
			$tip.toggleClass('is-open', !open).attr('aria-expanded', String(!open));
		});
		$(document).on('click', function () {
			$('.bdrvw-tip').removeClass('is-open').attr('aria-expanded', 'false');
		});

		// Tools page: the two date fields belong to "Custom date" only.
		function bdrvwSyncRange($group) {
			var isCustom = $group.find('[data-bdrvw-range-choice]:checked').val() === 'custom';
			$group.find('[data-bdrvw-range-custom]').prop('hidden', !isCustom);
		}
		$('[data-bdrvw-range]').each(function () {
			bdrvwSyncRange($(this));
		});
		$(document).on('change', '[data-bdrvw-range-choice]', function () {
			bdrvwSyncRange($(this).closest('[data-bdrvw-range]'));
		});

		$(document).on('change', 'input[data-toggle-field]', function () {
			var $row = $(this).closest('.bdrvw-fields__row');
			$row.toggleClass('is-enabled', this.checked);
		});

		// Form Fields tab: the "Rating input style" switch drives both the style
		// picker under it and the Star rating row in the field list — that row has
		// no Enabled switch of its own, since it would be the same setting twice.
		$(document).on('change', 'input[data-bdrvw-style-toggle]', function () {
			$('[data-bdrvw-style-options]').toggleClass('is-disabled', !this.checked);
			$('.bdrvw-fields__row[data-field="rating"]').toggleClass('is-enabled', this.checked);
		});

		// General tab: only show the custom words textarea for the BoldReview blacklist.
		$(document).on('change', 'select[data-bdrvw-blacklist-integration]', function () {
			$('[data-bdrvw-blacklist-words]').toggleClass('is-hidden', this.value === 'comments');
		});

		// "Settings based on CPT" filter (General tab): reveal the WooCommerce-only
		// review settings only while "Product" is selected. Pure view toggle.
		function bdrvwSyncCptFilter($sel) {
			var isProduct = $sel.val() === 'product';
			$sel.closest('.bdrvw-card').find('[data-bdrvw-wc-only]').prop('hidden', !isProduct);
		}
		$(document).on('change', 'select[data-bdrvw-cpt-filter]', function () {
			bdrvwSyncCptFilter($(this));
		});
		$('select[data-bdrvw-cpt-filter]').each(function () {
			bdrvwSyncCptFilter($(this));
		});

		// === Rating Summary tab: accordion + criteria repeaters ===

		$(document).on('click', '[data-cgroup-toggle]', function () {
			var $group = $(this).closest('[data-cgroup]');
			var open   = !$group.hasClass('is-open');
			$group.toggleClass('is-open', open);
			$(this).attr('aria-expanded', open ? 'true' : 'false');
			
			if (open) {
				$group.find('select[data-cgroup-pt-select]:not([data-br-select2-ready])').each(function () {
					initSelect2($(this));
				});
				$group.find('select[data-cgroup-pt-select][data-br-select2-ready]').each(function () {
					if ($(this).next('.select2').length) {
						$(this).trigger('change.select2');
					}
				});
			}
		});

		
		$(document).on('input', '[data-cgroup-name]', function () {
			var $group = $(this).closest('[data-cgroup]');
			var val    = String($(this).val() || '').trim();
			$group.find('[data-cgroup-title]').text(val || (BdrvwAdmin.i18n.newGroup || 'New group'));
		});

		$(document).on('click', '[data-cgroup-remove]', function () {
			if (!window.confirm(BdrvwAdmin.i18n.confirmRemoveGroup || 'Remove this group?')) { return; }
			$(this).closest('[data-cgroup]').remove();
		});

		
		$(document).on('click', '#bdrvw-cgroups-add', function () {
			var $container = $('#bdrvw-cgroups');
			var idx        = parseInt($container.attr('data-next-index') || '0', 10) || 0;
			var html       = $('#bdrvw-cgroup-template').html();
			if (!html) { return; }
			html = html.split('__INDEX__').join(String(idx));
			var $row = $(html);
			$container.append($row);
			$container.attr('data-next-index', String(idx + 1));

			$row.addClass('is-open').find('[data-cgroup-toggle]').attr('aria-expanded', 'true');
			// New group is appended to the DOM after init ran on document, so its
			// post-type Select2s have never been bound — initialize them now.
			initSelect2In($row);
			$row.find('[data-cgroup-name]').trigger('focus');
		});

		// Add a criterion row inside a group.
		$(document).on('click', '[data-crit-add]', function () {
			var $btn   = $(this);
			var $group = $btn.closest('[data-cgroup]');
			var $list  = $group.find('[data-crit-list]').first();
			var gIdx   = String($group.attr('data-index'));
			var cIdx   = parseInt($list.attr('data-next-crit') || '0', 10) || 0;
			var tpl    = $('#bdrvw-crit-row-template').html();
			if (!tpl) { return; }
			tpl = tpl.split('__GROUP__').join(gIdx).split('__INDEX__').join(String(cIdx));
			var $row = $(tpl);
			$list.append($row);
			$list.attr('data-next-crit', String(cIdx + 1));
			$row.find('input[type="text"]').trigger('focus');
		});

		// Remove a single criterion row.
		$(document).on('click', '.bdrvw-cgroup__crit-remove', function () {
			$(this).closest('[data-crit-row]').remove();
		});

		// Rating summary overview switch — reveal/hide heading + description rows.
		$(document).on('change', '[data-overview-toggle]', function () {
			$(this).closest('[data-cgroup]').toggleClass('has-overview', this.checked);
		});

		// Star picker — click a star to set the rating; click the same star again to clear.
		$(document).on('click', '[data-stars-picker] [data-star]', function (e) {
			e.preventDefault();
			var $btn    = $(this);
			var $picker = $btn.closest('[data-stars-picker]');
			var val     = parseInt($btn.attr('data-star'), 10) || 0;
			var current = parseInt($picker.attr('data-rating'), 10) || 0;
			if (val === current) { val = 0; }
			$picker.attr('data-rating', String(val));
			$picker.find('[data-stars-input]').val(String(val));
			$picker.find('[data-star]').each(function () {
				var n = parseInt($(this).attr('data-star'), 10) || 0;
				$(this).toggleClass('is-on', n <= val && val > 0);
			});
		});

		
		$(document).on('change', 'select[data-cgroup-pt-select]', function () {
			var $sel = $(this);
			var vals = $sel.val() || [];
			if (vals.length <= 1) { return; }

			var lastRaw = $sel.data('lastValues') || [];
			var added   = vals.filter(function (v) { return lastRaw.indexOf(v) === -1; });

			if (added.indexOf('__all__') !== -1) {
				$sel.val(['__all__']);
				$sel.trigger('change.select2');
			} else if (vals.indexOf('__all__') !== -1) {
				$sel.val(vals.filter(function (v) { return v !== '__all__'; }));
				$sel.trigger('change.select2');
			}
			$sel.data('lastValues', $sel.val() || []);
		});

		
		initSelect2In($(document));
		initExcludePostsIn($(document));
		initPostTypeSelectIn($(document));

		// Enabled post types Select2: a plain multi-select over a fixed list of
		// public post types (no Ajax) — the options are already in the DOM.
		function initPostTypeSelectIn($scope) {
			$scope.find('select[data-bdrvw-pt-select]').each(function () {
				var $select = $(this);
				if ($select.data('br-select2-ready') || !$.fn.select2) { return; }

				$select.select2({
					width: '100%',
					placeholder: $select.data('placeholder') || (BdrvwAdmin.i18n.searchPlaceholder || 'Select…'),
					dropdownParent: $select.closest('.bdrvw-cgroup__pt-row'),
					// Short, fixed list of post types — no search field needed.
					minimumResultsForSearch: Infinity,
					language: {
						noResults: function () { return BdrvwAdmin.i18n.noResults || 'No results found'; },
					},
				});

				$select.data('br-select2-ready', true);
			});
		}

		function initSelect2In($scope) {
			$scope.find('select[data-cgroup-pt-select]').each(function () {
				initSelect2($(this));
			});
		}

		// Exclude-posts Select2 (Enabled post types row): cross-post-type search,
		// reuses the same Ajax endpoint, returns multi-select chips.
		function initExcludePostsIn($scope) {
			$scope.find('select[data-bdrvw-exclude-select]').each(function () {
				var $select = $(this);
				if ($select.data('br-select2-ready') || !$.fn.select2) { return; }

				var ptCsv = String($select.data('post-types') || '').trim();
				var ptList = ptCsv ? ptCsv.split(',') : [];

				$select.select2({
					width: '100%',
					placeholder: BdrvwAdmin.i18n.searchPlaceholder || 'Search…',
					dropdownParent: $select.closest('.bdrvw-cgroup__pt-row'),
					language: {
						noResults: function () { return BdrvwAdmin.i18n.noResults || 'No results found'; },
						searching: function () { return BdrvwAdmin.i18n.searching || 'Searching…'; },
					},
					ajax: {
						url: BdrvwAdmin.ajaxUrl,
						dataType: 'json',
						delay: 220,
						transport: function (params, success, failure) {
							// Endpoint expects ?post_type=… — query each enabled
							// post type in parallel, then merge results.
							if (!ptList.length) { success({ success: true, data: { items: [], has_more: false } }); return; }
							var calls = ptList.map(function (pt) {
								return $.ajax({
									url: BdrvwAdmin.ajaxUrl,
									dataType: 'json',
									data: $.extend({}, params.data, { post_type: pt }),
								});
							});
							$.when.apply($, calls).done(function () {
								var responses = ptList.length === 1 ? [arguments] : Array.prototype.slice.call(arguments);
								var merged = [];
								responses.forEach(function (res) {
									var resp = res && res[0] ? res[0] : res;
									if (resp && resp.success && resp.data && Array.isArray(resp.data.items)) {
										resp.data.items.forEach(function (it) {
											if (!merged.find(function (m) { return m.id === it.id; })) {
												merged.push(it);
											}
										});
									}
								});
								success({ success: true, data: { items: merged, has_more: false } });
							}).fail(failure);
						},
						data: function (params) {
							return {
								action: 'bdrvw_search_posts',
								q:      params.term || '',
								page:   params.page || 1,
								_nonce: BdrvwAdmin.nonce,
							};
						},
						processResults: function (resp) {
							if (!resp || !resp.success || !resp.data) { return { results: [] }; }
							return {
								results:    (resp.data.items || []).map(function (it) { return { id: it.id, text: it.text }; }),
								pagination: { more: false },
							};
						},
						cache: true,
					},
					escapeMarkup: function (m) { return m; },
					templateResult: function (item) {
						if (!item.id) { return item.text; }
						return $('<span>').text(item.text);
					},
					templateSelection: function (item) {
						return $('<span>').text(item.text || '');
					},
				});

				$select.data('br-select2-ready', true);
			});
		}

		function initSelect2($select) {
			if (!$select.length || $select.data('br-select2-ready')) { return; }
			if (!$.fn.select2) { return; }

			var pt = $select.data('cgroup-pt-select');
			$select.select2({
				width: '100%',
				placeholder: BdrvwAdmin.i18n.searchPlaceholder || 'Search…',
				dropdownParent: $select.closest('.bdrvw-cgroup__pt-row'),
				language: {
					noResults: function () { return BdrvwAdmin.i18n.noResults || 'No results found'; },
					searching: function () { return BdrvwAdmin.i18n.searching || 'Searching…'; },
				},
				ajax: {
					url: BdrvwAdmin.ajaxUrl,
					dataType: 'json',
					delay: 220,
					data: function (params) {
						return {
							action:    'bdrvw_search_posts',
							post_type: pt,
							q:         params.term || '',
							page:      params.page || 1,
							_nonce:    BdrvwAdmin.nonce,
						};
					},
					processResults: function (resp, params) {
						if (!resp || !resp.success || !resp.data) {
							return { results: [], pagination: { more: false } };
						}
						return {
							results:    resp.data.items || [],
							pagination: { more: !!resp.data.has_more },
						};
					},
					cache: true,
				},
				escapeMarkup: function (m) { return m; },
				templateResult: function (item) {
					if (!item.id) { return item.text; }
					return $('<span>').text(item.text);
				},
				templateSelection: function (item) {
					return $('<span>').text(item.text || '');
				},
			});

			$select.data('lastValues', $select.val() || []);
			$select.data('br-select2-ready', true);
		}

		
		$(document).on('click', '.bdrvw-nav__sub', function (e) {
			var href = $(this).attr('href');
			if (href && href.charAt(0) === '#') {
				var $target = $(href);
				if ($target.length) {
					e.preventDefault();
					$('html, body').animate({ scrollTop: $target.offset().top - 60 }, 220);
				}
			}
		});

		
		$(document).on('submit', '#bdrvw-settings-form', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn  = $form.find('button[name="bdrvw_settings_submit"]');
			var origLabel = $btn.text();

			$btn.prop('disabled', true).text(BdrvwAdmin.i18n.saving);

			$.post(
				BdrvwAdmin.ajaxUrl,
				$form.serialize() + '&action=bdrvw_save_settings'
			)
				.done(function (resp) {
					if (resp && resp.success) {
						showToast((resp.data && resp.data.message) || BdrvwAdmin.i18n.saved);
					} else {
						var msg = (resp && resp.data && resp.data.message) || BdrvwAdmin.i18n.saveFailed;
						showToast(msg, true);
					}
				})
				.fail(function () {
					showToast(BdrvwAdmin.i18n.saveFailed, true);
				})
				.always(function () {
					$btn.prop('disabled', false).text(origLabel);
				});
		});

		
		$(document).on('click', '[data-bdrvw-reset]', function () {
			var $btn   = $(this);
			var module = $btn.data('bdrvw-reset');
			if (!module) { return; }
			if (!window.confirm(BdrvwAdmin.i18n.confirmReset)) { return; }

			var origLabel = $btn.text();
			$btn.prop('disabled', true).text(BdrvwAdmin.i18n.resetting);

			$.post(BdrvwAdmin.ajaxUrl, {
				action: 'bdrvw_reset_module',
				module: module,
				_nonce: BdrvwAdmin.nonce,
			})
				.done(function (resp) {
					if (resp && resp.success) {
						showToast((resp.data && resp.data.message) || BdrvwAdmin.i18n.resetDone);
						
						setTimeout(function () { window.location.reload(); }, 600);
					} else {
						showToast(BdrvwAdmin.i18n.resetFailed, true);
						$btn.prop('disabled', false).text(origLabel);
					}
				})
				.fail(function () {
					showToast(BdrvwAdmin.i18n.resetFailed, true);
					$btn.prop('disabled', false).text(origLabel);
				});
		});

		// Tabbed module settings — switch panels client-side and sync URL.
		$(document).on('click', '.bdrvw-tabs__tab', function (e) {
			var tab = $(this).data('tab');
			if (!tab) {
				return;
			}
			e.preventDefault();

			$('.bdrvw-tabs__tab').removeClass('is-active').attr('aria-selected', 'false');
			$(this).addClass('is-active').attr('aria-selected', 'true');

			$('.bdrvw-tab-panel').removeClass('is-active');
			$('.bdrvw-tab-panel[data-tab-panel="' + tab + '"]').addClass('is-active');

			$('input[name="bdrvw_settings_tab"]').val(tab);

			if (window.history && window.history.replaceState) {
				try {
					var url = new URL(window.location.href);
					url.searchParams.set('tab', tab);
					window.history.replaceState({}, '', url.toString());
				} catch (err) { /* ignore */ }
			}
		});

		// Uses tab — copy a shortcode to the clipboard.
		$(document).on('click', '[data-bdrvw-copy-btn]', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var text = $btn.data('bdrvw-copy-btn');
			var done = function () {
				var $label = $btn.contents().filter(function () { return this.nodeType === 3; }).last();
				var original = $label.length ? $label[0].nodeValue : '';
				$btn.addClass('is-copied');
				if ($label.length) { $label[0].nodeValue = ' Copied!'; }
				window.setTimeout(function () {
					$btn.removeClass('is-copied');
					if ($label.length) { $label[0].nodeValue = original; }
				}, 1600);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done).catch(function () { done(); });
			} else {
				var $tmp = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
				$tmp[0].select();
				try { document.execCommand('copy'); } catch (err) { /* ignore */ }
				$tmp.remove();
				done();
			}
		});

		// Module on/off — AJAX toggle (dashboard card + per-module settings banner).
		$(document).on('change', 'input[data-bdrvw-module-toggle]', function () {
			var $input = $(this);
			var slug   = $input.data('bdrvw-module-toggle');
			var active = $input.is(':checked');
			var $card  = $('.bdrvw-module-card[data-module="' + slug + '"]');
			var $nav   = $('.bdrvw-nav__item').filter(function () {
				return ($(this).attr('href') || '').indexOf('module=' + slug) !== -1;
			});

			$input.prop('disabled', true);
			$card.toggleClass('is-off', !active);

			$.post(BdrvwAdmin.ajaxUrl, {
				action: 'bdrvw_toggle_module',
				module: slug,
				active: active ? 1 : 0,
				_nonce: BdrvwAdmin.nonce,
			})
				.done(function (resp) {
					if (resp && resp.success) {
						$nav.toggleClass('is-on', active);
						showToast(active ? BdrvwAdmin.i18n.moduleEnabled : BdrvwAdmin.i18n.moduleDisabled);
						
						if (typeof resp.data.active_count !== 'undefined' && typeof resp.data.total !== 'undefined') {
							$('.bdrvw-app__meta .bdrvw-pill--brand').text(resp.data.active_count + '/' + resp.data.total + ' active');
						}
						
						var $status = $('.bdrvw-module-status');
						if ($status.length) {
							$status.toggleClass('is-off', !active);
							$status.find('.bdrvw-module-status__title').text(active ? BdrvwAdmin.i18n.statusOn : BdrvwAdmin.i18n.statusOff);
							$status.find('.bdrvw-module-status__hint').text(active ? BdrvwAdmin.i18n.statusOnHint : BdrvwAdmin.i18n.statusOffHint);
						}
					} else {
						
						$input.prop('checked', !active);
						$card.toggleClass('is-off', active);
						showToast(BdrvwAdmin.i18n.toggleFailed, true);
					}
				})
				.fail(function () {
					$input.prop('checked', !active);
					$card.toggleClass('is-off', active);
					showToast(BdrvwAdmin.i18n.toggleFailed, true);
				})
				.always(function () {
					$input.prop('disabled', false);
				});
		});

		// === Reviews list: per-row action menu + details / edit panel ===

		function closeRowMenus($except) {
			$('[data-bdrvw-rowmenu].is-open').each(function () {
				if ($except && $except.is(this)) { return; }
				$(this).removeClass('is-open')
					.find('[data-bdrvw-rowmenu-toggle]').attr('aria-expanded', 'false');
			});
		}

		$(document).on('click', '[data-bdrvw-rowmenu-toggle]', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var $menu = $(this).closest('[data-bdrvw-rowmenu]');
			var open  = !$menu.hasClass('is-open');
			closeRowMenus($menu);
			$menu.toggleClass('is-open', open);
			$(this).attr('aria-expanded', open ? 'true' : 'false');
			// Flip upward when the menu would run past the bottom of the viewport.
			$menu.toggleClass('is-up', open && $menu[0].getBoundingClientRect().bottom + 240 > window.innerHeight);
		});

		// A crowned entry does nothing — swallow the click so it doesn't reach the
		// document handler below and collapse the menu the user is reading.
		$(document).on('click', '.bdrvw-rowmenu__item.is-plan', function (e) {
			e.preventDefault();
			e.stopPropagation();
		});

		$(document).on('click', function () { closeRowMenus(); });

		var $panel = $('#bdrvw-review-panel');

		function openPanel(id, mode) {
			if (!$panel.length) { return; }
			closeRowMenus();
			$panel.prop('hidden', false).addClass('is-open');
			$('body').addClass('bdrvw-panel-open');
			$panel.find('[data-bdrvw-panel-body]').html('<div class="bdrvw-panel__loading"><span class="spinner is-active"></span></div>');
			$panel.find('.bdrvw-panel__title').text('');

			$.get(BdrvwAdmin.ajaxUrl, {
				action: 'bdrvw_review_panel',
				id:     id,
				mode:   mode,
				_nonce: BdrvwAdmin.nonce,
			})
				.done(function (resp) {
					if (resp && resp.success && resp.data) {
						$panel.find('.bdrvw-panel__title').text(resp.data.title || '');
						$panel.find('[data-bdrvw-panel-body]').html(resp.data.html || '');
						// Lets anything bound to the panel's markup initialise —
						// it arrives after page load, so init() never sees it.
						$(document).trigger('bdrvw:panel-rendered', [$panel]);
					} else {
						var msg = (resp && resp.data && resp.data.message) || BdrvwAdmin.i18n.panelFailed;
						$panel.find('[data-bdrvw-panel-body]').html($('<p class="bdrvw-panel__error">').text(msg));
					}
				})
				.fail(function () {
					$panel.find('[data-bdrvw-panel-body]').html($('<p class="bdrvw-panel__error">').text(BdrvwAdmin.i18n.panelFailed));
				});
		}

		function closePanel() {
			if (!$panel.length) { return; }
			$panel.removeClass('is-open').prop('hidden', true);
			$panel.find('[data-bdrvw-panel-body]').empty();
			$('body').removeClass('bdrvw-panel-open');
		}

		$(document).on('click', '[data-bdrvw-view]', function (e) {
			e.preventDefault();
			openPanel($(this).data('bdrvw-view'), 'view');
		});

		$(document).on('click', '[data-bdrvw-edit]', function (e) {
			e.preventDefault();
			openPanel($(this).data('bdrvw-edit'), 'edit');
		});

		$(document).on('click', '[data-bdrvw-reply]', function (e) {
			e.preventDefault();
			openPanel($(this).data('bdrvw-reply'), 'reply');
		});

		$(document).on('click', '[data-bdrvw-panel-close]', function (e) {
			e.preventDefault();
			closePanel();
		});

		// ---- Edit review → Attachment -------------------------------------
		// Two kinds of thumbnail share the strip. An already-attached photo
		// carries a hidden photos[] input, so dropping the element is enough for
		// the save to see a shorter list. A freshly picked file has no input —
		// it lives in input.files, which is read-only, so removing one means
		// rebuilding the list through a DataTransfer.
		//
		// Nothing is deleted until the form is saved, which keeps Cancel honest.
		$(document).on('click', '[data-bdrvw-remove-photo]', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('[data-bdrvw-photo-preview]');
			$(this).closest('[data-bdrvw-edit-photo]').remove();
			syncPhotoRoom($wrap);
		});

		$(document).on('click', '[data-bdrvw-remove-new-photo]', function (e) {
			e.preventDefault();
			var $item = $(this).closest('[data-bdrvw-new-photo]');
			var input = $item.closest('[data-bdrvw-uploader]').find('.bdrvw-photos__input')[0];
			var index = parseInt($item.attr('data-index'), 10);

			if (!input || typeof DataTransfer === 'undefined' || isNaN(index)) {
				$item.remove();
				return;
			}
			var dt = new DataTransfer();
			Array.prototype.slice.call(input.files).forEach(function (file, i) {
				if (i !== index) { dt.items.add(file); }
			});
			input.files = dt.files;
			renderNewPhotoPicks(input);
		});

		$(document).on('change', '.bdrvw-photos__input', function () {
			renderNewPhotoPicks(this);
		});

		// Dim the drop area once kept photos plus pending uploads fill the quota.
		function syncPhotoRoom($wrap) {
			if (!$wrap || !$wrap.length) { return; }
			var max = parseInt($wrap.attr('data-max'), 10) || 0;
			var used = $wrap.find('[data-bdrvw-edit-photo], [data-bdrvw-new-photo]').length;
			$wrap.closest('[data-bdrvw-uploader]').toggleClass('is-full', !!max && used >= max);
		}

		function renderNewPhotoPicks(input) {
			var $box = $(input).closest('[data-bdrvw-uploader]');
			var $wrap = $box.find('[data-bdrvw-photo-preview]');
			if (!$wrap.length) { return; }

			$wrap.find('[data-bdrvw-new-photo]').remove();

			var max = parseInt($wrap.attr('data-max'), 10) || 0;
			var kept = $wrap.find('[data-bdrvw-edit-photo]').length;
			var skipped = 0;

			Array.prototype.slice.call(input.files).forEach(function (file, i) {
				if (max && kept + i >= max) { skipped++; return; }

				var url = URL.createObjectURL(file);
				var $item = $(
					'<span class="bdrvw-photos__item is-new" data-bdrvw-new-photo>' +
					'<img class="bdrvw-photos__thumb" alt="" />' +
					'<button type="button" class="bdrvw-photos__remove" data-bdrvw-remove-new-photo>' +
					'<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>' +
					'</button></span>'
				);
				// The index is the position in input.files, which is what the
				// remove handler has to splice — not the position on screen.
				$item.attr('data-index', i);
				$item.find('button').attr('aria-label', BdrvwAdmin.i18n.photoRemove || 'Remove photo');
				$item.find('img').attr('src', url).on('load', function () { URL.revokeObjectURL(url); });
				$wrap.append($item);
			});

			var $err = $box.find('[data-bdrvw-photo-error]');
			if ($err.length) {
				$err.text(skipped > 0 ? (BdrvwAdmin.i18n.photosSkipped || '').replace('%d', String(skipped)) : '');
				$err.prop('hidden', skipped < 1);
			}

			syncPhotoRoom($wrap);
		}

		// The panel arrives over AJAX, so init() never sees its markup.
		$(document).on('bdrvw:panel-rendered', function () {
			$('[data-bdrvw-photo-preview]').each(function () { syncPhotoRoom($(this)); });
		});

		// ---- Post a reply --------------------------------------------------
		$(document).on('submit', '[data-bdrvw-reply-form]', function (e) {
			e.preventDefault();

			var $form = $(this);
			var $btn = $form.find('[data-bdrvw-send-reply]');
			var $text = $form.find('textarea[name="content"]');

			if (!$.trim($text.val())) {
				$text.trigger('focus');
				return;
			}

			var label = $btn.text();
			$btn.prop('disabled', true).text(BdrvwAdmin.i18n.saving || 'Saving…');

			$.post(
				BdrvwAdmin.ajaxUrl,
				$form.serialize() + '&action=bdrvw_add_reply&_nonce=' + encodeURIComponent(BdrvwAdmin.nonce)
			)
				.done(function (resp) {
					if (resp && resp.success) {
						showToast((resp.data && resp.data.message) || BdrvwAdmin.i18n.replySent);
						// Reload so the new reply appears as its own row.
						setTimeout(function () { window.location.reload(); }, 600);
						return;
					}
					showToast((resp && resp.data && resp.data.message) || BdrvwAdmin.i18n.replyFailed, true);
					$btn.prop('disabled', false).text(label);
				})
				.fail(function () {
					showToast(BdrvwAdmin.i18n.replyFailed, true);
					$btn.prop('disabled', false).text(label);
				});
		});

		// ---- Save the edit form -------------------------------------------
		$(document).on('submit', '[data-bdrvw-edit-form]', function (e) {
			var $form = $(this);
			var $btn = $form.find('[data-bdrvw-save]');
			if (!$btn.length) { return; }

			e.preventDefault();

			var label = $btn.text();
			$btn.prop('disabled', true).text(BdrvwAdmin.i18n.saving || 'Saving…');

			// FormData rather than serialize(): the Attachment field can carry
			// newly picked files, and serialize() silently drops file inputs.
			var payload = new FormData(this);
			payload.append('action', 'bdrvw_save_review');
			payload.append('_nonce', BdrvwAdmin.nonce);

			$.ajax({
				url: BdrvwAdmin.ajaxUrl,
				method: 'POST',
				data: payload,
				processData: false, // Let the browser build the multipart body…
				contentType: false  // …and set its own boundary header.
			})
				.done(function (resp) {
					if (resp && resp.success) {
						showToast((resp.data && resp.data.message) || BdrvwAdmin.i18n.reviewSaved);
						// Reload so the row picks up the edited values.
						setTimeout(function () { window.location.reload(); }, 600);
						return;
					}
					showToast((resp && resp.data && resp.data.message) || BdrvwAdmin.i18n.reviewFailed, true);
					$btn.prop('disabled', false).text(label);
				})
				.fail(function () {
					showToast(BdrvwAdmin.i18n.reviewFailed, true);
					$btn.prop('disabled', false).text(label);
				});
		});

		$(document).on('keyup', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				closeRowMenus();
				closePanel();
			}
		});

		var toastTimer = null;
		function showToast(msg, isError) {
			var $t = $('#bdrvw-toast');
			if (!$t.length) {
				return;
			}
			$t.find('.bdrvw-toast__text').text(msg);
			$t.toggleClass('is-error', !!isError);
			$t.addClass('is-visible');
			clearTimeout(toastTimer);
			toastTimer = setTimeout(function () {
				$t.removeClass('is-visible');
			}, 2200);
		}
	});
})(jQuery);
