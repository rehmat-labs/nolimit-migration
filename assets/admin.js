/* NoLimit Migration admin. No dependencies. */
(function () {
	'use strict';

	var cfg = window.NLWM || {};
	var $ = function (sel, root) { return (root || document).querySelector(sel); };
	var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
	var esc = function (s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	};
	// Server messages arrive HTML-escaped (esc_html in PHP); turn them back into plain text before using them.
	var plain = function (s) {
		var t = document.createElement('textarea');
		t.innerHTML = String(s == null ? '' : s);
		return t.value;
	};
	var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };

	/* ------------------------------------------------------------ ajax */

	function api(action, data, file) {
		var fd = new FormData();
		fd.append('action', 'nlwm_' + action);
		fd.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) { v.forEach(function (x) { fd.append(k + '[]', x); }); }
			else if (v !== undefined && v !== null) { fd.append(k, v); }
		});
		if (file) { fd.append('chunk', file, 'chunk'); }
		return fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) {
				return r.text().then(function (t) {
					var j;
					try { j = JSON.parse(t); } catch (e) {
						var err = new Error(r.ok ? 'The server sent an unexpected answer.' : 'The server answered HTTP ' + r.status + '.');
						err.retry = true;
						err.detail = t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
						throw err;
					}
					if (!j.success) { throw new Error(plain((j.data && j.data.message) || 'Request failed.')); }
					return j.data;
				});
			}, function () {
				var err = new Error('Network error.');
				err.retry = true;
				throw err;
			});
	}

	/* ------------------------------------------------------------ modal */

	var modal = {
		el: null,
		job: null,
		open: function (title) {
			this.el = $('#nlwm-modal');
			$('#nlwm-modal-title').textContent = title;
			$('#nlwm-result').innerHTML = '';
			$('#nlwm-log').textContent = '';
			$('#nlwm-close').hidden = true;
			$('#nlwm-cancel').hidden = false;
			$('#nlwm-keep').hidden = false;
			$('.nlwm-bar').classList.remove('is-error');
			this.set(0, 'Starting…');
			this.el.hidden = false;
			window.onbeforeunload = function () { return 'A backup or restore is running.'; };
		},
		set: function (pct, msg) {
			pct = Math.max(0, Math.min(100, Math.round(pct)));
			$('#nlwm-bar').style.width = pct + '%';
			$('.nlwm-bar').setAttribute('aria-valuenow', pct);
			$('#nlwm-pct').textContent = pct + '%';
			if (msg) { $('#nlwm-msg').textContent = plain(msg); }
		},
		log: function (lines) {
			if (lines && lines.length) { $('#nlwm-log').textContent = lines.map(plain).join('\n'); }
		},
		finish: function (ok, title, html) {
			window.onbeforeunload = null;
			$('#nlwm-modal-title').textContent = title;
			$('#nlwm-result').innerHTML = html || '';
			$('#nlwm-cancel').hidden = true;
			$('#nlwm-close').hidden = false;
			$('#nlwm-keep').hidden = true;
			if (!ok) { $('.nlwm-bar').classList.add('is-error'); }
			$('#nlwm-close').focus();
		},
		close: function () {
			window.onbeforeunload = null;
			if (this.el) { this.el.hidden = true; }
		}
	};

	var cancelled = false;

	/* ------------------------------------------------------------ job runner */

	function follow(view, token, label) {
		var job = { id: view.id, token: token };
		modal.job = job;
		var failures = 0;
		var step = function (v) {
			if (v) {
				modal.set(v.progress, (label ? label + ': ' : '') + v.message);
				modal.log(v.log);
				if (v.status === 'done') { return Promise.resolve(v); }
				if (v.status === 'error' || v.status === 'cancelled') {
					var e = new Error(plain(v.message));
					e.view = v;
					return Promise.reject(e);
				}
			}
			if (cancelled) {
				return sleep(800).then(function () {
					return api('cancel', { job: job.id, token: job.token });
				}).then(step, function (err) {
					if (err.retry) { return step(null); }
					throw err;
				});
			}
			return sleep(v && v.busy ? 2000 : 150).then(function () {
				return api('step', { job: job.id, token: job.token });
			}).then(function (nv) {
				failures = 0;
				return step(nv);
			}, function (err) {
				if (err.retry && failures < 30) {
					failures++;
					modal.set(v ? v.progress : 0, 'Connection hiccup, retrying (' + failures + ')…');
					return sleep(Math.min(15000, 1000 * failures)).then(function () { return step(null); });
				}
				throw err;
			});
		};
		return step(view);
	}

	function runJob(type, opts, label) {
		return api('start', { type: type, opts: JSON.stringify(opts || {}) }).then(function (v) {
			return follow(v, v.token, label);
		});
	}

	/* ------------------------------------------------------------ export */

	function replaceRow(oldV, newV) {
		var row = document.createElement('div');
		row.className = 'nlwm-replace-row';
		row.innerHTML = '<input class="nlwm-input" placeholder="Find" aria-label="Find"> <span aria-hidden="true">→</span> ' +
			'<input class="nlwm-input" placeholder="Replace with" aria-label="Replace with"> ' +
			'<button type="button" class="nlwm-btn is-ghost is-small" aria-label="Remove">✕</button>';
		var inputs = $$('input', row);
		inputs[0].value = oldV || '';
		inputs[1].value = newV || '';
		$('button', row).addEventListener('click', function () { row.remove(); });
		$('#nlwm-replace').appendChild(row);
	}

	function exportOpts() {
		var o = { label: ($('#nlwm-label') || {}).value || '' };
		$$('input[name="exclude"]:checked').forEach(function (c) { o[c.value] = 1; });
		o.exclude_tables = $$('input[name="exclude_table"]:checked').map(function (c) { return c.value; });
		o.exclude_paths = ($('#nlwm-exclude-paths') || {}).value || '';
		o.max_file_mb = parseInt(($('#nlwm-max-mb') || {}).value || '0', 10) || 0;
		o.storages = $$('input[name="nlwm_storage"]:checked').map(function (c) { return c.value; });
		o.password = ($('#nlwm-password') || {}).value || '';
		o.replace = $$('.nlwm-replace-row').map(function (r) {
			var i = $$('input', r);
			return [i[0].value, i[1].value];
		}).filter(function (p) { return p[0].trim() !== ''; });
		return o;
	}

	function exportDone(v) {
		var r = v.result || {};
		modal.set(100, 'Backup complete.');
		var warn = (r.warnings || []).map(function (w) { return '<div class="nlwm-alert is-warn">' + esc(w) + '</div>'; }).join('');
		modal.finish(true, 'Backup ready', '<div class="nlwm-alert is-ok"><strong>' + esc(r.name) + '</strong> · ' + esc(r.size_human) +
			(r.uploaded && r.uploaded.length ? ' · uploaded to ' + esc(r.uploaded.join(', ')) : '') + (r.local === false ? ' (kept in the cloud only)' : '') + '</div>' + warn +
			'<div class="nlwm-row">' + (r.download ? '<a class="nlwm-btn is-primary" href="' + esc(r.download) + '">Download</a>' : '') +
			'<a class="nlwm-btn" href="?page=nolimit-migration&tab=backups">Open backups</a></div>');
	}

	function jobFailed(err, title) {
		modal.log(err.view && err.view.log);
		var extra = err.detail ? '<p class="nlwm-small nlwm-muted">' + esc(err.detail) + '</p>' : '';
		modal.finish(false, title || 'Something went wrong', '<div class="nlwm-alert is-error">' + esc(err.message) + '</div>' + extra);
	}

	function initExport() {
		var btn = $('#nlwm-export');
		if (!btn) { return; }
		$('#nlwm-add-replace').addEventListener('click', function () { replaceRow(); });
		btn.addEventListener('click', function () {
			cancelled = false;
			modal.open('Creating backup');
			runJob('export', exportOpts()).then(exportDone, function (e) { jobFailed(e, 'Backup failed'); });
		});
	}

	/* ------------------------------------------------------------ import */

	var selected = null;
	var deleteAfter = false;

	function showConfirm(name) {
		return api('inspect', { name: name }).then(function (i) {
			selected = name;
			var rows = [
				['Backup', i.name], ['Type', i.format], ['Size', i.size], ['Made', i.created],
				['From site', i.from + (i.site_name ? ' (' + i.site_name + ')' : '')], ['Restore to', i.to],
				['WordPress', i.wp_version], ['PHP (source)', i.php],
				['Contents', (i.has_db ? 'Database, ' : 'No database, ') + i.plugins + ' plugin files, ' + i.themes + ' theme files, ' + i.uploads + ' media files'],
				['Theme', i.theme]
			].filter(function (r) { return r[1]; });
			$('#nlwm-facts').innerHTML = rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('');
			$('#nlwm-warn-newer').hidden = !i.newer_wp;
			$('#nlwm-pw-row').hidden = !i.encrypted;
			$('#nlwm-restore-pw').value = '';
			$('#nlwm-understand').checked = false;
			$('#nlwm-restore').disabled = true;
			var box = $('#nlwm-confirm');
			box.hidden = false;
			box.scrollIntoView({ behavior: 'smooth', block: 'start' });
		});
	}

	function restoreDone(v) {
		var r = v.result || {};
		modal.set(100, 'Restore complete.');
		var warn = (r.warnings || []).map(function (w) { return '<div class="nlwm-alert is-warn">' + esc(w) + '</div>'; }).join('');
		modal.finish(true, 'Site restored', '<div class="nlwm-alert is-ok">Done. ' + (r.db ? 'Log in with a username and password from the restored site, then open <strong>Settings → Permalinks</strong> and press Save once.' : 'Files were restored.') + '</div>' + warn +
			'<div class="nlwm-row"><a class="nlwm-btn is-primary" href="' + esc(r.login) + '">Log in</a><a class="nlwm-btn" href="' + esc(r.home) + '" target="_blank" rel="noopener">View site</a></div>');
		$('#nlwm-close').onclick = function () { window.location.href = r.login; };
	}

	function startRestore() {
		var name = selected;
		var opts = {
			backup: name,
			email_replace: $('#nlwm-email').checked ? 1 : 0,
			skip_files: $('#nlwm-skip-files').checked ? 1 : 0,
			skip_db: $('#nlwm-skip-db').checked ? 1 : 0,
			delete_after: deleteAfter ? 1 : 0,
			password: $('#nlwm-restore-pw') ? $('#nlwm-restore-pw').value : ''
		};
		if (!$('#nlwm-pw-row').hidden && !opts.password) { $('#nlwm-restore-pw').focus(); return; }
		cancelled = false;
		modal.open('Restoring site');
		var chain = Promise.resolve();
		if ($('#nlwm-safety').checked) {
			chain = runJob('export', { origin: 'pre-import', label: 'Before restoring ' + shortName(name) }, 'Safety backup');
		}
		chain.then(function () {
			if (cancelled) { throw new Error('Cancelled.'); }
			return runJob('import', opts, 'Restore');
		}).then(restoreDone, function (e) { jobFailed(e, 'Restore stopped'); });
	}

	function initConfirm() {
		if (!$('#nlwm-confirm')) { return; }
		$('#nlwm-understand').addEventListener('change', function () { $('#nlwm-restore').disabled = !this.checked; });
		$('#nlwm-restore').addEventListener('click', startRestore);
		$('#nlwm-restore-cancel').addEventListener('click', function () { $('#nlwm-confirm').hidden = true; selected = null; deleteAfter = false; });
		$('#nlwm-skip-files').addEventListener('change', function () { if (this.checked) { $('#nlwm-skip-db').checked = false; } });
		$('#nlwm-skip-db').addEventListener('change', function () { if (this.checked) { $('#nlwm-skip-files').checked = false; } });
	}

	function uploadFile(file) {
		if (!/\.(nolimit|wpress)$/i.test(file.name)) {
			alert('Choose a NoLimit backup file (.nolimit).');
			return;
		}
		cancelled = false;
		modal.open('Uploading ' + file.name);
		var id = (Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).replace(/[^a-z0-9]/g, '').slice(0, 24);
		var chunk = cfg.chunk || 2097152;
		var offset = 0;
		var failures = 0;
		var started = Date.now();

		var next = function () {
			if (cancelled) { throw new Error('Upload cancelled.'); }
			var blob = file.slice(offset, Math.min(offset + chunk, file.size));
			return api('upload', { upload_id: id, name: file.name, offset: offset, total: file.size }, blob).then(function (r) {
				failures = 0;
				offset = r.offset;
				var secs = (Date.now() - started) / 1000;
				var speed = offset / Math.max(1, secs);
				var left = speed ? Math.round((file.size - offset) / speed) : 0;
				modal.set(100 * offset / file.size, 'Uploading… ' + fmt(offset) + ' of ' + fmt(file.size) + (left > 5 ? ' · about ' + duration(left) + ' left' : ''));
				if (r.done) { return r.name; }
				return next();
			}, function (err) {
				if (err.retry && failures < 20) {
					failures++;
					modal.set(100 * offset / file.size, 'Connection hiccup, retrying (' + failures + ')…');
					return sleep(Math.min(15000, 1000 * failures)).then(next);
				}
				throw err;
			});
		};
		next().then(function (name) {
			modal.close();
			return showConfirm(name);
		}).catch(function (e) { jobFailed(e, 'Upload failed'); });
	}

	function initImport() {
		var drop = $('#nlwm-drop');
		if (drop) {
			var input = $('#nlwm-file');
			drop.addEventListener('click', function () { input.click(); });
			drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
			input.addEventListener('change', function () { if (input.files[0]) { uploadFile(input.files[0]); } input.value = ''; });
			['dragenter', 'dragover'].forEach(function (ev) {
				drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
			});
			['dragleave', 'drop'].forEach(function (ev) {
				drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
			});
			drop.addEventListener('drop', function (e) {
				if (e.dataTransfer.files[0]) { uploadFile(e.dataTransfer.files[0]); }
			});
		}
		var fetchBtn = $('#nlwm-fetch');
		if (fetchBtn) {
			fetchBtn.addEventListener('click', function () {
				var url = $('#nlwm-url').value.trim();
				if (!/^https?:\/\//i.test(url)) { $('#nlwm-url').focus(); return; }
				cancelled = false;
				modal.open('Fetching backup');
				runJob('fetch', { url: url }).then(function (v) {
					modal.close();
					return showConfirm(v.result.name);
				}).catch(function (e) { jobFailed(e, 'Could not fetch the backup'); });
			});
		}
	}

	/* ------------------------------------------------------------ backups list */

	var backupLabels = {};

	// A short, readable name for a backup: its label if it has one, else the file name without the
	// extension, shortened in the middle so it fits on one line.
	function shortName(name) {
		var s = backupLabels[name] || String(name).replace(/\.(nolimit|wpress)$/, '');
		return s.length > 48 ? s.slice(0, 30) + '…' + s.slice(-14) : s;
	}

	var typeNames = { manual: 'manual', scheduled: 'scheduled', 'pre-import': 'restore point', upload: 'uploaded', url: 'from link', cloud: 'from cloud', drive: 'from cloud' };

	function renderBackups(list) {
		var box = $('#nlwm-backups');
		if (!box) { return; }
		if (!list.length) {
			box.innerHTML = '<div class="nlwm-empty">No backups yet. <a href="?page=nolimit-migration">Create your first backup</a>.</div>';
			return;
		}
		backupLabels = {};
		list.forEach(function (b) { if (b.label) { backupLabels[b.name] = b.label; } });
		box.innerHTML = list.map(function (b) {
			return '<div class="nlwm-b" data-name="' + esc(b.name) + '">' +
				'<div class="nlwm-b-main"><div class="nlwm-b-name" title="' + esc(b.label || b.name) + '">' + esc(b.label || b.name) + '</div>' +
				'<div class="nlwm-b-meta"><span>' + esc(b.created) + '</span><span>' + esc(b.size) + '</span>' +
				'<span class="nlwm-tag is-' + esc(b.type) + '">' + esc(typeNames[b.type] || b.type) + '</span>' +
				(b.locked ? '<span class="nlwm-tag is-locked">kept</span>' : '') +
				(b.label ? '<span>' + esc(b.name) + '</span>' : '') +
				(b.site ? '<span>' + esc(b.site) + '</span>' : '') + '</div></div>' +
				'<div class="nlwm-b-actions">' +
				(cfg.canImport ? '<button type="button" class="nlwm-btn is-small" data-act="restore">Restore</button>' : '') +
				'<a class="nlwm-btn is-small" href="' + esc(b.download) + '">Download</a>' +
				'<button type="button" class="nlwm-btn is-small" data-act="link">Transfer link</button>' +
				'<button type="button" class="nlwm-btn is-link" data-act="label">Rename</button>' +
				'<button type="button" class="nlwm-btn is-link" data-act="lock" data-locked="' + (b.locked ? 1 : 0) + '">' + (b.locked ? 'Unkeep' : 'Keep forever') + '</button>' +
				(cfg.canImport ? '<button type="button" class="nlwm-btn is-link nlwm-danger-link" data-act="delete">Delete</button>' : '') +
				'</div></div>';
		}).join('');
	}

	function initBackups() {
		var box = $('#nlwm-backups');
		if (!box) { return; }
		var load = function () { return api('backups', {}).then(renderBackups, function (e) { box.innerHTML = '<div class="nlwm-alert is-error">' + esc(e.message) + '</div>'; }); };
		load();
		box.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-act]');
			if (!btn) { return; }
			var row = btn.closest('.nlwm-b');
			var name = row.getAttribute('data-name');
			var act = btn.getAttribute('data-act');
			if (act === 'restore') {
				deleteAfter = false;
				showConfirm(name).catch(function (err) { alert(err.message); });
			} else if (act === 'delete') {
				if (confirm('Delete ' + name + '? This cannot be undone.')) {
					api('delete', { name: name }).then(renderBackups, function (err) { alert(err.message); });
				}
			} else if (act === 'label') {
				var label = prompt('Label for this backup:', $('.nlwm-b-name', row).textContent);
				if (label !== null) { api('label', { name: name, label: label }).then(renderBackups); }
			} else if (act === 'lock') {
				api('lock', { name: name, locked: btn.getAttribute('data-locked') === '1' ? 0 : 1 }).then(renderBackups);
			} else if (act === 'link') {
				var existing = $('.nlwm-linkbox', row);
				if (existing) { existing.remove(); return; }
				api('link', { name: name, hours: 24 }).then(function (r) {
					var lb = document.createElement('div');
					lb.className = 'nlwm-linkbox';
					lb.innerHTML = '<input class="nlwm-input" readonly aria-label="Transfer link"><button type="button" class="nlwm-btn is-small is-primary">Copy</button><span class="nlwm-small nlwm-muted">Private link, valid until ' + esc(r.expires) + '. Anyone with it can download this backup.</span>';
					$('input', lb).value = r.url;
					$('button', lb).addEventListener('click', function () {
						var inp = $('input', lb);
						inp.select();
						(navigator.clipboard ? navigator.clipboard.writeText(r.url) : Promise.reject()).catch(function () { document.execCommand('copy'); });
						this.textContent = 'Copied';
					});
					row.appendChild(lb);
				}, function (err) { alert(err.message); });
			}
		});
	}

	/* ------------------------------------------------------------ cloud backups */

	function renderDrive(list) {
		var box = $('#nlwm-drive-files');
		if (!list.length) {
			box.innerHTML = '<div class="nlwm-empty">No backups in this storage yet.</div>';
			return;
		}
		if (!list.length) { return; }
		box.innerHTML = list.map(function (f) {
			return '<div class="nlwm-b" data-id="' + esc(f.id) + '" data-name="' + esc(f.name) + '" data-local="' + (f.local ? 1 : 0) + '">' +
				'<div><div class="nlwm-b-name">' + esc(f.label || f.name) + '</div>' +
				'<div class="nlwm-b-meta"><span>' + esc(f.created) + '</span><span>' + esc(f.size) + '</span>' +
				'<span class="nlwm-tag is-' + esc(f.type) + '">' + esc(typeNames[f.type] || f.type) + '</span>' +
				(f.local ? '<span class="nlwm-tag is-locked">also on server</span>' : '<span class="nlwm-tag">cloud only</span>') +
				(f.label ? '<span>' + esc(f.name) + '</span>' : '') + '</div></div>' +
				'<div class="nlwm-b-actions">' +
				(cfg.canImport ? '<button type="button" class="nlwm-btn is-small" data-dact="restore">Restore</button>' : '') +
				(f.view ? '<a class="nlwm-btn is-small" href="' + esc(f.view) + '" target="_blank" rel="noopener">Open</a>' : '') +
				(cfg.canImport ? '<button type="button" class="nlwm-btn is-link nlwm-danger-link" data-dact="delete">Delete</button>' : '') +
				'</div></div>';
		}).join('');
	}

	function initDrive() {
		var box = $('#nlwm-drive-files');
		if (box) {
			var load = function () {
				box.innerHTML = '<p class="nlwm-muted">Loading…</p>';
				api('remote_files', { storage: cloud() }).then(renderDrive, function (e) { box.innerHTML = '<div class="nlwm-alert is-error">' + esc(e.message) + '</div>'; });
			};
			var cloud = function () { return $('#nlwm-cloud-pick').value; };
			load();
			$('#nlwm-drive-refresh').addEventListener('click', load);
			$('#nlwm-cloud-pick').addEventListener('change', load);
			box.addEventListener('click', function (e) {
				var btn = e.target.closest('[data-dact]');
				if (!btn) { return; }
				var row = btn.closest('.nlwm-b');
				var id = row.getAttribute('data-id');
				var name = row.getAttribute('data-name');
				if (btn.getAttribute('data-dact') === 'delete') {
					if (confirm('Delete ' + name + ' from ' + $('#nlwm-cloud-pick').selectedOptions[0].textContent + '?')) {
						api('remote_delete', { storage: cloud(), id: id }).then(load, function (err) { alert(err.message); });
					}
					return;
				}
				// Restore: use the local copy when there is one, otherwise download it from Drive first.
				if (row.getAttribute('data-local') === '1') {
					deleteAfter = false;
					showConfirm(name).catch(function (err) { alert(err.message); });
					return;
				}
				cancelled = false;
				modal.open('Downloading from ' + $('#nlwm-cloud-pick').selectedOptions[0].textContent);
				runJob('fetch', { storage: cloud(), remote_id: id }).then(function (v) {
					modal.close();
					deleteAfter = cfg.storage === 'remote';
					return showConfirm(v.result.name);
				}).catch(function (err) { jobFailed(err, 'Download failed'); });
			});
		}

		var form = $('#nlwm-drive-settings');
		if (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var checked = $('input[name="storage"]:checked', form);
				var value = checked ? checked.value : 'local';
				api('storage_save', { storage: value, drive_retention: $('#nlwm-dret').value })
					.then(function (r) { $('#nlwm-drive-saved').textContent = r.message; cfg.storage = value; }, function (err) { alert(err.message); });
			});
		}
	}

	/* ------------------------------------------------------------ schedule */

	function initSettings() {
		var form = $('#nlwm-settings');
		if (!form) { return; }
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var data = {};
			new FormData(form).forEach(function (v, k) {
				if (k.slice(-2) === '[]') {
					k = k.slice(0, -2);
					(data[k] = data[k] || []).push(v);
				} else { data[k] = v; }
			});
			var btn = $('button[type="submit"]', form);
			btn.disabled = true;
			api('settings', data).then(function (r) {
				$('#nlwm-next').textContent = r.message + (r.next ? ' Next run: ' + r.next : ' Automatic backups are off.');
			}, function (err) { alert(err.message); }).then(function () { btn.disabled = false; });
		});
	}

	/* ------------------------------------------------------------ utils */

	function fmt(b) {
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
		return (i > 1 ? b.toFixed(1) : Math.round(b)) + ' ' + u[i];
	}
	function duration(s) {
		if (s < 90) { return s + ' s'; }
		if (s < 5400) { return Math.round(s / 60) + ' min'; }
		return (s / 3600).toFixed(1) + ' h';
	}

	/* ------------------------------------------------------------ boot */

	document.addEventListener('DOMContentLoaded', function () {
		if (!$('#nlwm')) { return; }
		$('#nlwm-cancel').addEventListener('click', function () {
			if (confirm('Stop now? If a restore is stopped before the database switch, your database stays unchanged (files already copied stay).')) {
				cancelled = true;
				$('#nlwm-msg').textContent = 'Cancelling…';
			}
		});
		$('#nlwm-close').addEventListener('click', function () {
			modal.close();
			if ($('#nlwm-backups')) { api('backups', {}).then(renderBackups); }
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !$('#nlwm-close').hidden) { modal.close(); }
		});
		initExport();
		initConfirm();
		initImport();
		initBackups();
		initSettings();
		initDrive();

		// Re-attach to a job that is still running (page was reloaded).
		if (cfg.running) {
			cancelled = false;
			modal.open(cfg.running.type === 'import' ? 'Restoring site' : (cfg.running.type === 'fetch' ? 'Fetching backup' : 'Creating backup'));
			follow({ id: cfg.running.id, status: 'running', progress: 0, message: 'Resuming…' }, cfg.running.token).then(function (v) {
				if (v.type === 'import') { restoreDone(v); }
				else if (v.type === 'export') { exportDone(v); }
				else { modal.close(); showConfirm(v.result.name); }
			}, function (e) { jobFailed(e); });
		}
	});
})();
