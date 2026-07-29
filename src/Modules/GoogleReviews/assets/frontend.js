(function () {
	'use strict';

	function onReady(fn) {
		if (document.readyState !== 'loading') { fn(); }
		else { document.addEventListener('DOMContentLoaded', fn); }
	}

	function initSliders(root) {
    var scope = root || document;
    if (typeof window.BdrvwSwiper === 'undefined') { return; }
    
    Array.prototype.forEach.call(scope.querySelectorAll('[data-bdrvw-gr-slider]'), function (el) {
        if (el.swiper) { 
            el.swiper.update(); 
            return; 
        }
        
        var scope2 = el.closest('.bdrvw-google__slider-shell, .bdrvw-google__sbslider') || el;
        var single = el.hasAttribute('data-bdrvw-gr-single');
        var config = {
            slidesPerView: 1,
            spaceBetween: 16,
            watchOverflow: true
        };

        // Only wire the modules whose elements are actually present. The sidebar
        // slider, for instance, has arrows but no pagination dots — handing Swiper
        // a null pagination element can leave the whole slider un-initialised.
        var nextEl = scope2.querySelector('.swiper-button-next');
        var prevEl = scope2.querySelector('.swiper-button-prev');
        if (nextEl && prevEl) {
            config.navigation = { nextEl: nextEl, prevEl: prevEl };
        }
        var pagEl = el.querySelector('.swiper-pagination');
        if (pagEl) {
            config.pagination = { el: pagEl, clickable: true };
        }
        if (single) {
            // Height follows the current review instead of the tallest one.
            config.autoHeight = true;
        } else {
            // Cards-per-view comes from data-columns (set by the shortcode/admin),
            // mirroring the grid: mobile 1, tablet min(cols,2), desktop cols.
            var cols = parseInt(el.getAttribute('data-columns'), 10);
            if (!isFinite(cols) || cols < 1) { cols = 3; }
            if (cols > 4) { cols = 4; }
            config.breakpoints = {
                640: { slidesPerView: Math.min(cols, 2) },
                1024: { slidesPerView: cols }
            };
        }
        var swiper = new window.BdrvwSwiper(el, config);

        if (window.ResizeObserver) {
            // Only react to WIDTH changes. swiper.update() (with autoHeight)
            // changes the element's height, which would otherwise re-trigger the
            // observer endlessly — an infinite ResizeObserver loop.
            var lastWidth = el.clientWidth;
            new window.ResizeObserver(function () {
                var w = el.clientWidth;
                if (w === lastWidth) { return; }
                lastWidth = w;
                swiper.update();
            }).observe(el);
        } else {
            window.addEventListener('load', function () { swiper.update(); });
        }
    });
}

	window.BdrvwGR = window.BdrvwGR || {};
	window.BdrvwGR.initSliders = initSliders;

	
	function initReadMore(root) {
		var scope = root || document;
		Array.prototype.forEach.call(scope.querySelectorAll('[data-bdrvw-truncate].is-truncatable'), function (box) {
			if (box.scrollHeight <= box.clientHeight + 2) {
				box.classList.remove('is-truncatable');
				var wrap = box.closest('.bdrvw-google__grid-card, .bdrvw-google__sbslide') || box.parentElement;
				var btn = wrap ? wrap.querySelector('[data-bdrvw-toggle]') : null;
				if (btn) { btn.hidden = true; }
			}
		});
	}
	window.BdrvwGR.initReadMore = initReadMore;

	function toggle(btn) {
		var scope = btn.closest('.bdrvw-google__grid-card, .bdrvw-google__sbslide') || btn.parentElement;
		var box = scope ? scope.querySelector('[data-bdrvw-truncate]') : null;
		if (!box) { return; }
		var open = box.classList.toggle('is-open');
		box.style.maxHeight = open ? box.scrollHeight + 'px' : '';
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		var more = btn.querySelector('[data-bdrvw-label-more]');
		var less = btn.querySelector('[data-bdrvw-label-less]');
		if (more) { more.hidden = open; }
		if (less) { less.hidden = !open; }
		
		var sw = btn.closest('.swiper');
		if (sw && sw.swiper) {
			var swiper = sw.swiper;
			swiper.updateAutoHeight(300);
			var settle = function () {
				swiper.updateAutoHeight(0);
				box.removeEventListener('transitionend', settle);
			};
			box.addEventListener('transitionend', settle);
		}
	}

	// === Review "like" toggle (local, per-visitor) =======================
	function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
	function lsDel(k) { try { window.localStorage.removeItem(k); } catch (e) {} }

	function paintLike(btn, liked) {
		var base = parseInt(btn.getAttribute('data-base'), 10) || 0;
		btn.classList.toggle('is-liked', liked);
		btn.setAttribute('aria-pressed', liked ? 'true' : 'false');
		var c = btn.querySelector('.bdrvw-google__grid-like-count');
		if (c) { c.textContent = String(base + (liked ? 1 : 0)); }
	}
	function initLikes(root) {
		var scope = root || document;
		Array.prototype.forEach.call(scope.querySelectorAll('[data-bdrvw-like]'), function (btn) {
			paintLike(btn, lsGet('bdrvw_like_' + btn.getAttribute('data-review-id')) === '1');
		});
	}
	window.BdrvwGR.initLikes = initLikes;

	function toggleLike(btn) {
		var key = 'bdrvw_like_' + btn.getAttribute('data-review-id');
		var liked = lsGet(key) !== '1';
		if (liked) { lsSet(key, '1'); } else { lsDel(key); }
		paintLike(btn, liked);
	}

	// === Share popup (Google-style) ======================================
	var shareOverlay = null;
	function closeShareModal() {
		if (shareOverlay && shareOverlay.parentNode) { shareOverlay.parentNode.removeChild(shareOverlay); }
		shareOverlay = null;
	}
	var SHARE_ICONS = {
		fb: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#1877f2"/><path fill="#fff" d="M13.3 19v-6h1.7l.3-2.1h-2V9.6c0-.6.2-1 1.1-1h1V6.7c-.2 0-.9-.1-1.6-.1-1.6 0-2.7.9-2.7 2.7v1.6H9.4V13h1.7v6h2.2z"/></svg>',
		wa: '<svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="#25d366"/><path fill="#fff" d="M16 7.5c-4.7 0-8.5 3.8-8.5 8.5 0 1.5.4 2.9 1.1 4.1l-1.2 4.4 4.5-1.2c1.2.6 2.5 1 3.9 1 4.7 0 8.5-3.8 8.5-8.5S20.7 7.5 16 7.5zm0 15.4c-1.3 0-2.5-.3-3.5-.9l-.3-.2-2.6.7.7-2.6-.2-.3c-.7-1.1-1-2.3-1-3.6 0-3.7 3-6.7 6.7-6.7s6.7 3 6.7 6.7-3 6.9-6.2 6.9zm3.8-5c-.2-.1-1.2-.6-1.4-.7-.2-.1-.3-.1-.5.1-.1.2-.5.7-.6.8-.1.1-.2.1-.4 0-1.2-.6-2-1.1-2.9-2.5-.2-.4.2-.3.6-1.1.1-.1 0-.3 0-.4 0-.1-.5-1.1-.6-1.5-.2-.4-.3-.4-.5-.4h-.4c-.1 0-.4.1-.5.3-.2.2-.7.7-.7 1.7s.7 1.9.8 2.1c.1.1 1.4 2.2 3.5 3.1 1.3.6 1.9.6 2.5.5.4-.1 1.2-.5 1.3-1 .2-.5.2-.9.1-1-.1-.1-.2-.1-.4-.2z"/></svg>',
		tw: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="5" fill="#000"/><path fill="#fff" d="M16.8 6h2.1l-4.6 5.2 5.4 7.1h-4.2l-3.3-4.3-3.8 4.3H6.3l4.9-5.6L6 6h4.3l3 4 3.5-4zm-.7 11.1h1.2L9.3 7.2H8l8.1 9.9z"/></svg>',
		em: '<svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#4caf50" d="M45 16.2l-5 2.75-5 4.75L35 40h7c1.657 0 3-1.343 3-3V16.2z"/><path fill="#1e88e5" d="M3 16.2l3.614 1.71L13 23.7V40H6c-1.657 0-3-1.343-3-3V16.2z"/><path fill="#e53935" d="M35 11.2L24 19.45 13 11.2 12 17l1 6.7 11 8.25 11-8.25 1-6.7z"/><path fill="#c62828" d="M3 12.298V16.2l10 7.5V11.2L9.876 8.859C9.132 8.301 8.228 8 7.298 8C4.924 8 3 9.924 3 12.298z"/><path fill="#fbc02d" d="M45 12.298V16.2l-10 7.5V11.2l3.124-2.341C38.868 8.301 39.772 8 40.702 8C43.076 8 45 9.924 45 12.298z"/></svg>'
	};
	function shareRow(cls, href, icon, label, isMail) {
		return '<a class="bdrvw-google__share-row ' + cls + '" ' + (isMail ? '' : 'target="_blank" rel="noopener" ') + 'href="' + href + '">' +
			'<span class="bdrvw-google__share-ico">' + icon + '</span>' +
			'<span class="bdrvw-google__share-name">' + label + '</span>' +
		'</a>';
	}
	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.focus(); ta.select();
		try { document.execCommand('copy'); } catch (e) {}
		document.body.removeChild(ta);
	}
	function buildShareModal(url, text) {
		closeShareModal();
		var enc = encodeURIComponent(url);
		var check = encodeURIComponent('Check this out!');
		var ov = document.createElement('div');
		ov.className = 'bdrvw-google__share-overlay';
		ov.innerHTML =
			'<div class="bdrvw-google__share-modal" role="dialog" aria-modal="true" aria-label="Share">' +
				'<span class="bdrvw-google__share-close" role="button" tabindex="0" aria-label="Close">&times;</span>' +
				'<h3 class="bdrvw-google__share-title">Share</h3>' +
				shareRow('is-fb', 'https://www.facebook.com/sharer/sharer.php?u=' + enc, SHARE_ICONS.fb, 'Facebook') +
				shareRow('is-wa', 'https://api.whatsapp.com/send?text=' + enc, SHARE_ICONS.wa, 'WhatsApp') +
				shareRow('is-tw', 'https://twitter.com/intent/tweet?url=' + enc + '&text=' + check, SHARE_ICONS.tw, 'Twitter') +
				shareRow('is-em', 'https://mail.google.com/mail/?view=cm&fs=1&su=' + check + '&body=' + enc, SHARE_ICONS.em, 'Email') +
				'<div class="bdrvw-google__share-url"></div>' +
				'<span class="bdrvw-google__share-copy" role="button" tabindex="0">Copy</span>' +
			'</div>';
		document.body.appendChild(ov);
		shareOverlay = ov;
		ov.querySelector('.bdrvw-google__share-url').textContent = url;
		var copyBtn = ov.querySelector('.bdrvw-google__share-copy');
		function doCopy() {
			var done = function () { copyBtn.textContent = 'Copied!'; setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500); };
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(done, function () { fallbackCopy(url); done(); });
			} else {
				fallbackCopy(url); done();
			}
		}
		copyBtn.addEventListener('click', doCopy);
		copyBtn.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); doCopy(); } });
		ov.addEventListener('click', function (e) {
			if (e.target === ov || e.target.closest('.bdrvw-google__share-close')) { closeShareModal(); }
		});
		ov.addEventListener('keydown', function (e) {
			if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('.bdrvw-google__share-close')) { e.preventDefault(); closeShareModal(); }
		});
	}
	function openShare(btn) {
		var url = btn.getAttribute('data-share-url') || window.location.href || '';
		if (!url) { url = window.location.href; }
		var text = btn.getAttribute('data-share-text') || '';
		// Always show the custom popup (never the browser's native share sheet).
		buildShareModal(url, text);
	}

	// === Popup layout — slide-in offcanvas ================================
	var openOffcanvas = null;

	function showOffcanvas(oc) {
		if (!oc) { return; }
		closeOffcanvas();
		oc.classList.add('is-open');
		document.documentElement.classList.add('bdrvw-gr-offcanvas-open');
		openOffcanvas = oc;
	}

	function closeOffcanvas() {
		if (!openOffcanvas) { return; }
		openOffcanvas.classList.remove('is-open');
		document.documentElement.classList.remove('bdrvw-gr-offcanvas-open');
		openOffcanvas = null;
	}

	onReady(function () {
		initSliders(document);
		initReadMore(document);
		initLikes(document);

		document.addEventListener('click', function (e) {
			var toggleBtn = e.target.closest('[data-bdrvw-toggle]');
			if (toggleBtn) {
				e.preventDefault();
				toggle(toggleBtn);
				return;
			}

			var likeBtn = e.target.closest('[data-bdrvw-like]');
			if (likeBtn) {
				e.preventDefault();
				toggleLike(likeBtn);
				return;
			}

			var shareBtn = e.target.closest('[data-bdrvw-share]');
			if (shareBtn) {
				e.preventDefault();
				openShare(shareBtn);
				return;
			}

			// Open the offcanvas from the review badge.
			var opener = e.target.closest('[data-bdrvw-gr-popup-open]');
			if (opener) {
				e.preventDefault();
				var wrap = opener.closest('.bdrvw-google__popup') || document;
				showOffcanvas(wrap.querySelector('[data-bdrvw-gr-offcanvas]'));
				return;
			}

			// Close (X button or overlay).
			if (e.target.closest('[data-bdrvw-gr-popup-close]')) {
				e.preventDefault();
				closeOffcanvas();
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && openOffcanvas) { closeOffcanvas(); }
			if (e.key === 'Escape' && shareOverlay) { closeShareModal(); }
		});
	});
}());
