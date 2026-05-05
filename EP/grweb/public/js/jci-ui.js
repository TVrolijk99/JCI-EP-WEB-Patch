/*
 * JCI-EP-WEB-Patch — topbar + zijbalk + alarmbel polling + hash-trigger.
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
 *  - Sidebar: in-place expand/collapse for sub-menus
 *  - Header: bell icon turns red when there are active alarms
 *  - Right-side panel: graphic editor open/close
 */
window.JCI = window.JCI || {};

(function ($) {
	if (!window.jQuery) { return; }

	$(function () {

		// --- Hardening: ruim hangende modals + backdrops op -----------------
		// Een modal kan na een gebroken show-flow blijven hangen op twee
		// manieren: (a) als orphan .modal-backdrop div met z-index 1040 die
		// alle clicks afvangt, (b) als modal-dialog die zelf nog "open"
		// staat zonder dat Bootstrap z'n state kent. Beide blokkeren de
		// page silently. Bij elke fresh page-load: als geen enkele modal
		// expliciet open is (.modal.in zichtbaar), force-verberg we ALLE
		// modals + ruimen backdrops + reset body op modal-open class.
		if ($('.modal.in:visible').length === 0) {
			$('.modal').each(function () {
				$(this).removeClass('in')
				       .attr('aria-hidden', 'true')
				       .css('display', 'none');
			});
			$('.modal-backdrop').remove();
			$('body').removeClass('modal-open')
			         .css({ 'padding-right': '', 'overflow': '' });
		}

		// --- Sidebar: click-to-expand any <li> that has a sub-menu ----------
		// Works for Services/Utilities AND nested graphic-tree groups.
		$(document).on('click', '#leftXmlMenu li > a', function (e) {
			var $a = $(this);
			var $li = $a.parent();
			if ($li.children('ul.sub-menu').length === 0) { return; }
			var href = $a.attr('href');
			if (href === '#' || href === '' || href == null) {
				e.preventDefault();
				$li.toggleClass('expanded');
				$li.addClass('has-children');
			}
		});

		// --- Chevron-only toggle for "merged" groups -----------------------
		// A merged group is one whose row links to a graphic (the merged
		// child) AND has additional children. The row click navigates to
		// the graphic; clicking just the chevron must instead expand /
		// collapse without leaving the page.
		$(document).on('click', '#leftXmlMenu .jci-tree-caret', function (e) {
			var $li = $(this).closest('li');
			if ($li.children('ul.sub-menu').length === 0) return;
			e.preventDefault();
			e.stopPropagation();
			$li.toggleClass('expanded');
			$li.addClass('has-children');
		});

		// Tag any <li> with a sub-menu so chevron + style hooks apply.
		function jciTagLegacyExpandable() {
			$('#leftXmlMenu li').each(function () {
				var $li = $(this);
				if ($li.children('ul.sub-menu').length) {
					$li.addClass('has-children');
				}
			});
		}
		jciTagLegacyExpandable();
		setTimeout(jciTagLegacyExpandable, 200);
		setTimeout(jciTagLegacyExpandable, 1000);
		setTimeout(jciTagLegacyExpandable, 2500);

		// --- Sidebar: hamburger toggle --------------------------------------
		$('#header_container').on('click', '.mt-menuSwitch', function (e) {
			e.preventDefault();
			$('body').toggleClass('jci-sidebar-collapsed');
		});

		// --- Right-side panel (graphic editor properties) -------------------
		$('body').on('click', '.open-am-right-sidebar', function (e) {
			e.preventDefault();
			$('body').addClass('open-right-sidebar');
		});
		$('body').on('click', '.close-am-right-sidebar', function (e) {
			e.preventDefault();
			$('body').removeClass('open-right-sidebar');
		});

		// --- Header bell + sidebar bell go red when alarms are active --------
		// Priority 250 = "returned to normal" event, doesn't count as an
		// active alarm — matches the alarm-manager logic.
		function pollAlarms() {
			$.get('../plugins/alarmdb/alarmdb_exporter.php?www-command=alarmdb-active', function (data) {
				var alarms = (data && data.alarms) || [];
				var count = 0;
				for (var i = 0; i < alarms.length; i++) {
					var n = parseInt(alarms[i] && alarms[i].priority, 10);
					if (!isNaN(n) && n < 250) count++;
				}
				$('#alarmBell')
					.toggleClass('jci-bell-active', count > 0)
					.attr('data-count', count > 0 ? count : '');
				$('#leftXmlMenu .AlarmDBM > a > i').css('color', count > 0 ? '#e23b3b' : '');
			}).fail(function () { /* silent */ });
		}
		pollAlarms();
		setInterval(pollAlarms, 30000);

	});

	// --- Hash trigger: alarmdb.php sends users to graphic.php with a
	//     #trigger=<element-id> hash for utilities/admin actions whose
	//     handlers live in graphic.min.js. Fire the matching click once
	//     the page has finished loading. ----------------------------------
	$(window).on('load', function () {
		var m = /^#trigger=([\w\-]+)$/.exec(window.location.hash || '');
		if (!m) { return; }
		var id = m[1];
		setTimeout(function () {
			var $el = $('#' + id);
			if ($el.length) { $el.trigger('click'); }
			if (window.history && history.replaceState) {
				history.replaceState(null, '', location.pathname + location.search);
			} else {
				window.location.hash = '';
			}
		}, 250);
	});
})(jQuery);
