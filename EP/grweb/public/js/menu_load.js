/*
 * JCI-EP-WEB-Patch — render grNav.xml als SPACE-tree (icoonprefixes, NoAccess-filter).
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
 * Render grNav.xml into the SPACE section as a real nested tree.
 *
 * Each <grGroup> becomes an expandable folder, each <grItem> a leaf.
 * The legacy `<li class="parent">` + `<a grpath="...">` markers are kept
 * on top-level entries so the native graphic editor (graphic.min.js)
 * still finds its hooks.
 *
 * Naming convention (project-specific):
 *   - A 2-uppercase prefix + underscore in a graphic/group name picks an
 *     icon (the prefix is stripped from the displayed label):
 *       SC_X → schedule       (clock icon)
 *       ST_X → settings       (cog icon)
 *       MP_X → map            (map icon)
 *       BL_X → building       (office-building icon)
 *       EN_X → energy         (lightning-bolt icon)
 *       VW_X → verwarming     (radiator icon)
 *       KL_X → koeling        (snowflake icon)
 *       VT_X → ventilatie     (fan icon)
 *       VL_X → verlichting    (lightbulb icon)
 *       EK_X → e-kast         (server-rack icon)
 *       VV_X → VAV controller (developer-board / PLC icon)
 *       RR_X → ruimteregeling (desk icon)
 *   - Items with no prefix get the default equipment icon.
 *   - Groups with no prefix get the default folder icon.
 *
 * Merge convention: if a <grGroup> directly contains a <grItem> whose
 * stripped name matches the group's stripped name, the item is folded
 * INTO the group — the group's row becomes the link to that graphic
 * (and inherits the item's icon). The duplicate child is not rendered.
 * Other children of the group still appear; the chevron (handled by
 * jci-ui.js) toggles expand/collapse without navigating.
 */

(function () {
	var subMenuCounter = 0;

	// 2-letter prefix → MDI icon class. Extend here for new categories.
	var ICON_PREFIX = {
		'SC': 'mdi-clock-outline',            // Schedule
		'ST': 'mdi-cog-outline',              // Settings
		'MP': 'mdi-floor-plan',               // Map (floor plan)
		'BL': 'mdi-office-building-outline',  // Building
		'EN': 'mdi-lightning-bolt',           // Energy
		'VW': 'mdi-radiator',                 // Verwarming
		'KL': 'mdi-snowflake',                // Koeling
		'VT': 'mdi-fan',                      // Ventilatie
		'VL': 'mdi-lightbulb-outline',        // Verlichting
		'EK': 'mdi-server',                   // E-kast / regelkast
		'VV': 'mdi-developer-board',          // VAV controller (PLC-board)
		'RR': 'mdi-desk'                      // Ruimteregeling
	};
	var DEFAULT_ITEM_ICON  = 'mdi-cube-outline';   // equipment
	var DEFAULT_GROUP_ICON = 'mdi-folder-outline';

	// Strip the optional 2-uppercase + underscore prefix and pick an icon.
	// `defaultIcon` is used when there's no prefix, or the prefix isn't
	// known. Returns { icon, label }.
	function parseName(name, defaultIcon) {
		var s = name == null ? '' : String(name);
		var m = /^([A-Z]{2})_(.+)$/.exec(s);
		if (!m) return { icon: defaultIcon, label: s };
		var icon = ICON_PREFIX[m[1]] || defaultIcon;
		return { icon: icon, label: m[2] };
	}

	// Per-user NoAccess filter. Paths the current user has explicit
	// readable='f' on in cpt-web.db. Empty for admins. Populated by
	// fetchNoAccess() before tree rendering. isNoAccess(path) is the
	// only check used during render — empty / unknown paths default to
	// "allowed" so non-admin users without an entry behave like before.
	var noAccessSet = {};
	function isNoAccess(p) {
		return !!(p && noAccessSet[p] === true);
	}

	function fetchNoAccess() {
		// Returns a deferred. Best-effort — failures yield an empty set
		// so the tree still renders even if the API is unreachable.
		var d = $.Deferred();
		$.ajax({
			url: '../app/user_perms_api.php?cmd=get-grpath-noaccess',
			dataType: 'json',
			cache: false
		}).done(function (data) {
			noAccessSet = {};
			if (data && data.paths && data.paths.length) {
				data.paths.forEach(function (p) { noAccessSet[p] = true; });
			}
			d.resolve();
		}).fail(function () {
			noAccessSet = {};
			d.resolve();
		});
		return d;
	}

	$(function () {
		// Fetch perms first so isNoAccess() is populated before render.
		// Both calls fire essentially in parallel anyway because the
		// API is small and lightning-fast.
		fetchNoAccess().always(function () {
		$.get('../app/grdata/grNav.xml', function (xml) {
			var html = '';
			var $root = $(xml).find('grGroup').first();

			// Home shortcut comes from the XML home="1" attribute (set in
			// the CPT tool). Per-user home overrides have been removed —
			// every user sees the same Home, the row label is always
			// literally "Home".
			var $home = $root.find('grItem[home=1]').first();
			if ($home && $home.length) {
				// If the home graphic is on a NoAccess path for this user,
				// suppress the Home shortcut so a click can't land on
				// "cannot parse graphic".
				if (!isNoAccess($home.attr('path') || '')) {
					html += renderHome($home);
				} else {
					$home = null;
				}
			}

			// Recurse into top-level groups + non-home items.
			var homeEl = ($home && $home.length) ? $home.get(0) : null;
			$root.children('grItem').each(function () {
				// Dedup the rendered home from the top-level row list. We
				// only skip if THIS top-level item is the home — graphics
				// nested deeper still render at their natural place.
				if (homeEl && this === homeEl) { return; }
				if (!homeEl && $(this).attr('home') == 1) { return; }
				if (isNoAccess($(this).attr('path') || '')) { return; }
				html += renderItem($(this), /*topLevel*/ true);
			});
			$root.children('grGroup').each(function () {
				html += renderGroup($(this), /*topLevel*/ true, '');
			});

			// Insert under the SPACE marker.
			if ($('.jci-space-marker').length) {
				$('.jci-space-marker').after(html);
			} else if ($('.dashboardM').length === 0) {
				$('#leftXmlMenu').prepend(html);
			} else {
				$('.dashboardM').after(html);
			}

			markActive();
			getAlarmActive();
			callNanoScroller();
		});
		});

		function renderHome($home) {
			// Home row label is always literally "Home" — the underlying
			// graphic's name is irrelevant here (the same graphic still
			// shows under its real name at its natural tree position).
			// Link to the actual home graphic so a per-user override is
			// honoured (otherwise non-admin users always landed on the
			// XML home="1" graphic regardless of their personal home).
			var pathRaw = $home.attr('path') || '';
			var href    = pathRaw
				? ('../app/graphic.php?grname=' + encodeURI(pathRaw))
				: '../app/graphic.php';
			var grpathAttr = pathRaw ? (' grpath="' + esc(pathRaw) + '"') : '';
			return ''
				+ '<li class="parent homeMenu jci-tree-leaf">'
				+ '  <a href="' + href + '"' + grpathAttr + ' class="jci-tree-row">'
				+ '    <span class="jci-tree-caret-spacer"></span>'
				+ '    <i class="jci-tree-icon mdi mdi-home-outline"></i>'
				+ '    <span class="jci-tree-label">Home</span>'
				+ '  </a>'
				+ '</li>';
		}

		function renderGroup($group, topLevel, parentPath) {
			var rawName = $group.attr('name') || '';
			var groupParsed = parseName(rawName, DEFAULT_GROUP_ICON);

			// Build group path (for NoAccess matching against cpt-web.db
			// `permissions.path`). DB schema observed: groups stored as
			// "Hoi", "Hoi/Trend" — slash-joined raw names matching the
			// group hierarchy. Top-level group has no parent path.
			var myPath = parentPath ? (parentPath + '/' + rawName) : rawName;
			if (isNoAccess(myPath)) return '';

			var $items     = $group.children('grItem');
			var $subgroups = $group.children('grGroup');

			// Find a child <grItem> whose stripped name == this group's
			// stripped name. If found, this item is the "main" graphic of
			// the group — fold it in.
			var mergedItem = null;
			$items.each(function () {
				var itemRaw    = $(this).attr('name') || '';
				var itemParsed = parseName(itemRaw, DEFAULT_ITEM_ICON);
				if (itemParsed.label === groupParsed.label) {
					mergedItem = { el: this, parsed: itemParsed };
					return false;   // break each
				}
			});
			// If the merged item itself is NoAccess, demote the group
			// back to a plain folder (still expandable for surviving
			// children, but the row header doesn't link to a graphic).
			if (mergedItem && isNoAccess($(mergedItem.el).attr('path') || '')) {
				mergedItem = null;
			}

			// Pre-filter children: drop NoAccess items + subgroups whose
			// "myPath/childName" is NoAccess. We don't actually call
			// renderGroup() yet for subgroups — we let the recursion
			// handle the path check. But for items we filter inline so
			// hasOtherKids reflects the visible count.
			var visibleItemCount = 0;
			$items.each(function () {
				if (mergedItem && this === mergedItem.el) return;
				if (isNoAccess($(this).attr('path') || '')) return;
				visibleItemCount++;
			});

			// Render subgroups upfront so we know whether any survived
			// NoAccess (otherwise hasOtherKids over-counts).
			var subgroupHtml = '';
			var visibleSubgroupCount = 0;
			$subgroups.each(function () {
				var rendered = renderGroup($(this), false, myPath);
				if (rendered) {
					subgroupHtml += rendered;
					visibleSubgroupCount++;
				}
			});

			var hasOtherKids = (visibleItemCount + visibleSubgroupCount) > 0;

			// Empty group with no merged item = nothing to show.
			if (!hasOtherKids && !mergedItem) return '';

			var liClasses = ['jci-tree-group'];
			if (topLevel)     liClasses.push('parent');
			if (hasOtherKids) liClasses.push('has-children');

			// Row href + grpath + icon — when merged, the merged item drives.
			var icon, href, grpath;
			if (mergedItem) {
				// Item icon overrides folder; clicking the row opens the graphic.
				icon = mergedItem.parsed.icon;
				var pathRaw = $(mergedItem.el).attr('path') || '';
				href   = '../app/graphic.php?grname=' + encodeURI(pathRaw);
				grpath = pathRaw;
			} else {
				icon   = groupParsed.icon;
				href   = '#';
				grpath = '';
			}

			var caretEl = hasOtherKids
				? '<i class="jci-tree-caret mdi mdi-menu-right"></i>'
				: '<span class="jci-tree-caret-spacer"></span>';

			var out = '<li class="' + liClasses.join(' ') + '">';
			out += '  <a href="' + href + '" class="jci-tree-row jci-tree-toggle"' +
			        (grpath ? ' grpath="' + esc(grpath) + '"' : '') + '>';
			out += '    ' + caretEl;
			out += '    <i class="jci-tree-icon mdi ' + icon + '"></i>';
			out += '    <span class="jci-tree-label">' + esc(groupParsed.label) + '</span>';
			out += '  </a>';
			if (hasOtherKids) {
				out += '<ul class="sub-menu jci-tree-children nav" id="meniu_' + (subMenuCounter++) + '">';
				out += subgroupHtml;
				$items.each(function () {
					if (mergedItem && this === mergedItem.el) return;
					if (isNoAccess($(this).attr('path') || '')) return;
					out += renderItem($(this), false);
				});
				out += '</ul>';
			}
			out += '</li>';
			return out;
		}

		function renderItem($item, topLevel) {
			var rawName  = $item.attr('name') || '';
			var parsed   = parseName(rawName, DEFAULT_ITEM_ICON);
			var pathRaw  = $item.attr('path') || '';
			if (isNoAccess(pathRaw)) return '';
			var pathEnc  = encodeURI(pathRaw);
			var liClasses = ['jci-tree-leaf'];
			if (topLevel) liClasses.push('parent');
			return ''
				+ '<li class="' + liClasses.join(' ') + '">'
				+ '  <a class="jci-tree-row" href="../app/graphic.php?grname=' + pathEnc + '" grpath="' + esc(pathRaw) + '">'
				+ '    <span class="jci-tree-caret-spacer"></span>'
				+ '    <i class="jci-tree-icon mdi ' + parsed.icon + '"></i>'
				+ '    <span class="jci-tree-label">' + esc(parsed.label) + '</span>'
				+ '  </a>'
				+ '</li>';
		}

		function markActive() {
			var grName = getUrlParameter('grname');
			var fileName = GetFilename(window.location.href);

			if (grName) {
				var $active = $('#leftXmlMenu').find('a[grpath="' + grName + '"]');
				$active.closest('li').addClass('active');
				$active.parents('li.jci-tree-group').addClass('expanded has-children');
			} else if (fileName === 'graphic') {
				$('#leftXmlMenu .homeMenu').addClass('active');
			} else if (fileName) {
				$('#leftXmlMenu .' + fileName + 'M').addClass('active');
			}
		}

		function esc(s) {
			return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}

		function getAlarmActive() {
			$.get('../plugins/alarmdb/alarmdb_exporter.php?www-command=alarmdb-active', function (data) {
				if (typeof data.alarms !== 'undefined') {
					if (data.alarms.length > 0) {
						$('#leftXmlMenu').find('.glyphicon-bell').css('color', '#e23b3b');
						$('#leftXmlMenu .AlarmDBM > a > i').css('color', '#e23b3b');
					} else {
						$('#leftXmlMenu').find('.glyphicon-bell').removeAttr('style');
						$('#leftXmlMenu .AlarmDBM > a > i').css('color', '');
					}
				}
			});
		}

		function GetFilename(url) {
			if (!url) return false;
			var fn = url.split('?')[0].split('/').pop().split('#')[0].split('.')[0];
			return (fn && fn.length > 0) ? fn : false;
		}

		function getUrlParameter(name) {
			var query = decodeURIComponent(window.location.search.substring(1));
			var pairs = query.split('&');
			for (var i = 0; i < pairs.length; i++) {
				var p = pairs[i].split('=');
				if (p[0] === name) return p[1] === undefined ? true : p[1];
			}
		}
	});
})();

function callNanoScroller() {
	/* no-op for the JCI theme — sidebar is natively scrollable */
}

$(document).ready(function () {
	$('#header_container').on('click', '.mt-menuSwitch', function (e) {
		e.preventDefault();
		$('.mt-left-sidebar').toggleClass('mt-left-sidebar-on');
	});
	$('body').on('click', '.close-am-right-sidebar', function (e) {
		e.preventDefault();
		$('body').removeClass('open-right-sidebar');
	});
	$('body').on('click', '.open-am-right-sidebar', function (e) {
		e.preventDefault();
		$('body').addClass('open-right-sidebar');
	});
});
