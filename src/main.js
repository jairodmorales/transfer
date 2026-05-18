import { addNewFileMenuEntry, Permission } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

const CLOUD_UPLOAD_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M11 20H6.5Q4.22 20 2.61 18.43 1 16.85 1 14.58 1 12.63 2.17 11.1 3.35 9.57 5.25 9.15 5.88 6.85 7.75 5.43 9.63 4 12 4 14.93 4 16.96 6.04 19 8.07 19 11 20.73 11.2 21.86 12.5 23 13.78 23 15.5 23 17.38 21.69 18.69 20.38 20 18.5 20H13V12.85L14.6 14.4L16 13L12 9L8 13L9.4 14.4L11 12.85Z" /></svg>'

// ─────────────────────────────────────────────────────────────────────────────
// Styles
// ─────────────────────────────────────────────────────────────────────────────

const STYLES = `
.transfer-overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.5);
	z-index: 10100;
	display: flex;
	align-items: center;
	justify-content: center;
}
.transfer-dialog {
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	border-radius: var(--border-radius-large, 10px);
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	width: 480px;
	max-width: 90vw;
	max-height: 90vh;
	overflow-y: auto;
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
}
.transfer-dialog h2 {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 4);
	font-size: 1.2em;
	font-weight: 600;
}
.transfer-dialog__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}
.transfer-field label {
	display: block;
	margin-bottom: 4px;
	font-size: 0.9em;
	font-weight: 500;
	color: var(--color-text-maxcontrast, #767676);
}
.transfer-field input,
.transfer-field select {
	width: 100%;
	box-sizing: border-box;
	padding: 8px 10px;
	border: 2px solid var(--color-border-maxcontrast, #ccc);
	border-radius: var(--border-radius-large, 10px);
	font-size: 0.95em;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	min-height: var(--default-clickable-area, 34px);
}
.transfer-field input:focus,
.transfer-field select:focus {
	border-color: var(--color-primary-element, #0082c9);
	outline: none;
}
.transfer-field--row {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
.transfer-field--grow {
	flex: 1;
	min-width: 0;
}
.transfer-field--ext {
	width: 10em;
	flex-shrink: 0;
}
.transfer-note {
	background: var(--note-background, var(--color-background-dark, #ededed));
	border-radius: var(--border-radius-large, 10px);
	padding: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 3);
}
.transfer-note p {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
}
.transfer-actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
.transfer-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 8px 20px;
	border-radius: var(--border-radius-pill, 20px);
	font-size: 0.9em;
	font-weight: 600;
	cursor: pointer;
	border: 2px solid transparent;
	background: var(--color-background-dark, #ededed);
	color: var(--color-main-text, #222);
	min-height: var(--default-clickable-area, 34px);
}
.transfer-btn:hover:not(:disabled) {
	background: var(--color-background-hover, #e0e0e0);
}
.transfer-btn:disabled {
	opacity: 0.5;
	cursor: default;
}
.transfer-btn--primary {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}
.transfer-btn--primary:hover:not(:disabled) {
	background: var(--color-primary-element-hover, #006aa3);
}
.transfer-btn--primary svg {
	width: 20px;
	height: 20px;
	fill: currentColor;
}

/* ── Multi-URL rows ────────────────────────────────────────────────────────── */
.transfer-url-rows {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
.transfer-url-row {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
.transfer-url-row__num {
	flex-shrink: 0;
	width: 1.4em;
	text-align: right;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
	padding-bottom: 10px;
}
.transfer-url-row__inputs {
	flex: 1;
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	min-width: 0;
}
@media (max-width: 460px) {
	.transfer-url-row__inputs { grid-template-columns: 1fr; }
}
.transfer-url-row__remove {
	flex-shrink: 0;
	background: none;
	border: none;
	cursor: pointer;
	padding: 6px;
	color: var(--color-text-maxcontrast, #767676);
	border-radius: var(--border-radius, 4px);
	font-size: 1.1em;
	line-height: 1;
	align-self: flex-end;
	margin-bottom: 4px;
}
.transfer-url-row__remove:hover { color: var(--color-error, #e9322d); }
.transfer-url-row__remove:disabled { opacity: 0; pointer-events: none; }
.transfer-add-url {
	align-self: flex-start;
	font-size: 0.875em;
}
.transfer-dialog--wide {
	width: 600px;
}

/* ── Floating status panel ─────────────────────────────────────────────────── */
.transfer-panel {
	position: fixed;
	bottom: 16px;
	inset-inline-end: 16px;
	z-index: 9999;
	width: 320px;
	max-width: calc(100vw - 32px);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	border-radius: var(--border-radius-large, 10px);
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
	overflow: hidden;
}
.transfer-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 14px;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	font-weight: 600;
	font-size: 0.9em;
}
.transfer-panel__close {
	background: none;
	border: none;
	color: inherit;
	cursor: pointer;
	font-size: 1.2em;
	line-height: 1;
	padding: 0 2px;
	opacity: 0.8;
}
.transfer-panel__close:hover { opacity: 1; }
.transfer-panel__list {
	max-height: 240px;
	overflow-y: auto;
}
.transfer-panel__item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 9px 14px;
	border-bottom: 1px solid var(--color-border, #ededed);
	font-size: 0.875em;
}
.transfer-panel__item:last-child { border-bottom: none; }
.transfer-panel__icon {
	flex-shrink: 0;
	width: 18px;
	text-align: center;
	font-size: 1em;
}
.transfer-panel__name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.transfer-panel__state {
	flex-shrink: 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast, #767676);
}
.transfer-panel__item--done   .transfer-panel__icon { color: var(--color-success, #46ba61); }
.transfer-panel__item--failed .transfer-panel__icon { color: var(--color-error,   #e9322d); }
.transfer-panel__item--running .transfer-panel__icon { color: var(--color-primary-element, #0082c9); }
.transfer-panel__item--queued  .transfer-panel__icon { color: var(--color-text-maxcontrast, #767676); }

@keyframes transfer-spin {
	from { transform: rotate(0deg); }
	to   { transform: rotate(360deg); }
}
.transfer-panel__item--running .transfer-panel__icon svg,
.transfer-panel__item--queued  .transfer-panel__icon svg {
	animation: transfer-spin 1.2s linear infinite;
	display: block;
}
`

const SPIN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8Z"/></svg>'
const CHECK_SVG = '✓'
const CROSS_SVG = '✗'

function injectStyles() {
	if (document.getElementById('transfer-styles')) return
	const style = document.createElement('style')
	style.id = 'transfer-styles'
	style.textContent = STYLES
	document.head.appendChild(style)
}

// ─────────────────────────────────────────────────────────────────────────────
// Status panel
// ─────────────────────────────────────────────────────────────────────────────

// token → { path, status, error }
const trackedJobs = new Map()
let panelEl = null
let pollTimer = null
let panelHidden = false

function esc(s) {
	return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
}

function getFilename(path) {
	return path.split('/').filter(Boolean).pop() || path
}

function statusIcon(status) {
	if (status === 'done') return CHECK_SVG
	if (status === 'failed') return CROSS_SVG
	return SPIN_SVG
}

function statusLabel(status, error) {
	if (status === 'queued') return t('transfer', 'Queued')
	if (status === 'running') return t('transfer', 'Downloading…')
	if (status === 'done') return t('transfer', 'Done')
	if (status === 'failed') return error || t('transfer', 'Failed')
	return status
}

function renderPanel() {
	if (trackedJobs.size === 0) {
		panelEl?.remove()
		panelEl = null
		return
	}

	if (panelHidden) return

	if (!panelEl) {
		panelEl = document.createElement('div')
		panelEl.className = 'transfer-panel'
		panelEl.setAttribute('role', 'status')
		panelEl.setAttribute('aria-live', 'polite')
		document.body.appendChild(panelEl)
	}

	const activeCount = [...trackedJobs.values()].filter(j => j.status === 'queued' || j.status === 'running').length

	const headerLabel = activeCount > 0
		? t('transfer', 'Transfers ({n} active)', { n: activeCount })
		: t('transfer', 'Transfers')

	let itemsHtml = ''
	for (const [, job] of [...trackedJobs].reverse()) {
		itemsHtml += `
			<div class="transfer-panel__item transfer-panel__item--${esc(job.status)}">
				<span class="transfer-panel__icon">${statusIcon(job.status)}</span>
				<span class="transfer-panel__name" title="${esc(job.path)}">${esc(getFilename(job.path))}</span>
				<span class="transfer-panel__state">${esc(statusLabel(job.status, job.error))}</span>
			</div>`
	}

	panelEl.innerHTML = `
		<div class="transfer-panel__header">
			<span>${headerLabel}</span>
			<button class="transfer-panel__close" aria-label="${t('transfer', 'Dismiss')}">✕</button>
		</div>
		<div class="transfer-panel__list">${itemsHtml}</div>
	`

	panelEl.querySelector('.transfer-panel__close').addEventListener('click', () => {
		panelHidden = true
		panelEl.remove()
		panelEl = null
	})
}

function scheduleNextPoll() {
	const hasActive = [...trackedJobs.values()].some(j => j.status === 'queued' || j.status === 'running')
	if (!hasActive || pollTimer) return
	pollTimer = setTimeout(doPoll, 3000)
}

async function doPoll() {
	pollTimer = null
	try {
		const resp = await axios.get(generateFilePath('transfer', 'ajax', 'status.php'))
		let changed = false
		for (const job of resp.data) {
			if (trackedJobs.has(job.token)) {
				const current = trackedJobs.get(job.token)
				if (current.status !== job.status || current.error !== job.error) {
					trackedJobs.set(job.token, { ...current, status: job.status, error: job.error })
					changed = true
					if (job.status === 'done' || job.status === 'failed') {
						// Remove terminal jobs after 30 s so the map stays bounded
						// and polling stops naturally once all jobs are gone.
						setTimeout(() => trackedJobs.delete(job.token), 30000)
					}
				}
			}
		}
		if (changed) renderPanel()
	} catch {
		// Ignore poll failures — the panel simply stops updating until the next cycle
	}
	scheduleNextPoll()
}

function trackJob(token, path) {
	trackedJobs.set(token, { path, status: 'queued', error: null })
	panelHidden = false
	renderPanel()
	scheduleNextPoll()
}

// ─────────────────────────────────────────────────────────────────────────────
// URL utilities
// ─────────────────────────────────────────────────────────────────────────────

function parseFilename(url) {
	try {
		const pathname = new URL(url).pathname
		const basename = decodeURIComponent(pathname.split('/').filter(Boolean).pop() || '')
		const dot = basename.lastIndexOf('.')
		if (dot > 0) {
			return { filename: basename, hasExtension: true }
		}
		return { filename: basename || '', hasExtension: false }
	} catch {
		return { filename: '', hasExtension: false }
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Upload dialog
// ─────────────────────────────────────────────────────────────────────────────

// Admin-configured maximum number of URLs per dialog (default 3, max 10).
const MAX_URLS = loadState('transfer', 'maxUrls', 3)

function showDialog(currentPath) {
	injectStyles()

	return new Promise((resolve) => {
		// Each row holds its own state so probe timers and edited flags
		// are isolated per URL.
		const rows = [{ url: '', filename: '', probedExt: '', filenameEdited: false, probeTimer: null }]

		const overlay = document.createElement('div')
		overlay.className = 'transfer-overlay'

		const dialog = document.createElement('div')
		dialog.className = 'transfer-dialog'
		dialog.setAttribute('role', 'dialog')
		dialog.setAttribute('aria-modal', 'true')

		overlay.appendChild(dialog)
		document.body.appendChild(overlay)

		// Returns the effective filename for a row (typed > URL-derived+probed).
		function getRowFilename(row) {
			if (row.filename) return row.filename
			const parsed = parseFilename(row.url)
			if (parsed.hasExtension) return parsed.filename
			if (parsed.filename && row.probedExt) return parsed.filename + '.' + row.probedExt
			return parsed.filename
		}

		function isRowValid(row) {
			try { new URL(row.url) } catch { return false }
			return !!getRowFilename(row)
		}

		function getFilenamePlaceholder(row) {
			const parsed = parseFilename(row.url)
			if (parsed.hasExtension) return parsed.filename
			if (parsed.filename && row.probedExt) return parsed.filename + '.' + row.probedExt
			return t('transfer', 'File name')
		}

		// Full DOM rebuild — called at init and whenever rows are added/removed.
		// Input events patch specific elements instead to preserve focus.
		function renderDialog() {
			const allValid = rows.length > 0 && rows.every(isRowValid)
			const single = rows.length === 1
			const dir = currentPath.replace(/\/$/, '')

			dialog.className = 'transfer-dialog' + (single ? '' : ' transfer-dialog--wide')

			dialog.innerHTML = `
				<h2>${t('transfer', 'Upload by link')}</h2>
				<div class="transfer-dialog__form">
					<div class="transfer-url-rows" id="transfer-url-rows">
						${rows.map((row, i) => `
							<div class="transfer-url-row" data-index="${i}">
								${!single ? `<span class="transfer-url-row__num">${i + 1}.</span>` : ''}
								<div class="transfer-url-row__inputs">
									<div class="transfer-field">
										${i === 0 ? `<label>${t('transfer', 'Link')}</label>` : ''}
										<input
											class="transfer-row-url"
											type="url"
											placeholder="https://example.com/file.txt"
											value="${esc(row.url)}"
											data-row="${i}"
										/>
									</div>
									<div class="transfer-field">
										${i === 0 ? `<label>${t('transfer', 'File name')}</label>` : ''}
										<input
											class="transfer-row-filename"
											type="text"
											placeholder="${esc(getFilenamePlaceholder(row))}"
											value="${esc(row.filename)}"
											data-row="${i}"
										/>
									</div>
								</div>
								<button
									class="transfer-url-row__remove"
									title="${t('transfer', 'Remove')}"
									data-row="${i}"
									${single ? 'disabled' : ''}
								>✕</button>
							</div>
						`).join('')}
					</div>

					${rows.length < MAX_URLS ? `
						<button id="transfer-add-url" class="transfer-btn transfer-add-url">
							+ ${t('transfer', 'Add URL')}
						</button>
					` : ''}

					${single ? `
						<div class="transfer-note">
							<p>${t('transfer', 'Some websites provide a checksum in addition to the file. This is used after the transfer to verify that the file is not corrupted.')}</p>
						</div>
						<div class="transfer-field transfer-field--row">
							<div class="transfer-field transfer-field--ext">
								<label for="transfer-hashalgo">${t('transfer', 'Algorithm')}</label>
								<select id="transfer-hashalgo">
									<option value="">—</option>
									<option value="md5">md5</option>
									<option value="sha1">sha1</option>
									<option value="sha256">sha256</option>
									<option value="sha512">sha512</option>
								</select>
							</div>
							<div class="transfer-field transfer-field--grow">
								<label for="transfer-hash">${t('transfer', 'Checksum')}</label>
								<input id="transfer-hash" type="text" />
							</div>
						</div>
					` : ''}

					<div class="transfer-actions">
						<button id="transfer-cancel" class="transfer-btn">${t('transfer', 'Cancel')}</button>
						<button id="transfer-submit" class="transfer-btn transfer-btn--primary" ${!allValid ? 'disabled' : ''}>
							${CLOUD_UPLOAD_SVG}
							${single
								? t('transfer', 'Upload')
								: t('transfer', 'Upload {n} files', { n: rows.length })}
						</button>
					</div>
				</div>
			`

			bindRowEvents()
		}

		function bindRowEvents() {
			// Add-URL button
			dialog.querySelector('#transfer-add-url')?.addEventListener('click', () => {
				rows.push({ url: '', filename: '', probedExt: '', filenameEdited: false, probeTimer: null })
				renderDialog()
				dialog.querySelectorAll('.transfer-row-url')[rows.length - 1]?.focus()
			})

			// Remove buttons
			dialog.querySelectorAll('.transfer-url-row__remove').forEach((btn) => {
				btn.addEventListener('click', () => {
					const i = parseInt(btn.dataset.row, 10)
					clearTimeout(rows[i]?.probeTimer)
					rows.splice(i, 1)
					renderDialog()
				})
			})

			// URL inputs — update state; patch placeholder; do NOT re-render
			dialog.querySelectorAll('.transfer-row-url').forEach((input) => {
				input.addEventListener('input', () => {
					const i = parseInt(input.dataset.row, 10)
					rows[i].url = input.value

					if (!rows[i].filenameEdited) {
						const parsed = parseFilename(rows[i].url)
						rows[i].probedExt = ''
						updateRowPlaceholder(i)

						if (!parsed.hasExtension && parsed.filename) {
							clearTimeout(rows[i].probeTimer)
							rows[i].probeTimer = setTimeout(async () => {
								try {
									const resp = await axios.get(
										generateFilePath('transfer', 'ajax', 'probe.php'),
										{ params: { url: rows[i].url } },
									)
									rows[i].probedExt = resp.data.extension || ''
									updateRowPlaceholder(i)
								} catch { /* ignore */ }
								updateSubmitBtn()
							}, 500)
						}
					}
					updateSubmitBtn()
				})
			})

			// Filename inputs — update state without re-render
			dialog.querySelectorAll('.transfer-row-filename').forEach((input) => {
				input.addEventListener('input', () => {
					const i = parseInt(input.dataset.row, 10)
					rows[i].filename = input.value
					rows[i].filenameEdited = input.value !== ''
					updateSubmitBtn()
				})
			})

			dialog.querySelector('#transfer-cancel')?.addEventListener('click', close)

			dialog.querySelector('#transfer-submit')?.addEventListener('click', submit)

			dialog.addEventListener('keydown', (e) => {
				const submitBtn = dialog.querySelector('#transfer-submit')
				if (e.key === 'Enter' && submitBtn && !submitBtn.disabled) {
					e.preventDefault()
					submit()
				}
			})
		}

		// Patch just the filename placeholder without rebuilding the form.
		function updateRowPlaceholder(i) {
			const inputs = dialog.querySelectorAll('.transfer-row-filename')
			if (inputs[i]) inputs[i].placeholder = getFilenamePlaceholder(rows[i])
		}

		function updateSubmitBtn() {
			const btn = dialog.querySelector('#transfer-submit')
			if (btn) btn.disabled = !rows.every(isRowValid)
		}

		function close() {
			rows.forEach(row => clearTimeout(row.probeTimer))
			overlay.remove()
			resolve()
		}

		overlay.addEventListener('click', (e) => { if (e.target === overlay) close() })

		function onKey(e) {
			if (e.key === 'Escape') {
				document.removeEventListener('keydown', onKey)
				close()
			}
		}
		document.addEventListener('keydown', onKey)

		async function submit() {
			const submitBtn = dialog.querySelector('#transfer-submit')
			const cancelBtn = dialog.querySelector('#transfer-cancel')
			if (submitBtn) submitBtn.disabled = true
			if (cancelBtn) cancelBtn.disabled = true

			const hashAlgo = dialog.querySelector('#transfer-hashalgo')?.value ?? ''
			const hash = dialog.querySelector('#transfer-hash')?.value ?? ''
			const dir = currentPath.replace(/\/$/, '')
			const single = rows.length === 1

			const transfers = rows.map(row => ({
				url:      row.url,
				path:     dir + '/' + getRowFilename(row),
				hashAlgo: single ? hashAlgo : '',
				hash:     single ? hash     : '',
			}))

			try {
				const resp = await axios.post(
					generateFilePath('transfer', 'ajax', 'batch.php'),
					{ transfers },
				)
				for (const job of resp.data.jobs) {
					trackJob(job.token, job.path)
				}
				close()
			} catch (error) {
				const msg = (error.response && error.response.status)
					? t('transfer', 'Failed to add the upload to the queue. The server responded with status code {statusCode}.', { statusCode: error.response.status })
					: t('transfer', 'Failed to add the upload to the queue.')
				showError(msg)
				if (submitBtn) submitBtn.disabled = false
				if (cancelBtn) cancelBtn.disabled = false
			}
		}

		renderDialog()
		dialog.querySelector('.transfer-row-url')?.focus()
	})
}

// ─────────────────────────────────────────────────────────────────────────────
// Files app menu entry
// ─────────────────────────────────────────────────────────────────────────────

addNewFileMenuEntry({
	id: 'transfer',
	displayName: t('transfer', 'Upload by link'),
	iconSvgInline: CLOUD_UPLOAD_SVG,
	order: -1,
	if: (context) => (context.permissions & Permission.CREATE) !== 0,
	async handler(context) {
		showDialog(context.path || '/')
	},
})
