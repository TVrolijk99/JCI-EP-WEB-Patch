/*
 * JCI-EP-WEB-Patch — native alarmmanager controller.
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
 * Replaces the legacy AlarmDB plugin UI with a clean implementation that
 * runs on the same modern stack as graphic.php (jQuery 3.7.1, Bootstrap 3,
 * underscore, moment, daterangepicker). Talks to the existing PHP backend
 * (alarmdb_exporter.php) — the data layer is untouched.
 *
 * Layout:
 *   - Tabs (Active / History) at the very top — flat, no card.
 *   - Sliding viewport with two panes (Active / History). Click a tab and
 *     the whole content area slides horizontally.
 *   - Each pane has its own main-card (toolbar + filter bar + table + footer).
 *   - Active pane also has a stats row above (UNACKED ALARMS / SEVERITY).
 *
 * Severity ranges (project spec):
 *   Critical 1-39, High 40-79, Medium 80-139, Low 140-255.
 *
 * Session expired -> signin.php redirect.
 */
(function ($) {
	'use strict';

	if (!window.jQuery) return;
	if (!$('#myAlarmContainer').length) return;
	if (typeof moment === 'undefined') {
		console.error('[alarm-manager] moment.js not loaded');
		return;
	}

	var API = '../plugins/alarmdb/alarmdb_exporter.php';
	var PERMS_API = 'user_perms_api.php';
	var POLL_MS = 15000;
	var BG_POLL_MS = 30000;
	var USER = (window.cur_account_info && window.cur_account_info.name) || 'user';
	var SIGNIN_URL = 'signin.php';

	// Per-user feature permissions. Default = all true so unrestricted users
	// (and offline / permission-API failures) keep working as before.
	var PERMS = { alarm_ack: true, alarm_delete: true, trend_edit: true };

	function applyPermsToBody() {
		$('body').toggleClass('jci-noperm-alarm-ack',    !PERMS.alarm_ack);
		$('body').toggleClass('jci-noperm-alarm-delete', !PERMS.alarm_delete);
	}

	function fetchPerms() {
		$.ajax({
			url: PERMS_API + '?cmd=get-mine',
			dataType: 'json',
			cache: false
		}).done(function (data) {
			if (data && data.perms) {
				PERMS = $.extend(PERMS, data.perms);
			}
			applyPermsToBody();
		});
	}

	// ---------- State -----------------------------------------------------
	// Two pane-scoped state slices (active and history) plus shared data.
	function defaultPaneState() {
		return {
			page: 1,
			pageSize: 10,
			search: '',
			sortKey: 'date',
			sortDir: 'desc',
			selected: {},                           // { id: true }
			filters: {
				dateFrom: null, dateTo: null,       // null = always
				severities: { critical: true, high: true, medium: true, low: true, normal: true },
				tags: null,                         // null = all
				status: { active: true, normal: true }
			},
			filterBarOpen: false,
			daterangeInited: false
		};
	}

	var state = {
		tab: 'active',                              // 'active' or 'history'
		activeAlarms: [],
		historyAlarms: [],
		totals: { active: 0, ackn: 0, notes: 0 },
		panes: { active: defaultPaneState(), history: defaultPaneState() },
		loaded: { active: false, history: false },
		busy: false,
		autoTimer: null,
		bgTimer: null
	};

	// ---------- Helpers ---------------------------------------------------
	function $pane(pane) { return $('.alarm-view-pane[data-view="' + pane + '"]'); }
	function $card(pane) { return $('.alarm-main-card[data-pane="' + pane + '"]'); }

	function paneList(pane) {
		if (pane === 'active') {
			// Active tab only shows real alarms (priority < 250). Priority 250
			// = "returned to normal" — informational, no ack needed.
			return state.activeAlarms.filter(isAlarmEvent);
		}
		return state.historyAlarms;
	}

	function isAcked(a) { return a && a.ackn === 'true'; }

	// Severity ranges (per project spec):
	//   1-39    critical (red)
	//   40-79   high     (dark orange)
	//   80-139  medium   (light orange)
	//   140-249 low      (yellow)
	//   250     normal   (blue) — alarm returned to normal, no ack needed
	function classifyPriority(p) {
		var n = parseInt(p, 10);
		if (isNaN(n)) return { name: 'none', label: '—', cls: 'priority-none' };
		if (n === 250) return { name: 'normal',   label: 'Normal',   cls: 'priority-normal' };
		if (n <= 39)   return { name: 'critical', label: 'Critical', cls: 'priority-critical' };
		if (n <= 79)   return { name: 'high',     label: 'High',     cls: 'priority-high' };
		if (n <= 139)  return { name: 'medium',   label: 'Medium',   cls: 'priority-medium' };
		if (n <= 249)  return { name: 'low',      label: 'Low',      cls: 'priority-low' };
		return { name: 'none', label: '—', cls: 'priority-none' };
	}

	// True if this alarm event is an active alarm (priority < 250).
	// Priority 250 means "returned to normal" — informational, no ack needed.
	function isAlarmEvent(a) {
		var n = parseInt(a && a.priority, 10);
		return !isNaN(n) && n < 250;
	}

	// Source identifier — used to link an alarm event with its later
	// "returned to normal" (priority 250) event. Probes multiple fields so
	// it works regardless of how the underlying system tags its alarms:
	//   1. attr.uuid / .UUID / .id  (structured ID, most reliable)
	//   2. attr.name / .Name
	//   3. attr.path / .Path        (sedona software path)
	//   4. tags (when not the default 'alarmdb' placeholder)
	//   5. value (sometimes a sedona path)
	//   6. text (last-resort)
	function sourceKey(a) {
		if (!a) return '';
		var attr = a.attr;
		if (attr && typeof attr === 'object' && !Array.isArray(attr)) {
			var v = attr.uuid || attr.UUID || attr.id || attr.ID
			     || attr.name || attr.Name
			     || attr.path || attr.Path;
			if (v) return 'a:' + String(v).trim();
		}
		var t = (a.tags || '').trim();
		if (t && t.toLowerCase() !== 'alarmdb') return 't:' + t;
		var val = (a.value || '').trim();
		if (val) return 'v:' + val;
		return 'x:' + (a.text || '').trim();
	}
	function buildStatusMap(alarms) {
		var bySource = {};
		for (var i = 0; i < alarms.length; i++) {
			var a = alarms[i];
			if (!a) continue;
			var key = sourceKey(a);
			var prev = bySource[key];
			if (!prev) { bySource[key] = a; continue; }
			// Prefer the row with the most recent date (lexical compare on
			// 'YYYY-MM-DD HH:MM:SS' = chronological). Treat missing dates as
			// older than any present date.
			var ad = a.date || '';
			var pd = prev.date || '';
			if (ad > pd) bySource[key] = a;
		}
		return bySource;
	}

	// Current status of `a` based on the latest same-source event. Returns
	// 'active' (latest event priority < 250) or 'normal' (= 250).
	function statusOf(a, statusMap) {
		var key = sourceKey(a);
		var latest = (statusMap && statusMap[key]) || a;
		var n = parseInt(latest.priority, 10);
		return (n === 250) ? 'normal' : 'active';
	}

	function escapeHtml(s) {
		if (s == null) return '';
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function nowSqlDate() { return moment().format('YYYY-MM-DD HH:mm:ss'); }

	// ---------- API ------------------------------------------------------
	function api(cmd, params, opts) {
		opts = opts || {};
		var data = $.extend({}, params || {});
		data['www-command'] = 'alarmdb-' + cmd;
		return $.ajax({
			url: API,
			method: opts.method || 'GET',
			dataType: 'json',
			data: data,
			cache: false
		}).done(function (resp) {
			if (resp && resp.sessionExpired) redirectSignin();
		}).fail(function (xhr) {
			// 403 from the patch's own permission gate carries an `error`
			// field — that's a permission denial, NOT a session expiry, so
			// we surface it in-page instead of redirecting to signin.
			var resp = xhr && xhr.responseJSON;
			if (xhr.status === 403 && resp && resp.error && /forbidden/i.test(resp.error)) {
				// Re-sync the permission state — UI was probably stale.
				fetchPerms();
				if (window.alert) window.alert(resp.error);
				return;
			}
			if (xhr.status === 401) redirectSignin();
		});
	}

	function redirectSignin() {
		if (window.__jciSignoutRedirecting) return;
		window.__jciSignoutRedirecting = true;
		window.location.href = SIGNIN_URL;
	}

	// ---------- Filtering / sorting / pagination -------------------------
	function applyFilters(list, paneState, statusMap) {
		var f = paneState.filters;
		var q = (paneState.search || '').toLowerCase();
		return list.filter(function (a) {
			// date range
			if (f.dateFrom && a.date && moment(a.date).isBefore(f.dateFrom)) return false;
			if (f.dateTo && a.date && moment(a.date).isAfter(f.dateTo)) return false;
			// severity
			var sev = classifyPriority(a.priority).name;
			if (sev !== 'none' && !f.severities[sev]) return false;
			// status (current state — based on latest same-text event)
			if (statusMap && f.status) {
				var st = statusOf(a, statusMap);
				if (!f.status[st]) return false;
			}
			// tags (any tag in selection)
			if (f.tags && f.tags.length) {
				var alarmTags = (a.tags || '').split(',').map(function (t) { return t.trim(); }).filter(Boolean);
				if (!alarmTags.some(function (t) { return f.tags.indexOf(t) >= 0; })) return false;
			}
			// search
			if (q) {
				var hay = [a.text, a.value, a.tags, a.ackn_user, a.priority, a.date]
					.filter(function (x) { return x != null; }).join(' ').toLowerCase();
				if (hay.indexOf(q) < 0) return false;
			}
			return true;
		});
	}

	function sortList(list, paneState) {
		var key = paneState.sortKey, dir = paneState.sortDir === 'asc' ? 1 : -1;
		list.sort(function (a, b) {
			var av = a[key], bv = b[key];
			if (key === 'priority' || key === 'value') {
				var an = parseFloat(av), bn = parseFloat(bv);
				if (!isNaN(an) && !isNaN(bn)) return (an - bn) * dir;
			}
			av = av == null ? '' : String(av);
			bv = bv == null ? '' : String(bv);
			return av.localeCompare(bv) * dir;
		});
		return list;
	}

	function paginate(list, paneState) {
		if (!paneState.pageSize) return { rows: list, page: 1, pages: 1, start: 0, end: list.length };
		var pages = Math.max(1, Math.ceil(list.length / paneState.pageSize));
		if (paneState.page > pages) paneState.page = pages;
		if (paneState.page < 1) paneState.page = 1;
		var start = (paneState.page - 1) * paneState.pageSize;
		var end = Math.min(start + paneState.pageSize, list.length);
		return { rows: list.slice(start, end), page: paneState.page, pages: pages, start: start, end: end };
	}

	// ---------- Rendering -------------------------------------------------
	function renderAll() {
		renderPane('active');
		renderPane('history');
		renderStats();
		renderTotals();
		renderTagsFilters();
	}

	function renderPane(pane) {
		var ps = state.panes[pane];
		var $c = $card(pane);
		var raw = paneList(pane);
		var statusMap = buildStatusMap([].concat(state.activeAlarms, state.historyAlarms));
		var filtered = applyFilters(raw, ps, statusMap);
		sortList(filtered, ps);
		var p = paginate(filtered, ps);

		// Body
		var $body = $c.find('[data-role="table-body"]');
		$body.html(p.rows.map(function (a) { return rowHtml(a, ps, statusMap, pane); }).join(''));

		// Empty + show/hide
		$c.find('[data-role="empty"]').toggle(filtered.length === 0);
		$c.find('[data-role="table"]').toggle(filtered.length > 0);

		// Page info
		var pageInfoText;
		if (filtered.length === 0) {
			pageInfoText = '0 of 0';
		} else if (!ps.pageSize) {
			pageInfoText = 'Total ' + filtered.length + ' items';
		} else {
			pageInfoText = 'Total ' + filtered.length + ' items   Page ' + p.page + ' of ' + p.pages;
		}
		$c.find('[data-role="page-info"]').text(pageInfoText);

		// Header sort indicators
		$c.find('th.alarm-sort').removeClass('sort-asc sort-desc');
		$c.find('th.alarm-sort[data-sort="' + ps.sortKey + '"]')
			.addClass(ps.sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
		$c.find('th.alarm-sort i').each(function () {
			var $th = $(this).closest('.alarm-sort');
			var key = $th.data('sort');
			if (key !== ps.sortKey) {
				this.className = 'mdi mdi-unfold-more-horizontal';
			} else {
				this.className = 'mdi ' + (ps.sortDir === 'asc' ? 'mdi-arrow-up' : 'mdi-arrow-down');
			}
		});

		// Select-all checkbox state
		var selectableIds = p.rows.map(function (a) { return String(a.id); });
		var allChecked = selectableIds.length > 0 && selectableIds.every(function (id) { return ps.selected[id]; });
		var someChecked = selectableIds.some(function (id) { return ps.selected[id]; });
		$c.find('[data-act="select-all"]').prop('checked', allChecked).prop('indeterminate', !allChecked && someChecked);

		// Bulk-ack button state
		var hasSelectedUnacked = Object.keys(ps.selected).some(function (id) {
			if (!ps.selected[id]) return false;
			var alarm = raw.filter(function (a) { return String(a.id) === id; })[0];
			return alarm && !isAcked(alarm);
		});
		$c.find('[data-act="bulk-ackn"]').prop('disabled', !hasSelectedUnacked);

		// Toggle .has-selection on the card so the bulk-delete button can
		// surface (history pane only — hidden by CSS on active).
		var anySelected = Object.keys(ps.selected).some(function (id) { return ps.selected[id]; });
		$c.toggleClass('has-selection', anySelected);

		// Page-size dropdown
		$c.find('[data-act="page-size"]').val(String(ps.pageSize));

		// Search input value (without re-firing events)
		var $search = $c.find('[data-act="search"]');
		if ($search.val() !== ps.search) $search.val(ps.search);

		// Filter bar visibility
		$c.find('[data-role="filter-bar"]').toggleClass('expanded', !!ps.filterBarOpen);
		$c.find('[data-act="filter-toggle"]').toggleClass('active', !!ps.filterBarOpen);

		// Update multi-select labels
		updateMultiLabel($c.find('[data-role="severity"]'), ps.filters.severities);
		updateMultiLabel($c.find('[data-role="status"]'), ps.filters.status, 2);
		updateTagsLabel($c.find('[data-role="tags"]'), ps);
	}

	function rowHtml(a, paneState, statusMap, pane) {
		var pri = classifyPriority(a.priority);
		var acked = isAcked(a);
		var alarmEvent = isAlarmEvent(a);
		var status = statusMap ? statusOf(a, statusMap) : (alarmEvent ? 'active' : 'normal');
		var noteCount = (a.notes && a.notes.length) || 0;
		var checked = paneState.selected[a.id] ? 'checked' : '';
		var tagsHtml = (a.tags || '').split(',').map(function (t) { return t.trim(); }).filter(Boolean)
			.map(function (t) { return '<span class="alarm-tag">' + escapeHtml(t) + '</span>'; }).join(' ');

		// Status / Acknowledged column.
		//   Active pane: shows current state (Active / Normal / blank for
		//                priority-250 rows) + a small acked marker if applicable.
		//   History pane: shows acknowledgement info instead (ack date + user)
		//                — the live status is irrelevant in a history view.
		var statusHtml = '';
		if (pane === 'history') {
			if (acked) {
				statusHtml = '<div class="alarm-ack-line">' +
					'<div class="alarm-ack-line-top"><i class="mdi mdi-check-circle"></i> ' + escapeHtml(a.ackn_user || '—') + '</div>' +
					(a.adate ? '<div class="alarm-ack-date">' + escapeHtml(a.adate) + '</div>' : '') +
					'</div>';
			}
		} else {
			if (alarmEvent) {
				if (status === 'normal') {
					statusHtml = '<span class="alarm-status alarm-status-normal"><i class="mdi mdi-check-circle"></i> Normal</span>';
				} else {
					statusHtml = '<span class="alarm-status alarm-status-active"><i class="mdi mdi-alert-circle"></i> Active</span>';
				}
			}
			if (acked) {
				statusHtml += '<div class="alarm-ack-info"><i class="mdi mdi-check"></i> ' + escapeHtml(a.ackn_user || 'Acked') + '</div>';
			}
		}

		var actions = '';
		// Ack only makes sense for unacked, real alarm events (not "normal" priority-250 entries).
		if (!acked && alarmEvent) {
			actions += '<button class="alarm-row-btn alarm-ack" title="Acknowledge"><i class="mdi mdi-check"></i></button>';
		}
		actions += '<button class="alarm-row-btn alarm-notes" title="Notes"><i class="mdi mdi-comment-text-outline"></i>' +
			(noteCount ? '<span class="alarm-note-count">' + noteCount + '</span>' : '') + '</button>';
		actions += '<button class="alarm-row-btn alarm-del" title="Delete"><i class="mdi mdi-trash-can-outline"></i></button>';

		return '<tr data-id="' + escapeHtml(a.id) + '" class="' + (acked ? 'alarm-row-acked' : '') + '">' +
			'<td class="alarm-col-select"><input type="checkbox" class="alarm-row-select" ' + checked + '></td>' +
			'<td class="alarm-col-priority"><span class="alarm-pri-pill ' + pri.cls + '">' + pri.label + '</span></td>' +
			'<td class="alarm-col-text">' + escapeHtml(a.text) + '</td>' +
			'<td class="alarm-col-date">' + escapeHtml(a.date || '') + '</td>' +
			'<td class="alarm-col-value">' + escapeHtml(a.value) + '</td>' +
			'<td class="alarm-col-tags">' + tagsHtml + '</td>' +
			'<td class="alarm-col-status">' + statusHtml + '</td>' +
			'<td class="alarm-col-action">' + actions + '</td>' +
		'</tr>';
	}

	function updateMultiLabel($wrap, flags, totalOpts) {
		var keys = Object.keys(flags);
		var on = keys.filter(function (k) { return flags[k]; });
		var total = totalOpts || keys.length;
		var label;
		if (on.length === 0) label = 'None';
		else if (on.length === total) label = 'All';
		else label = on.length + ' selected';
		$wrap.find('.alarm-multi-label').text(label);
		$wrap.find('input[type=checkbox]').each(function () {
			var v = $(this).closest('a').data('val');
			$(this).prop('checked', !!flags[v]);
		});
	}

	function updateTagsLabel($wrap, paneState) {
		var f = paneState.filters.tags;
		var label;
		if (f === null) label = 'All';
		else if (f.length === 0) label = 'None';
		else label = f.length + ' selected';
		$wrap.find('.alarm-multi-label').text(label);
	}

	function renderTagsFilters() {
		var allTags = collectAllTags();
		['active', 'history'].forEach(function (pane) {
			var $menu = $card(pane).find('[data-role="tags-menu"]');
			var ps = state.panes[pane];
			var current = ps.filters.tags;
			var html = allTags.map(function (t) {
				var checked = (current === null || current.indexOf(t) >= 0) ? 'checked' : '';
				return '<li><a href="#" data-val="' + escapeHtml(t) + '"><label><input type="checkbox" ' + checked + '> ' + escapeHtml(t) + '</label></a></li>';
			}).join('');
			if (!html) html = '<li class="alarm-multi-empty"><span>No tags</span></li>';
			$menu.html(html);
		});
	}

	function collectAllTags() {
		var set = {};
		[].concat(state.activeAlarms, state.historyAlarms).forEach(function (a) {
			(a.tags || '').split(',').map(function (t) { return t.trim(); }).filter(Boolean).forEach(function (t) {
				set[t] = true;
			});
		});
		return Object.keys(set).sort();
	}

	function renderTotals() {
		// Server-side `totals.active` counts every unacked row including
		// priority-250 ("returned to normal") events. Recount client-side
		// to exclude those — only real alarms count toward "active".
		var realActive = 0;
		state.activeAlarms.forEach(function (a) {
			if (!isAcked(a) && isAlarmEvent(a)) realActive++;
		});
		['active', 'history'].forEach(function (pane) {
			var $c = $card(pane);
			$c.find('[data-role="total-active"]').text(realActive);
			$c.find('[data-role="total-ackn"]').text(state.totals.ackn || 0);
			$c.find('[data-role="total-notes"]').text(state.totals.notes || 0);
		});
	}

	// ---------- Stat cards (UNACKED ALARMS / SEVERITY) -------------------
	function renderStats() {
		var counts = { critical: 0, high: 0, medium: 0, low: 0 };
		var unacked = 0;
		state.activeAlarms.forEach(function (a) {
			if (isAcked(a)) return;
			if (!isAlarmEvent(a)) return;   // skip "returned to normal" entries
			unacked++;
			var c = classifyPriority(a.priority).name;
			if (counts[c] !== undefined) counts[c]++;
		});

		$('#jci-unacked-count').text(unacked);
		$('#jci-sev-critical').text(counts.critical);
		$('#jci-sev-high').text(counts.high);
		$('#jci-sev-medium').text(counts.medium);
		$('#jci-sev-low').text(counts.low);

		var total = counts.critical + counts.high + counts.medium + counts.low;
		var denom = total === 0 ? 1 : total;
		$('#jci-bar-critical').css('width', (counts.critical / denom * 100) + '%');
		$('#jci-bar-high').css('width',     (counts.high     / denom * 100) + '%');
		$('#jci-bar-medium').css('width',   (counts.medium   / denom * 100) + '%');
		$('#jci-bar-low').css('width',      (counts.low      / denom * 100) + '%');

		$('#alarmBell')
			.toggleClass('jci-bell-active', unacked > 0)
			.attr('data-count', unacked > 0 ? unacked : '');
		// Sidebar Alarms entry — keep in sync with the topbar bell so it
		// reacts at the same speed as the alarm-manager's own polling, and
		// also ignores priority-250 ("returned to normal") events.
		$('#leftXmlMenu .AlarmDBM > a > i').css('color', unacked > 0 ? '#e23b3b' : '');
	}

	// ---------- Loading ---------------------------------------------------
	function showLoading(pane, on) {
		state.busy = !!on;
		$card(pane).find('[data-role="loading"]').toggle(!!on);
	}

	// ---------- Data fetchers --------------------------------------------
	function loadInitial() {
		showLoading('active', true);
		showLoading('history', true);
		api('uiall').done(function (data) {
			state.activeAlarms = (data && data.active_alarms) || [];
			state.historyAlarms = (data && data.alarms) || [];
			state.totals = (data && data.totals) || { active: 0, ackn: 0, notes: 0 };
			state.loaded.active = true;
			state.loaded.history = true;
			renderAll();
		}).always(function () {
			showLoading('active', false);
			showLoading('history', false);
		});
	}

	function loadActive() {
		showLoading('active', true);
		api('active').done(function (data) {
			state.activeAlarms = (data && data.alarms) || [];
			state.loaded.active = true;
			renderAll();
		}).always(function () { showLoading('active', false); });
	}

	function loadHistory() {
		showLoading('history', true);
		var ps = state.panes.history;
		var params = {};
		if (ps.filters.dateFrom && ps.filters.dateTo) {
			params.dateFrom = moment(ps.filters.dateFrom).format('YYYYMMDDHHmmss');
			params.dateTo = moment(ps.filters.dateTo).format('YYYYMMDDHHmmss');
			params.activeAlarmFilter = 'false';
			api('all', params).done(function (data) {
				state.historyAlarms = (data && data.alarms) || [];
				if (data && data.totals) state.totals = data.totals;
				state.loaded.history = true;
				renderAll();
			}).always(function () { showLoading('history', false); });
		} else {
			// "Always" — use uiall (latest 500)
			api('uiall').done(function (data) {
				state.historyAlarms = (data && data.alarms) || [];
				if (data && data.totals) state.totals = data.totals;
				state.loaded.history = true;
				renderAll();
			}).always(function () { showLoading('history', false); });
		}
	}

	function refreshPane(pane) {
		if (pane === 'active') loadActive();
		else loadHistory();
	}

	// Background tick: refresh BOTH the active list and the (latest 500)
	// history list. Using uiall keeps the status-map fresh — without this,
	// a freshly-logged priority-250 ("returned to normal") event might land
	// only in history but historyAlarms would be stale, so the alarm row in
	// the active tab wouldn't switch from "Active" to "Normal".
	function backgroundTick() {
		api('uiall').done(function (data) {
			state.activeAlarms  = (data && data.active_alarms) || [];
			state.historyAlarms = (data && data.alarms)        || [];
			if (data && data.totals) state.totals = data.totals;
			renderStats();
			renderTotals();
			renderPane('active');
			renderPane('history');
			renderTagsFilters();
		});
	}

	// ---------- Actions ---------------------------------------------------
	function ackAlarms(pane, ids) {
		if (!ids.length) return;
		showLoading(pane, true);
		api('ackn', {
			id: ids.join(','),
			ackn_user: USER,
			adate: nowSqlDate()
		}, { method: 'POST' }).done(function (data) {
			if (data && data.totals) state.totals = data.totals;
			ids.forEach(function (id) { delete state.panes[pane].selected[id]; });
			loadActive();
			if (state.loaded.history) loadHistory();
		}).always(function () { showLoading(pane, false); });
	}

	function deleteAlarms(pane, ids) {
		if (!ids.length) return;
		if (!window.confirm('Delete ' + ids.length + ' alarm(s)? This cannot be undone.')) return;
		showLoading(pane, true);
		api('delete', { id: ids.join(',') }, { method: 'POST' }).done(function (data) {
			if (data && data.totals) state.totals = data.totals;
			ids.forEach(function (id) { delete state.panes[pane].selected[id]; });
			refreshPane(pane);
		}).always(function () { showLoading(pane, false); });
	}

	function selectedIds(pane) {
		var sel = state.panes[pane].selected;
		return Object.keys(sel).filter(function (id) { return sel[id]; });
	}

	// ---------- Notes -----------------------------------------------------
	function openNotes(pane, id) {
		var alarm = paneList(pane).filter(function (a) { return String(a.id) === String(id); })[0];
		$('#alarm-notes-modal').data('id', id).data('pane', pane);
		$('#alarm-notes-context').text('#' + id + (alarm ? ' — ' + (alarm.text || '') : ''));
		$('#alarm-note-text').val('');
		renderNotes(alarm && alarm.notes);
		$('#alarm-notes-modal').modal('show');
		api('noteget', { id: id }).done(function (data) {
			var notes = (data && data.notes && data.notes[id]) || [];
			renderNotes(notes);
			if (alarm) alarm.notes = notes;
		});
	}

	function renderNotes(notes) {
		var $list = $('#alarm-notes-list');
		if (!notes || !notes.length) {
			$list.html('<div class="alarm-notes-empty">No notes yet</div>');
			return;
		}
		var html = notes.map(function (n) {
			return '<div class="alarm-note-item">' +
				'<div class="alarm-note-meta">' +
					'<span class="alarm-note-user"><i class="mdi mdi-account-outline"></i> ' + escapeHtml(n.user) + '</span>' +
					'<span class="alarm-note-date">' + escapeHtml(n.date) + '</span>' +
				'</div>' +
				'<div class="alarm-note-body">' + escapeHtml(n.text) + '</div>' +
			'</div>';
		}).join('');
		$list.html(html);
	}

	function submitNote() {
		var id = $('#alarm-notes-modal').data('id');
		var pane = $('#alarm-notes-modal').data('pane');
		var text = $.trim($('#alarm-note-text').val());
		if (!text) return;
		api('noteadd', {
			id: id,
			ackn_user: USER,
			text: text,
			adate: nowSqlDate()
		}, { method: 'POST' }).done(function (data) {
			$('#alarm-note-text').val('');
			if (data && data.totals) state.totals = data.totals;
			var notes = (data && data.notes && data.notes[id]) || [];
			renderNotes(notes);
			var alarm = paneList(pane).filter(function (a) { return String(a.id) === String(id); })[0];
			if (alarm) alarm.notes = notes;
			renderTotals();
			renderPane(pane);
		});
	}

	// ---------- Export ----------------------------------------------------
	function doExport(pane, fmt) {
		var ps = state.panes[pane];
		var rows = sortList(applyFilters(paneList(pane), ps), ps);
		var stamp = moment().format('YYYYMMDD_HHmmss');
		var fileBase = 'alarms-' + pane + '-' + stamp;
		if (fmt === 'csv') {
			var keys = ['id', 'date', 'priority', 'value', 'text', 'tags', 'ackn', 'ackn_user', 'adate'];
			var lines = [keys.join(',')];
			rows.forEach(function (r) {
				lines.push(keys.map(function (k) {
					var v = r[k] == null ? '' : String(r[k]);
					if (/[",\n]/.test(v)) v = '"' + v.replace(/"/g, '""') + '"';
					return v;
				}).join(','));
			});
			triggerDownload(lines.join('\n'), 'text/csv;charset=utf-8', fileBase + '.csv');
		} else if (fmt === 'json') {
			triggerDownload(JSON.stringify(rows, null, 2), 'application/json', fileBase + '.json');
		}
	}

	function triggerDownload(content, mime, filename) {
		var blob = new Blob([content], { type: mime });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	// ---------- Date range with bootstrap-datetimepicker (Stefan Petre) ---
	// Two pickers (from / to) on .input-group.date wrappers. The picker
	// fires `changeDate` with `e.date` (a Date or null when cleared).
	function initDateRange(pane) {
		var ps = state.panes[pane];
		if (ps.daterangeInited) return;
		var $card_ = $card(pane);
		var $fromWrap = $card_.find('[data-role="dt-from"]');
		var $toWrap   = $card_.find('[data-role="dt-to"]');
		var $clear    = $card_.find('[data-role="date-clear"]');
		if (!$fromWrap.length || !$toWrap.length) return;
		if (typeof $fromWrap.datetimepicker !== 'function') {
			console.warn('[alarm-manager] bootstrap-datetimepicker not loaded');
			return;
		}

		var opts = {
			format: 'yyyy-MM-dd',
			pickDate: true,
			pickTime: false,
			pickSeconds: false,
			maskInput: false
		};

		$fromWrap.datetimepicker(opts);
		$toWrap.datetimepicker(opts);
		var fromPicker = $fromWrap.data('datetimepicker');
		var toPicker   = $toWrap.data('datetimepicker');

		var $fromLabel = $card_.find('[data-role="dt-from-label"]');
		var $toLabel   = $card_.find('[data-role="dt-to-label"]');

		// Start unset (no pre-selected date — picker shows "Always" via empty input).
		if (fromPicker) fromPicker.setValue(null);
		if (toPicker)   toPicker.setValue(null);
		$fromLabel.text('From').addClass('is-empty');
		$toLabel.text('To').addClass('is-empty');

		function applyState() {
			ps.page = 1;
			if (pane === 'history') loadHistory();
			else renderPane('active');
		}

		function fmt(d) { return moment(d).format('YYYY-MM-DD'); }

		$fromWrap.on('changeDate', function (e) {
			if (e.date) {
				ps.filters.dateFrom = moment(e.date).startOf('day').toISOString();
				$fromLabel.text(fmt(e.date)).removeClass('is-empty');
				if (toPicker) toPicker.setStartDate(e.date);
			} else {
				ps.filters.dateFrom = null;
				$fromLabel.text('From').addClass('is-empty');
				if (toPicker) toPicker.setStartDate(-Infinity);
			}
			applyState();
		});
		$toWrap.on('changeDate', function (e) {
			if (e.date) {
				ps.filters.dateTo = moment(e.date).endOf('day').toISOString();
				$toLabel.text(fmt(e.date)).removeClass('is-empty');
				if (fromPicker) fromPicker.setEndDate(e.date);
			} else {
				ps.filters.dateTo = null;
				$toLabel.text('To').addClass('is-empty');
				if (fromPicker) fromPicker.setEndDate(Infinity);
			}
			applyState();
		});

		$clear.on('click', function () {
			ps.filters.dateFrom = null;
			ps.filters.dateTo = null;
			if (fromPicker) { fromPicker.setValue(null); fromPicker.setEndDate(Infinity); }
			if (toPicker)   { toPicker.setValue(null);   toPicker.setStartDate(-Infinity); }
			$card_.find('input.alarm-dt-input').val('');
			$fromLabel.text('From').addClass('is-empty');
			$toLabel.text('To').addClass('is-empty');
			applyState();
		});

		ps.daterangeInited = true;
	}

	// ---------- Event bindings -------------------------------------------
	function bindEvents() {
		// Top tabs (slide between panes)
		$('.alarm-tabs').on('click', 'a[data-alarm-tab]', function (e) {
			e.preventDefault();
			var tab = $(this).data('alarm-tab');
			if (tab === state.tab) return;
			state.tab = tab;
			$('.alarm-tabs li').removeClass('active');
			$(this).parent().addClass('active');
			$('#alarm-views-track').attr('data-view', tab);
			if (tab === 'history' && !state.loaded.history) loadHistory();
		});

		// All toolbar/table interactions are bound at #myAlarmContainer level
		// and dispatched per-pane via [data-pane] on the closest .alarm-main-card.
		var $container = $('#myAlarmContainer');
		function paneOf(el) {
			return $(el).closest('.alarm-main-card').data('pane');
		}

		// Search (debounced, per pane)
		var searchTimers = {};
		$container.on('input', 'input[data-act="search"]', function () {
			var pane = paneOf(this);
			var v = $(this).val();
			clearTimeout(searchTimers[pane]);
			searchTimers[pane] = setTimeout(function () {
				state.panes[pane].search = v;
				state.panes[pane].page = 1;
				renderPane(pane);
			}, 200);
		});

		// Refresh
		$container.on('click', '[data-act="refresh"]', function () {
			refreshPane(paneOf(this));
		});

		// Filter toggle
		$container.on('click', '[data-act="filter-toggle"]', function () {
			var pane = paneOf(this);
			state.panes[pane].filterBarOpen = !state.panes[pane].filterBarOpen;
			if (state.panes[pane].filterBarOpen) initDateRange(pane);
			renderPane(pane);
		});

		// Filter clear
		$container.on('click', '[data-act="filter-clear"]', function () {
			var pane = paneOf(this);
			var ps = state.panes[pane];
			ps.filters.dateFrom = null;
			ps.filters.dateTo = null;
			ps.filters.severities = { critical: true, high: true, medium: true, low: true, normal: true };
			ps.filters.status = { active: true, normal: true };
			ps.filters.tags = null;
			ps.search = '';
			ps.page = 1;
			$card(pane).find('[data-role="date-from"], [data-role="date-to"]').val('');
			$card(pane).find('input[data-act="search"]').val('');
			if (pane === 'history') loadHistory();
			else renderPane('active');
		});

		// Severity multi-select toggle.
		// Use `change` so direct clicks on the checkbox work; `click` only
		// keeps the dropdown open and stops the <a>'s default href nav.
		$container.on('click', '[data-role="severity-menu"] a, [data-role="severity-menu"] label', function (e) {
			e.stopPropagation();
		});
		$container.on('click', '[data-role="severity-menu"] a', function (e) {
			e.preventDefault();
		});
		$container.on('change', '[data-role="severity-menu"] input[type="checkbox"]', function (e) {
			e.stopPropagation();
			var pane = paneOf(this);
			var val = $(this).closest('a').data('val');
			var ps = state.panes[pane];
			ps.filters.severities[val] = $(this).prop('checked');
			ps.page = 1;
			renderPane(pane);
		});

		// Status multi-select toggle (Active / Normal)
		$container.on('click', '[data-role="status-menu"] a, [data-role="status-menu"] label', function (e) {
			e.stopPropagation();
		});
		$container.on('click', '[data-role="status-menu"] a', function (e) {
			e.preventDefault();
		});
		$container.on('change', '[data-role="status-menu"] input[type="checkbox"]', function (e) {
			e.stopPropagation();
			var pane = paneOf(this);
			var val = $(this).closest('a').data('val');
			var ps = state.panes[pane];
			ps.filters.status[val] = $(this).prop('checked');
			ps.page = 1;
			renderPane(pane);
		});

		// Tags multi-select toggle (same change-based pattern).
		$container.on('click', '[data-role="tags-menu"] a, [data-role="tags-menu"] label', function (e) {
			e.stopPropagation();
		});
		$container.on('click', '[data-role="tags-menu"] a', function (e) {
			e.preventDefault();
		});
		$container.on('change', '[data-role="tags-menu"] input[type="checkbox"]', function (e) {
			e.stopPropagation();
			var pane = paneOf(this);
			var val = $(this).closest('a').data('val');
			if (!val) return;
			var ps = state.panes[pane];
			var on = $(this).prop('checked');
			var allTags = collectAllTags();
			if (ps.filters.tags === null) {
				// "all" was implicit — start from full list, then remove this one
				ps.filters.tags = allTags.slice();
			}
			if (on) {
				if (ps.filters.tags.indexOf(val) < 0) ps.filters.tags.push(val);
			} else {
				ps.filters.tags = ps.filters.tags.filter(function (t) { return t !== val; });
			}
			if (ps.filters.tags.length === allTags.length) ps.filters.tags = null;
			ps.page = 1;
			renderPane(pane);
			renderTagsFilters();
		});

		// Export
		$container.on('click', '.alarm-export-menu a[data-export]', function (e) {
			e.preventDefault();
			doExport(paneOf(this), $(this).data('export'));
		});

		// Sort header
		$container.on('click', 'th.alarm-sort', function () {
			var pane = paneOf(this);
			var key = $(this).data('sort');
			var ps = state.panes[pane];
			if (ps.sortKey === key) ps.sortDir = ps.sortDir === 'asc' ? 'desc' : 'asc';
			else { ps.sortKey = key; ps.sortDir = 'asc'; }
			renderPane(pane);
		});

		// Select all
		$container.on('change', '[data-act="select-all"]', function () {
			var pane = paneOf(this);
			var ps = state.panes[pane];
			var on = $(this).prop('checked');
			var filtered = sortList(applyFilters(paneList(pane), ps), ps);
			var p = paginate(filtered, ps);
			p.rows.forEach(function (a) {
				if (on) ps.selected[a.id] = true;
				else delete ps.selected[a.id];
			});
			renderPane(pane);
		});

		// Row select
		$container.on('change', 'input.alarm-row-select', function () {
			var pane = paneOf(this);
			var id = $(this).closest('tr').data('id');
			if ($(this).prop('checked')) state.panes[pane].selected[id] = true;
			else delete state.panes[pane].selected[id];
			renderPane(pane);
		});

		// Per-row actions
		$container.on('click', '.alarm-ack', function (e) {
			e.stopPropagation();
			var pane = paneOf(this);
			var id = $(this).closest('tr').data('id');
			ackAlarms(pane, [String(id)]);
		});
		$container.on('click', '.alarm-del', function (e) {
			e.stopPropagation();
			var pane = paneOf(this);
			var id = $(this).closest('tr').data('id');
			deleteAlarms(pane, [String(id)]);
		});
		$container.on('click', '.alarm-notes', function (e) {
			e.stopPropagation();
			var pane = paneOf(this);
			var id = $(this).closest('tr').data('id');
			openNotes(pane, String(id));
		});

		// Bulk acknowledge
		$container.on('click', '[data-act="bulk-ackn"]', function () {
			var pane = paneOf(this);
			var ids = selectedIds(pane).filter(function (id) {
				var alarm = paneList(pane).filter(function (a) { return String(a.id) === id; })[0];
				return alarm && !isAcked(alarm);
			});
			if (ids.length) ackAlarms(pane, ids);
		});

		// Bulk delete (history pane — exposed by CSS only there)
		$container.on('click', '[data-act="bulk-delete"]', function () {
			var pane = paneOf(this);
			var ids = selectedIds(pane);
			if (ids.length) deleteAlarms(pane, ids);
		});

		// Pagination
		$container.on('click', '[data-act="page-first"]', function () { state.panes[paneOf(this)].page = 1; renderPane(paneOf(this)); });
		$container.on('click', '[data-act="page-prev"]',  function () { var p = paneOf(this); if (state.panes[p].page > 1) { state.panes[p].page--; renderPane(p); } });
		$container.on('click', '[data-act="page-next"]',  function () { var p = paneOf(this); state.panes[p].page++; renderPane(p); });
		$container.on('click', '[data-act="page-last"]',  function () {
			var p = paneOf(this);
			var ps = state.panes[p];
			var filtered = applyFilters(paneList(p), ps);
			ps.page = ps.pageSize ? Math.max(1, Math.ceil(filtered.length / ps.pageSize)) : 1;
			renderPane(p);
		});
		$container.on('change', '[data-act="page-size"]', function () {
			var p = paneOf(this);
			state.panes[p].pageSize = parseInt($(this).val(), 10) || 0;
			state.panes[p].page = 1;
			renderPane(p);
		});

		// Notes modal
		$('#alarm-note-submit').on('click', submitNote);
		$('#alarm-note-text').on('keydown', function (e) {
			if (e.ctrlKey && e.key === 'Enter') submitNote();
		});

		// Bootstrap tooltip for the help icon and severity-info icon
		try {
			$('[data-toggle="tooltip"]').tooltip({ container: 'body' });
		} catch (err) { /* tooltip plugin missing — silent */ }
	}

	// ---------- Init ------------------------------------------------------
	$(function () {
		fetchPerms();
		bindEvents();
		loadInitial();
		state.bgTimer = setInterval(backgroundTick, BG_POLL_MS);
	});
})(jQuery);
