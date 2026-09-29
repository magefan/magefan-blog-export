/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */

(function () {
	'use strict';

	const config = window.mageshblBlogImportConfig;
	const i18n = config.i18n;
	const POLL_INTERVAL = 2000;
	const MAX_POLL_INTERVAL = 30000;
	const RETRY_DELAYS = [2000, 4000, 8000];
	const FINAL_STATUSES = ['done', 'failed', 'cancelled'];

	const $ = function (name) {
		return document.querySelector('[data-mageshbl-bi-' + name + ']');
	};

	const el = {
		form: document.getElementById('mageshbl-export-form'),
		destination: document.getElementById('destination'),
		key: document.getElementById('export_shopify_import_key'),
		keyHint: document.getElementById('tagline-description'),
		limitRow: document.getElementById('entities-limit'),
		limit: document.getElementById('export_shopify_entities_limit'),
		domainRow: document.getElementById('domain'),
		domain: document.getElementById('export_domain'),
		submit: document.getElementById('mageshbl-export-submit'),
		formError: $('form-error'),
		shop: $('shop'),
		progress: $('progress'),
		progressTitle: $('progress-title'),
		bar: $('bar'),
		barFill: $('bar-fill'),
		count: $('count'),
		note: $('note'),
		failed: $('failed'),
		failedList: $('failed-list'),
		failedMore: $('failed-more'),
		resume: $('resume'),
		newExport: $('new'),
		cancel: $('cancel'),
		error: $('error')
	};

	if (!el.form || !el.destination || !el.progress) {
		return;
	}

	const submitLabel = el.submit.value;
	let pollTimer = null;
	let pollDelay = POLL_INTERVAL;
	let conflictJobId = 0;

	const format = function (template) {
		const values = Array.prototype.slice.call(arguments, 1);
		return template.replace(/%(\d+)\$d/g, function (match, index) {
			return String(values[index - 1]);
		});
	};

	const show = function (element, visible) {
		element.hidden = !visible;
	};

	const wait = function (ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	};

	const isSelected = function () {
		return config.destination === el.destination.value;
	};

	const request = function (action, data) {
		const body = new FormData();
		body.append('action', 'mageshbl_blog_import_' + action);
		body.append('nonce', config.nonce);
		Object.keys(data || {}).forEach(function (name) {
			body.append(name, data[name]);
		});

		return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.catch(function () {
				throw { message: i18n.genericError, retryable: true };
			})
			.then(function (response) {
				return response.json().catch(function () {
					throw { message: i18n.genericError, retryable: 500 <= response.status };
				});
			})
			.then(function (json) {
				if (!json || !json.success) {
					const data = json && json.data ? json.data : {};
					throw {
						message: data.message || i18n.genericError,
						retryable: !!data.retryable,
						jobId: data.job_id || 0
					};
				}
				return json.data;
			});
	};

	const requestWithRetry = function (action, data) {
		let attempt = 0;

		const run = function () {
			return request(action, data).catch(function (error) {
				if (!error.retryable || attempt >= RETRY_DELAYS.length) {
					throw error;
				}
				showNote(i18n.retrying);
				return wait(RETRY_DELAYS[attempt++]).then(run);
			});
		};

		return run().then(function (result) {
			show(el.note, false);
			return result;
		});
	};

	const showFormError = function (message) {
		el.formError.textContent = message;
		show(el.formError, !!message);
	};

	const showError = function (message) {
		el.error.textContent = message;
		show(el.error, !!message);
	};

	const showNote = function (message) {
		el.note.textContent = message;
		show(el.note, !!message);
	};

	const setBar = function (done, total, state) {
		const percent = total ? Math.min(100, Math.round(done / total * 100)) : 100;
		el.barFill.style.width = percent + '%';
		el.bar.setAttribute('aria-valuenow', String(percent));
		el.bar.classList.toggle('mageshbl-bi-bar--waiting', 'waiting' === state);
		el.bar.classList.toggle('mageshbl-bi-bar--done', 'done' === state);
	};

	const setActions = function (actions) {
		show(el.resume, -1 !== actions.indexOf('resume'));
		show(el.newExport, -1 !== actions.indexOf('new'));
		show(el.cancel, -1 !== actions.indexOf('cancel'));
	};

	const setSubmit = function (label) {
		el.submit.disabled = !!label || (isSelected() && !config.totals.posts);
		el.submit.value = label || submitLabel;
	};

	const stopPolling = function () {
		clearTimeout(pollTimer);
		pollTimer = null;
	};

	/**
	 * Show the fields the selected destination needs. The other destinations keep their own handler in form.php.
	 */
	const applyDestination = function () {
		const selected = isSelected();
		show(el.limitRow, !selected);
		el.limit.required = !selected;
		if (selected) {
			show(el.domainRow, false);
			el.domain.required = false;
			el.keyHint.style.display = 'block';
			el.keyHint.textContent = i18n.keyHint + ' ' + (config.totals.posts
				? format(i18n.summary, config.totals.posts, config.totals.blogs)
				: i18n.nothing);
		}
		setSubmit('');
	};

	const showForm = function () {
		stopPolling();
		show(el.form, true);
		show(el.progress, false);
		showFormError('');
		conflictJobId = 0;
		setSubmit('');
	};

	const showProgress = function () {
		show(el.form, false);
		show(el.progress, true);
		show(el.failed, false);
		el.shop.textContent = i18n.exportingTo.replace('%s', config.shop);
	};

	const renderSending = function (job) {
		const sendingBlogs = job.blogs_sent < job.blogs_total;
		el.progressTitle.textContent = sendingBlogs ? i18n.sendingBlogs : i18n.sendingPosts;
		const done = sendingBlogs ? job.blogs_sent : job.posts_sent;
		const total = sendingBlogs ? job.blogs_total : job.posts_total;
		el.count.textContent = done + ' / ' + total;
		setBar(done, total, 'sending');
	};

	const isSent = function (job) {
		return job.blogs_sent >= job.blogs_total && job.posts_sent >= job.posts_total;
	};

	const sendAll = function (job) {
		showProgress();
		showError('');
		showNote('');
		setActions(['cancel']);
		renderSending(job);

		if (isSent(job)) {
			return finish();
		}

		return requestWithRetry('send')
			.then(function (updated) {
				config.job = updated;
				return sendAll(updated);
			})
			.catch(function (error) {
				showError(error.message);
				setActions(['resume', 'cancel']);
			});
	};

	const finish = function () {
		return requestWithRetry('finish')
			.then(function () {
				config.job.finished = true;
				showNote(i18n.canClose);
				pollStatus();
			})
			.catch(function (error) {
				showError(error.message);
				setActions(['resume', 'cancel']);
			});
	};

	const renderFailed = function (status) {
		el.failedList.textContent = '';
		(status.failed || []).forEach(function (post) {
			const item = document.createElement('li');
			const title = document.createElement('strong');
			title.textContent = post.title || ('#' + post.id);
			item.appendChild(title);
			item.appendChild(document.createTextNode(' — ' + (post.error || '')));
			el.failedList.appendChild(item);
		});
		show(el.failed, 0 < status.posts_failed);
		el.failedMore.textContent = i18n.moreFailed;
		show(el.failedMore, status.posts_failed > (status.failed || []).length);
	};

	const renderStatus = function (status) {
		const handled = status.posts_done + status.posts_failed;
		el.count.textContent = handled + ' / ' + status.posts_total;
		renderFailed(status);

		if ('queued' === status.status) {
			el.progressTitle.textContent = i18n.waiting;
			setBar(0, 1, 'waiting');
			setActions(['cancel']);
			return;
		}

		if ('processing' === status.status || 'receiving' === status.status) {
			el.progressTitle.textContent = i18n.importing;
			setBar(handled, status.posts_total, 'importing');
			setActions(['cancel']);
			return;
		}

		showNote('');
		setActions(['new']);

		if ('done' === status.status) {
			el.progressTitle.textContent = i18n.done;
			el.count.textContent = format(i18n.doneSummary, status.posts_done, status.posts_failed);
			setBar(1, 1, 'done');
		} else if ('cancelled' === status.status) {
			el.progressTitle.textContent = i18n.cancelled;
			setBar(handled, status.posts_total, 'cancelled');
		} else {
			el.progressTitle.textContent = i18n.failedJob;
			setBar(handled, status.posts_total, 'failed');
			showError(status.error || '');
		}
	};

	const pollStatus = function () {
		stopPolling();
		showProgress();

		if (document.hidden) {
			return;
		}

		request('status')
			.then(function (status) {
				pollDelay = POLL_INTERVAL;
				showError('');
				renderStatus(status);
				if (-1 === FINAL_STATUSES.indexOf(status.status)) {
					pollTimer = setTimeout(pollStatus, pollDelay);
				}
			})
			.catch(function (error) {
				showError(error.message);
				pollDelay = Math.min(MAX_POLL_INTERVAL, pollDelay * 2);
				pollTimer = setTimeout(pollStatus, pollDelay);
			});
	};

	el.destination.addEventListener('change', applyDestination);

	el.form.addEventListener('submit', function (event) {
		if (!isSelected()) {
			return;
		}

		event.preventDefault();
		showFormError('');
		setSubmit(i18n.connecting);

		request('connect', { key: el.key.value })
			.then(function (data) {
				config.shop = data.shop;
				config.job = null;
				setSubmit(i18n.starting);
				return request('start');
			})
			.then(function (job) {
				config.job = job;
				el.key.value = '';
				setSubmit('');
				return sendAll(job);
			})
			.catch(function (error) {
				setSubmit('');
				conflictJobId = error.jobId || 0;
				if (!conflictJobId) {
					showFormError(error.message);
					return;
				}
				showProgress();
				showError(i18n.conflict + ' ' + error.message);
				el.progressTitle.textContent = i18n.conflict;
				el.count.textContent = '';
				setBar(0, 1, 'waiting');
				setActions(['cancel']);
			});
	});

	el.resume.addEventListener('click', function () {
		sendAll(config.job);
	});

	el.newExport.addEventListener('click', showForm);

	el.cancel.addEventListener('click', function () {
		if (!window.confirm(i18n.confirmCancel)) {
			return;
		}

		stopPolling();
		el.cancel.disabled = true;

		request('cancel', conflictJobId ? { job_id: conflictJobId } : {})
			.then(function (data) {
				if (!conflictJobId) {
					config.job = null;
				}
				showForm();
				if (data && data.warning) {
					showFormError(data.warning);
				}
			})
			.catch(function (error) {
				showError(error.message);
			})
			.finally(function () {
				el.cancel.disabled = false;
			});
	});

	document.addEventListener('visibilitychange', function () {
		if (!document.hidden && config.job && config.job.finished && null === pollTimer && !el.progress.hidden) {
			pollStatus();
		}
	});

	if (config.job) {
		el.destination.value = config.destination;
		applyDestination();
		if (!config.job.finished) {
			showProgress();
			renderSending(config.job);
			showNote(i18n.interrupted);
			setActions(['resume', 'cancel']);
		} else {
			pollStatus();
		}
	}
})();
