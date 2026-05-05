/*
 * JCI-EP-WEB-Patch — chrome rond #grCanvas (breadcrumb, toolbar, zoom).
 * Copyright (C) 2026 Dymotica B.V. <info@dymotica.nl>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 * ----
 *
 * Wraps the legacy `#grCanvas` (rendered by graphic.min.js) with:
 *   - a breadcrumb above the card (sidebar location → graphic title)
 *   - a light-gray toolbar atop the card (title left, zoom controls right)
 *   - auto-fit-to-viewport scaling on load / resize / graphic-swap
 *   - manual +/- buttons and mouse-wheel zoom (centered on cursor)
 *
 * NEVER touches #grCanvas itself; never overrides its dimensions. CSS
 * `zoom` is set on a parent `.jci-graphic-scale` wrapper so graphic.min.js
 * keeps full ownership of #grCanvas's geometry. Setting width/display on
 * #grCanvas crashes Apex inside graphic widgets ("<rect> attribute x:
 * Expected length, 'Infinity'") because the chart can't measure its parent.
 *
 * Why CSS `zoom` and not `transform: scale`?
 * - `zoom` is non-standard but on Chromium it scales BOTH visuals AND
 *   measurements (offsetWidth, mouse coords). graphic.min.js's click
 *   hit-tests therefore stay accurate when the graphic is zoomed.
 * - `transform: scale` only scales visuals; coordinates would be off,
 *   breaking interactive widgets in the graphic.
 *
 * IMPORTANT: this file is loaded from <head> (alongside the other JCI
 * scripts), so we MUST defer everything to $(document).ready — at script-
 * load time the body markup doesn't exist yet, so any selector check
 * runs against an empty DOM and silently bails.
 */
(function ($) {
	'use strict';
	if (!window.jQuery) return;

	$(function () {
		var $page = $('.jci-graphic-page');
		if (!$page.length) return;

		var $scale    = $('#jci-graphic-scale');
		var $viewport = $('#jci-graphic-viewport');
		var $level    = $('#jci-graphic-zoom-level');
		if (!$scale.length || !$viewport.length) return;

		var Z_MIN = 0.10, Z_MAX = 4, Z_STEP = 0.10;
		var WHEEL_STEP = 0.10;
		var userOverride = false;

		function getZoom() {
			var z = parseFloat($scale[0].style.zoom);
			return isFinite(z) && z > 0 ? z : 1;
		}

		function applyZoom(z) {
			z = Math.max(Z_MIN, Math.min(Z_MAX, z));
			$scale[0].style.zoom = z;
			$level.text(Math.round(z * 100) + '%');
			updateOverflow(z);
			return z;
		}

		// Auto-fit: pick the largest zoom <= 1 that makes #grCanvas fit
		// inside the viewport. Measures #grCanvas itself (NOT the wrapper
		// — wrapper's scrollWidth is unreliable with floated children).
		function fitToViewport() {
			var canvas = document.getElementById('grCanvas');
			if (!canvas) return false;
			$scale[0].style.zoom = 1;
			void $scale[0].offsetHeight;   // force reflow before measuring
			var iw = canvas.offsetWidth || canvas.scrollWidth;
			var ih = canvas.offsetHeight || canvas.scrollHeight;
			var v  = $viewport[0];
			var vw = v.clientWidth;
			var vh = v.clientHeight;
			if (iw <= 0 || ih <= 0 || vw <= 0 || vh <= 0) return false;
			var z = Math.min(1, vw / iw, vh / ih);
			applyZoom(z);
			userOverride = false;
			updateOverflow(z, true);
			return true;
		}

		function updateOverflow(z, forceFit) {
			var v = $viewport[0];
			if (!v) return;
			var canvas = document.getElementById('grCanvas');
			if (!canvas || forceFit || !userOverride) {
				v.style.overflow = 'hidden';
				v.scrollLeft = 0; v.scrollTop = 0;
				return;
			}
			var iw = (canvas.offsetWidth  || 0) * z;
			var ih = (canvas.offsetHeight || 0) * z;
			v.style.overflow = (iw > v.clientWidth || ih > v.clientHeight) ? 'auto' : 'hidden';
		}

		// Zoom toward a viewport-relative point. Adjust scroll so the
		// content-coordinate under the cursor stays at the same screen pos.
		function zoomAt(zNew, cursorX, cursorY) {
			var v = $viewport[0];
			if (!v) { applyZoom(zNew); return; }
			var zOld = getZoom();
			var contentX = (v.scrollLeft + cursorX) / zOld;
			var contentY = (v.scrollTop  + cursorY) / zOld;
			applyZoom(zNew);
			var zReal = getZoom();
			v.scrollLeft = Math.max(0, contentX * zReal - cursorX);
			v.scrollTop  = Math.max(0, contentY * zReal - cursorY);
		}

		// --- Breadcrumb / title ---------------------------------------
		function buildBreadcrumb() {
			var $active = $('.mt-left-sidebar .sidebar-elements li.active').last();
			if (!$active.length) return '';
			var parts = [];
			function label($li) {
				var $a = $li.children('a').first();
				if ($a.length) return ($a.find('span').first().text() || $a.text()).trim();
				return $li.text().trim();
			}
			parts.unshift(label($active));
			var $cur = $active.parent('ul').parent('li');
			while ($cur && $cur.length) {
				var t = label($cur);
				if (t) parts.unshift(t);
				$cur = $cur.parent('ul').parent('li');
			}
			return parts.filter(Boolean).join('  /  ');
		}
		function fallbackTitle() {
			var t = (document.title || '').trim();
			return t.replace(/^Johnson Controls\s*[-—|·]\s*/i, '').trim();
		}
		function updateBreadcrumb() {
			var crumb = buildBreadcrumb();
			var leaf  = (crumb.split('/').pop() || '').trim() || fallbackTitle();
			$('#jci-graphic-breadcrumb').text(crumb || leaf || '');
			$('#jci-graphic-title-bar').text(leaf || '');
		}

		var fitTimer = null;
		function scheduleFit() {
			if (userOverride) return;
			clearTimeout(fitTimer);
			fitTimer = setTimeout(fitToViewport, 150);
		}

		function observeGraphicSwap() {
			if (!window.MutationObserver) return;
			var canvas = document.getElementById('grCanvas');
			if (!canvas) return;
			var obs = new MutationObserver(function () {
				updateBreadcrumb();
				scheduleFit();
			});
			obs.observe(canvas, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'width', 'height'] });
		}
		function observeSidebar() {
			if (!window.MutationObserver) return;
			var nav = document.getElementById('leftXmlMenu');
			if (!nav) return;
			var obs = new MutationObserver(function () { updateBreadcrumb(); });
			obs.observe(nav, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
		}

		function deferredFit() {
			var attempts = 0;
			var iv = setInterval(function () {
				attempts++;
				var canvas = document.getElementById('grCanvas');
				var ready = canvas && canvas.offsetWidth > 0 && canvas.offsetHeight > 0;
				if (ready) {
					if (!userOverride) fitToViewport();
					updateBreadcrumb();
					clearInterval(iv);
				} else if (attempts > 60) {
					clearInterval(iv);
				}
			}, 200);
		}

		// --- Bindings -----------------------------------------------
		$('#jci-graphic-zoom-in').on('click', function () {
			userOverride = true;
			var v = $viewport[0];
			zoomAt(getZoom() + Z_STEP, v.clientWidth / 2, v.clientHeight / 2);
		});
		$('#jci-graphic-zoom-out').on('click', function () {
			userOverride = true;
			var v = $viewport[0];
			zoomAt(getZoom() - Z_STEP, v.clientWidth / 2, v.clientHeight / 2);
		});
		$('#jci-graphic-zoom-fit').on('click', function () { fitToViewport(); });

		// Mouse wheel anywhere over the viewport → zoom toward cursor.
		$viewport.on('wheel', function (ev) {
			ev.preventDefault();
			var oe = ev.originalEvent || ev;
			var rect = $viewport[0].getBoundingClientRect();
			var cx = oe.clientX - rect.left;
			var cy = oe.clientY - rect.top;
			var dir = oe.deltaY < 0 ? +1 : -1;
			userOverride = true;
			zoomAt(getZoom() + dir * WHEEL_STEP, cx, cy);
		});

		var rIv;
		$(window).on('resize', function () {
			clearTimeout(rIv);
			rIv = setTimeout(function () {
				if (!userOverride) fitToViewport();
			}, 120);
		});

		updateBreadcrumb();
		observeSidebar();
		observeGraphicSwap();
		deferredFit();
	});
})(jQuery);
