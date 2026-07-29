/* global BdrvwFront */
(function () {
	'use strict';

	var i18n = (window.BdrvwFront && BdrvwFront.i18n) || {};

	function setRating(container, value) {
		value = Math.max(0, Math.min(5, parseInt(value, 10) || 0));
		var input = container.querySelector('input[type="hidden"]');
		if (input) {
			input.value = String(value);
		}
		container.setAttribute('data-rating', String(value));
		container.classList.toggle('selected', value > 0);

		// Star template (anchor-based)
		container.querySelectorAll('.bdrvw-rating-input__star').forEach(function (star) {
			var v = parseInt(star.getAttribute('data-value'), 10);
			var active = v <= value;
			star.classList.toggle('is-active', active);
			star.classList.toggle('active', v === value);
			star.setAttribute('aria-checked', v === value ? 'true' : 'false');
			star.setAttribute('tabindex', v === value ? '0' : '-1');
		});

		// Chip-based templates (bar / square / pill — buttons with role=radio)
		container.querySelectorAll('.bdrvw-rating-input__chip').forEach(function (chip) {
			var v = parseInt(chip.getAttribute('data-value'), 10);
			var active = v <= value;
			chip.classList.toggle('is-active', active);
			chip.setAttribute('aria-checked', v === value ? 'true' : 'false');
			chip.setAttribute('tabindex', v === value ? '0' : '-1');
		});

		// Slider template — sync the range input + the output display
		var slider = container.querySelector('.bdrvw-rating-slider');
		if (slider && Number(slider.value) !== value) {
			slider.value = String(value);
		}
		var output = container.querySelector('.bdrvw-rating-output');
		if (output) {
			output.textContent = value + ' / 5';
		}

		// If nothing is selected, keep the first interactive element tabbable.
		if (value <= 0) {
			var first = container.querySelector('.bdrvw-rating-input__star, .bdrvw-rating-input__chip');
			if (first) { first.setAttribute('tabindex', '0'); }
		}
	}

	function bindStarLike(input, selector) {
		var items = Array.prototype.slice.call(input.querySelectorAll(selector));
		items.forEach(function (item, idx) {
			item.addEventListener('mouseenter', function () {
				var v = parseInt(item.getAttribute('data-value'), 10);
				items.forEach(function (s) {
					var sv = parseInt(s.getAttribute('data-value'), 10);
					s.classList.toggle('is-hover', sv <= v);
				});
			});
			item.addEventListener('mouseleave', function () {
				items.forEach(function (s) { s.classList.remove('is-hover'); });
			});
			item.addEventListener('click', function (e) {
				e.preventDefault();
				setRating(input, parseInt(item.getAttribute('data-value'), 10));
				item.focus();
			});
			item.addEventListener('keydown', function (e) {
				var next = null;
				switch (e.key) {
					case 'ArrowRight':
					case 'ArrowUp':
						next = items[Math.min(idx + 1, items.length - 1)];
						break;
					case 'ArrowLeft':
					case 'ArrowDown':
						next = items[Math.max(idx - 1, 0)];
						break;
					case 'Home':
						next = items[0];
						break;
					case 'End':
						next = items[items.length - 1];
						break;
					case ' ':
					case 'Enter':
						e.preventDefault();
						setRating(input, parseInt(item.getAttribute('data-value'), 10));
						return;
				}
				if (next) {
					e.preventDefault();
					setRating(input, parseInt(next.getAttribute('data-value'), 10));
					next.focus();
				}
			});
		});
	}

	function bindRatingInputs(scope) {
		(scope || document).querySelectorAll('.bdrvw-rating-input').forEach(function (input) {
			// Star template (anchors)
			if (input.querySelector('.bdrvw-rating-input__star')) {
				bindStarLike(input, '.bdrvw-rating-input__star');
			}
			// Chip-based templates (bar / square / pill — buttons)
			if (input.querySelector('.bdrvw-rating-input__chip')) {
				bindStarLike(input, '.bdrvw-rating-input__chip');
			}
			// Slider template — listen to native input events
			var slider = input.querySelector('.bdrvw-rating-slider');
			if (slider) {
				slider.addEventListener('input', function () {
					setRating(input, slider.value);
				});
				slider.addEventListener('change', function () {
					setRating(input, slider.value);
				});
			}
		});
	}

	// Thumbnails for the files just picked, plus the same count/size limits the
	// server enforces — so an oversized file is caught before the upload, not
	// silently dropped after it.
	// Drop one file from the picked set. input.files is read-only, so the list
	// has to be rebuilt through a DataTransfer and assigned back — that keeps
	// the native input as the single source of truth for what gets submitted.
	function removePhotoAt(input, index) {
		if (typeof DataTransfer === 'undefined') { return; }
		var dt = new DataTransfer();
		Array.prototype.slice.call(input.files).forEach(function (file, i) {
			if (i !== index) { dt.items.add(file); }
		});
		input.files = dt.files;
		renderPhotoPreview(input);
	}

	function renderPhotoPreview(input) {
		var box = input.closest('.bdrvw-uploader');
		if (!box) { return; }
		var wrap = box.querySelector('[data-bdrvw-photo-preview]');
		var errorBox = box.querySelector('[data-bdrvw-photo-error]');
		if (!wrap) { return; }

		wrap.innerHTML = '';
		var max = BdrvwFront.photoMax || 0;
		var maxBytes = BdrvwFront.photoMaxBytes || 0;
		var rejected = 0;

		Array.prototype.slice.call(input.files).forEach(function (file, i) {
			if ((max && i >= max) || (maxBytes && file.size > maxBytes)) {
				rejected++;
				return;
			}

			var item = document.createElement('span');
			item.className = 'bdrvw-photos__item';

			var img = document.createElement('img');
			img.className = 'bdrvw-photos__thumb';
			img.alt = '';
			img.src = URL.createObjectURL(file);
			img.addEventListener('load', function () { URL.revokeObjectURL(img.src); });
			item.appendChild(img);

			var remove = document.createElement('button');
			remove.type = 'button'; // Not "submit" — this sits inside the review form.
			remove.className = 'bdrvw-photos__remove';
			remove.setAttribute('aria-label', i18n.photoRemove || 'Remove photo');
			remove.innerHTML = '<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>';
			// The index is captured here rather than read from the DOM, so it can
			// never drift out of step with input.files after a re-render.
			remove.addEventListener('click', function () { removePhotoAt(input, i); });
			item.appendChild(remove);

			wrap.appendChild(item);
		});

		if (errorBox) {
			if (rejected > 0) {
				errorBox.textContent = (i18n.photosSkipped || '%d file(s) were skipped — too many, or too large.')
					.replace('%d', String(rejected));
				errorBox.hidden = false;
			} else {
				errorBox.textContent = '';
				errorBox.hidden = true;
			}
		}
	}

	function bindPhotoInputs(scope) {
		(scope || document).querySelectorAll('.bdrvw-photos__input').forEach(function (input) {
			if (input.dataset.bdrvwBound === '1') { return; }
			input.dataset.bdrvwBound = '1';
			input.addEventListener('change', function () { renderPhotoPreview(input); });

			// The dashed box invites a drag, so make it accept one. DataTransfer
			// is assignable to input.files in every browser that supports the
			// drop events, which keeps the form submitting exactly as before.
			var drop = input.closest('.bdrvw-uploader');
			drop = drop && drop.querySelector('[data-bdrvw-photo-drop]');
			if (!drop || typeof DataTransfer === 'undefined') { return; }

			['dragenter', 'dragover'].forEach(function (evt) {
				drop.addEventListener(evt, function (e) {
					e.preventDefault();
					drop.classList.add('is-dragover');
				});
			});
			['dragleave', 'dragend', 'drop'].forEach(function (evt) {
				drop.addEventListener(evt, function () { drop.classList.remove('is-dragover'); });
			});
			drop.addEventListener('drop', function (e) {
				if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) { return; }
				e.preventDefault();
				var dt = new DataTransfer();
				Array.prototype.slice.call(e.dataTransfer.files).forEach(function (file) {
					if (file.type && file.type.indexOf('image/') === 0) { dt.items.add(file); }
				});
				if (!dt.files.length) { return; }
				input.files = dt.files;
				renderPhotoPreview(input);
			});
		});
	}

	function clearErrors(form) {
		form.querySelectorAll('.bdrvw-field-error').forEach(function (el) { el.remove(); });
		form.querySelectorAll('.has-error').forEach(function (el) { el.classList.remove('has-error'); });
	}

	function showNotice(form, type, message) {
		var box = form.querySelector('.bdrvw-form__messages');
		if (!box) return;
		box.innerHTML = '<div class="bdrvw-notice bdrvw-notice--' + type + '"></div>';
		box.querySelector('.bdrvw-notice').textContent = message;
	}

	function showFieldError(form, name, message) {
		var input = form.querySelector('[name="' + name + '"]');
		// If the field isn't on the form (e.g. main rating is hidden because
		// criteria are configured), surface the message near the criteria
		// block instead of silently swallowing it.
		if (!input) {
			var crit = form.querySelector('.bdrvw-criteria-input');
			if (crit) {
				crit.classList.add('has-error');
				var fallback = document.createElement('div');
				fallback.className = 'bdrvw-field-error';
				fallback.textContent = message;
				crit.parentNode.insertBefore(fallback, crit);
				if (typeof crit.scrollIntoView === 'function') {
					crit.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			}
			return;
		}
		input.classList.add('has-error');
		var err = document.createElement('div');
		err.className = 'bdrvw-field-error';
		err.textContent = message;
		input.parentNode.appendChild(err);
	}

	function submitForm(form) {
		clearErrors(form);

		var data = {};
		var formData = new FormData(form);
		formData.forEach(function (value, key) {
			if (key === 'criteria' || key.indexOf('criteria[') === 0) {
				// criteria[name] handled below
				return;
			}
			// Files are appended separately: this loop keys by name, so several
			// photos sharing one field name would collapse into the last one.
			if (value instanceof File) {
				return;
			}
			data[key] = value;
		});

		// Collect criteria ratings.
		data.criteria = {};
		form.querySelectorAll('input[name^="criteria["]').forEach(function (el) {
			var m = el.name.match(/criteria\[(.+)\]/);
			if (m && m[1]) {
				data.criteria[m[1]] = el.value;
			}
		});


		var payload = new FormData();
		payload.append('action', 'bdrvw_submit');
		payload.append('_nonce', BdrvwFront.nonce);
		Object.keys(data).forEach(function (k) {
			if (k === 'criteria') {
				Object.keys(data.criteria).forEach(function (ck) {
					payload.append('data[criteria][' + ck + ']', data.criteria[ck]);
				});
			} else {
				payload.append('data[' + k + ']', data[k]);
			}
		});

		// Photos ride outside `data[]` so they land in $_FILES, where PHP can
		// see the temp paths — a file nested in a data[] key would not.
		var photoInput = form.querySelector('.bdrvw-photos__input');
		if (photoInput && photoInput.files) {
			var max = (BdrvwFront.photoMax || 0);
			for (var i = 0; i < photoInput.files.length; i++) {
				if (max && i >= max) { break; }
				payload.append(photoInput.name, photoInput.files[i]);
			}
		}

		var submitBtn = form.querySelector('button[type="submit"]');
		if (submitBtn) {
			submitBtn.disabled = true;
			submitBtn.dataset.originalText = submitBtn.textContent;
			submitBtn.textContent = i18n.submitting || 'Submitting…';
		}

		fetch(BdrvwFront.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: payload
		})
			.then(function (res) { return res.json().then(function (json) { return { status: res.status, json: json }; }); })
			.then(function (result) {
				if (result.json && result.json.success) {
					showNotice(form, 'success', (result.json.data && result.json.data.message) || i18n.success);
					form.reset();
					form.querySelectorAll('.bdrvw-rating-input').forEach(function (el) { setRating(el, 0); });
					// form.reset() empties the file input but leaves the thumbnails.
					form.querySelectorAll('[data-bdrvw-photo-preview]').forEach(function (el) { el.innerHTML = ''; });
					form.querySelectorAll('[data-bdrvw-photo-error]').forEach(function (el) { el.textContent = ''; el.hidden = true; });

					// When the server returns freshly-rendered list HTML (auto-
					// approved submission), swap it into the existing list wrap
					// so the new review surfaces without a page reload.
					var data = (result.json && result.json.data) || {};
					if (data.list_html && data.post_id) {
						var wrap = document.querySelector('.bdrvw-list-wrap[data-post-id="' + data.post_id + '"]');
						if (wrap) {
							var tmp = document.createElement('div');
							tmp.innerHTML = data.list_html;
							var fresh = tmp.querySelector('.bdrvw-list-wrap[data-post-id="' + data.post_id + '"]');
							if (fresh) {
								wrap.replaceWith(fresh);
							}
						}
					}
				} else {
					var msg = (result.json && result.json.data && result.json.data.message) || i18n.errorGeneric;
					showNotice(form, 'error', msg);
					if (result.json && result.json.data && result.json.data.fields) {
						Object.keys(result.json.data.fields).forEach(function (name) {
							showFieldError(form, name, result.json.data.fields[name]);
						});
					}
				}
			})
			.catch(function () {
				showNotice(form, 'error', i18n.errorGeneric);
			})
			.then(function () {
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.textContent = submitBtn.dataset.originalText || 'Submit';
				}
			});
	}

	function init() {
		bindRatingInputs(document);
		bindPhotoInputs(document);

		document.querySelectorAll('.bdrvw-form').forEach(function (form) {
			if (form.dataset.bdrvwBound === '1') { return; }
			form.dataset.bdrvwBound = '1';
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				submitForm(form);
			});
		});
	}

	// Defensive init: if script runs after the page is already interactive
	// (defer/async/cache plugin), DOMContentLoaded would never re-fire and
	// the click handlers would silently not attach.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
