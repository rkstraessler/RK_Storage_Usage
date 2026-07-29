(function () {
	'use strict'

	const APP_ID = 'storageusage'
	const FOLDER_BROWSER_URL = '/apps/storageusage/admin/folders'
	const SETTINGS_URL = '/apps/storageusage/admin/folder-settings'
	const JSON_KEY_PATTERN = /^[A-Za-z][A-Za-z0-9_-]{0,63}$/
	let translations = Object.create(null)

	function translate(source, parameters) {
		let translated = typeof translations[source] === 'string'
			? translations[source]
			: source

		Object.entries(parameters || {}).forEach(([key, value]) => {
			translated = translated.split('{' + key + '}').join(String(value))
		})

		return translated
	}

	function createElement(tagName, className, text) {
		const element = document.createElement(tagName)
		if (className) {
			element.className = className
		}
		if (typeof text === 'string') {
			element.textContent = text
		}
		return element
	}

	function createButton(text, className, onClick) {
		const button = createElement('button', className, text)
		button.type = 'button'
		button.addEventListener('click', onClick)
		return button
	}

	function createId() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID()
		}

		return 'folder-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2)
	}

	function initialise() {
		const root = document.getElementById('storageusage-folder-settings')
		if (!root) {
			return
		}

		let initialState = {}
		try {
			initialState = window.OCP.InitialState.loadState(APP_ID, 'folderAdminSettings', {})
		} catch (error) {
			console.error('Storage Usage: could not load the folder settings.', error)
		}
		if (!initialState || typeof initialState !== 'object') {
			initialState = {}
		}
		translations = initialState.translations && typeof initialState.translations === 'object'
			? initialState.translations
			: Object.create(null)

		const availableUnits = Array.isArray(initialState.availableUnits)
			? initialState.availableUnits.map(String)
			: ['Auto', 'B', 'kB', 'KiB', 'MB', 'MiB', 'GB', 'GiB', 'TB', 'TiB']
		const defaultUnit = availableUnits.includes(String(initialState.defaultUnit))
			? String(initialState.defaultUnit)
			: 'Auto'

		const entryList = document.getElementById('storageusage-folder-entries')
		const status = document.getElementById('storageusage-settings-status')
		const addButton = document.getElementById('storageusage-add-folder')
		const saveButton = document.getElementById('storageusage-save-folders')
		const openApiButton = document.getElementById('storageusage-open-api')
		const dialog = document.getElementById('storageusage-folder-browser')
		const dialogCloseButton = document.getElementById('storageusage-browser-close')
		const dialogCancelButton = document.getElementById('storageusage-browser-cancel')
		const selectCurrentButton = document.getElementById('storageusage-select-current')
		const browserBreadcrumbs = document.getElementById('storageusage-browser-breadcrumbs')
		const browserList = document.getElementById('storageusage-browser-list')
		const browserStatus = document.getElementById('storageusage-browser-status')

		let entries = Array.isArray(initialState.entries)
			? initialState.entries.map(normaliseEntry)
			: []
		let editingEntryId = null
		let currentBrowserData = null
		let browserRequestNumber = 0

		function normaliseEntry(entry) {
			const unit = String(entry && entry.unit ? entry.unit : defaultUnit)

			return {
				id: String(entry && entry.id ? entry.id : createId()),
				key: String(entry && entry.key ? entry.key : ''),
				viewUserId: String(entry && entry.viewUserId ? entry.viewUserId : ''),
				fileId: Number(entry && entry.fileId ? entry.fileId : 0),
				storageId: String(entry && entry.storageId ? entry.storageId : ''),
				path: String(entry && entry.path ? entry.path : '/'),
				unit: availableUnits.includes(unit) ? unit : defaultUnit,
				excludeFromTotal: Boolean(entry && entry.excludeFromTotal),
			}
		}

		function setStatus(message, type) {
			status.textContent = message
			status.classList.toggle('storageusage-status--success', type === 'success')
			status.classList.toggle('storageusage-status--error', type === 'error')
		}

		function setBrowserStatus(message, type) {
			browserStatus.textContent = message
			browserStatus.classList.toggle('storageusage-browser-status--error', type === 'error')
		}

		function renderEntries() {
			entryList.replaceChildren()

			if (entries.length === 0) {
				entryList.append(createElement(
					'p',
					'storageusage-empty-state',
					translate('No separate folders have been configured yet.'),
				))
				return
			}

			entries.forEach((entry, index) => {
				entryList.append(renderEntry(entry, index))
			})
		}

		function renderEntry(entry, index) {
			const card = createElement('article', 'storageusage-entry')
			const header = createElement('header', 'storageusage-entry__header')
			const title = createElement(
				'h3',
				'storageusage-entry__title',
				entry.key || translate('Separate folder {number}', { number: index + 1 }),
			)
			header.append(title)

			const removeButton = createButton(
				translate('Remove'),
				'button storageusage-remove-button',
				() => {
					entries = entries.filter((candidate) => candidate.id !== entry.id)
					setStatus(translate('The folder was removed from the configuration. Save to apply the change.'))
					renderEntries()
				},
			)
			removeButton.setAttribute('aria-label', translate('Remove folder {path}', { path: entry.path }))
			header.append(removeButton)
			card.append(header)

			const fields = createElement('div', 'storageusage-entry__fields')
			const keyId = controlId('key', entry.id)
			const keyField = createElement('div', 'storageusage-field')
			const keyLabel = createElement('label', '', translate('JSON key'))
			keyLabel.htmlFor = keyId
			const keyInput = createElement('input', 'storageusage-key-input')
			keyInput.id = keyId
			keyInput.type = 'text'
			keyInput.required = true
			keyInput.maxLength = 64
			keyInput.pattern = '[A-Za-z][A-Za-z0-9_-]{0,63}'
			keyInput.value = entry.key
			keyInput.placeholder = translate('For example: project_files')
			keyInput.autocomplete = 'off'
			keyInput.addEventListener('input', () => {
				entry.key = keyInput.value
				const valid = JSON_KEY_PATTERN.test(entry.key)
				keyInput.setCustomValidity(valid ? '' : translate('Use 1–64 characters. Start with a letter; then use letters, numbers, underscores, or hyphens.'))
				keyInput.setAttribute('aria-invalid', String(!valid))
				title.textContent = entry.key.trim()
					|| translate('Separate folder {number}', { number: index + 1 })
			})
			const keyHintId = controlId('key-hint', entry.id)
			const keyHint = createElement(
				'p',
				'storageusage-field__hint',
				translate('Use 1–64 characters. Start with a letter; then use letters, numbers, underscores, or hyphens.'),
			)
			keyHint.id = keyHintId
			keyInput.setAttribute('aria-describedby', keyHintId)
			keyField.append(keyLabel, keyInput, keyHint)

			const unitId = controlId('unit', entry.id)
			const unitField = createElement('div', 'storageusage-field')
			const unitLabel = createElement('label', '', translate('Output unit'))
			unitLabel.htmlFor = unitId
			const unitSelect = createElement('select', 'storageusage-unit-select')
			unitSelect.id = unitId
			availableUnits.forEach((unit) => {
				const option = createElement('option', '', unit)
				option.value = unit
				option.selected = unit === entry.unit
				unitSelect.append(option)
			})
			unitSelect.addEventListener('change', () => {
				entry.unit = unitSelect.value
			})
			const unitHint = createElement(
				'p',
				'storageusage-field__hint',
				translate('Auto selects a binary unit that fits the folder size.'),
			)
			unitField.append(unitLabel, unitSelect, unitHint)
			fields.append(keyField, unitField)
			card.append(fields)

			const folderRow = createElement('div', 'storageusage-selected-folder')
			const folderDetails = createElement('div', 'storageusage-selected-folder__details')
			folderDetails.append(
				createElement('span', 'storageusage-selected-folder__label', translate('Selected folder')),
				createElement('strong', 'storageusage-selected-folder__path', entry.path || '/'),
			)
			const changeButton = createButton(
				translate('Change folder'),
				'button',
				() => openFolderBrowser(entry.id),
			)
			folderRow.append(folderDetails, changeButton)
			card.append(folderRow)

			const excludeId = controlId('exclude', entry.id)
			const excludeField = createElement('div', 'storageusage-checkbox-field')
			const excludeCheckbox = createElement('input')
			excludeCheckbox.id = excludeId
			excludeCheckbox.type = 'checkbox'
			excludeCheckbox.checked = entry.excludeFromTotal
			excludeCheckbox.addEventListener('change', () => {
				entry.excludeFromTotal = excludeCheckbox.checked
			})
			const excludeText = createElement('div')
			const excludeLabel = createElement(
				'label',
				'storageusage-checkbox-field__label',
				translate('Exclude from total'),
			)
			excludeLabel.htmlFor = excludeId
			excludeText.append(
				excludeLabel,
				createElement(
					'p',
					'storageusage-field__hint',
					translate('When enabled, this folder is returned separately and its size is subtracted from totalUsage.'),
				),
			)
			excludeField.append(excludeCheckbox, excludeText)
			card.append(excludeField)

			return card
		}

		function controlId(prefix, id) {
			return 'storageusage-' + prefix + '-' + id.replace(/[^A-Za-z0-9_-]/g, '-')
		}

		function openFolderBrowser(entryId) {
			editingEntryId = entryId || null
			const entry = entries.find((candidate) => candidate.id === editingEntryId)
			openDialog()
			loadFolder(entry && entry.path ? entry.path : '/')
		}

		function openDialog() {
			if (typeof dialog.showModal === 'function') {
				if (!dialog.open) {
					dialog.showModal()
				}
			} else {
				dialog.setAttribute('open', '')
				dialog.classList.add('storageusage-dialog--fallback')
			}
		}

		function closeDialog() {
			browserRequestNumber += 1
			if (typeof dialog.close === 'function' && dialog.open) {
				dialog.close()
			} else {
				dialog.removeAttribute('open')
				dialog.classList.remove('storageusage-dialog--fallback')
			}
			editingEntryId = null
			currentBrowserData = null
		}

		async function loadFolder(path) {
			const requestNumber = ++browserRequestNumber
			setBrowserStatus(translate('Loading folders…'))
			browserList.replaceChildren()
			browserBreadcrumbs.replaceChildren()
			selectCurrentButton.disabled = true

			try {
				const url = window.OC.generateUrl(FOLDER_BROWSER_URL)
				const data = await requestJson(url + '?path=' + encodeURIComponent(path || '/'))
				if (requestNumber !== browserRequestNumber) {
					return
				}

				currentBrowserData = data
				renderBrowser(data)
				setBrowserStatus('')
			} catch (error) {
				if (requestNumber !== browserRequestNumber) {
					return
				}
				setBrowserStatus(
					error.message || translate('The folders could not be loaded.'),
					'error',
				)
			}
		}

		function renderBrowser(data) {
			const current = getCurrentFolder(data)
			const breadcrumbs = Array.isArray(data.breadcrumbs)
				? data.breadcrumbs
				: buildBreadcrumbs(current.path)

			browserBreadcrumbs.replaceChildren()
			breadcrumbs.forEach((breadcrumb, index) => {
				if (index > 0) {
					const separator = createElement('span', 'storageusage-breadcrumbs__separator', '/')
					separator.setAttribute('aria-hidden', 'true')
					browserBreadcrumbs.append(separator)
				}
				const name = breadcrumb.name || (index === 0 ? translate('Files') : breadcrumb.path)
				const button = createButton(String(name), 'storageusage-breadcrumbs__button', () => {
					loadFolder(String(breadcrumb.path || '/'))
				})
				if (index === breadcrumbs.length - 1) {
					button.setAttribute('aria-current', 'page')
				}
				browserBreadcrumbs.append(button)
			})

			browserList.replaceChildren()
			const folders = Array.isArray(data.folders) ? data.folders : []
			if (folders.length === 0) {
				const emptyItem = createElement(
					'li',
					'storageusage-browser-empty',
					translate('This folder does not contain any subfolders.'),
				)
				browserList.append(emptyItem)
			}

			folders.forEach((folder) => {
				const item = createElement('li', 'storageusage-browser-item')
				const openButton = createButton(
					String(folder.name || folder.path || translate('Folder')),
					'storageusage-browser-item__open',
					() => loadFolder(String(folder.path || '/')),
				)
				openButton.title = translate('Open folder {name}', { name: folder.name || folder.path })
				const selectButton = createButton(
					translate('Select'),
					'button storageusage-browser-item__select',
					() => selectFolder(folder),
				)
				selectButton.setAttribute(
					'aria-label',
					translate('Select folder {name}', { name: folder.name || folder.path }),
				)
				item.append(openButton, selectButton)
				browserList.append(item)
			})

			selectCurrentButton.disabled = !hasFolderIdentity(current)
		}

		function buildBreadcrumbs(path) {
			const breadcrumbs = [{ name: translate('Files'), path: '/' }]
			const segments = String(path || '/').split('/').filter(Boolean)
			let currentPath = ''
			segments.forEach((segment) => {
				currentPath += '/' + segment
				breadcrumbs.push({ name: segment, path: currentPath })
			})
			return breadcrumbs
		}

		function getCurrentFolder(data) {
			const current = data.currentFolder || data.current || {}
			return {
				name: String(current.name || data.name || translate('Files')),
				path: String(current.path || data.path || '/'),
				viewUserId: String(current.viewUserId || data.viewUserId || ''),
				fileId: Number(current.fileId || data.fileId || 0),
				storageId: String(current.storageId || data.storageId || ''),
			}
		}

		function hasFolderIdentity(folder) {
			return Number.isInteger(Number(folder.fileId))
				&& Number(folder.fileId) > 0
				&& String(folder.storageId || '') !== ''
				&& String(folder.path || '') !== ''
		}

		function selectFolder(folder) {
			const browserCurrent = getCurrentFolder(currentBrowserData || {})
			const selection = {
				name: String(folder.name || browserCurrent.name || translate('Folder')),
				path: String(folder.path || browserCurrent.path || '/'),
				viewUserId: String(folder.viewUserId || browserCurrent.viewUserId || ''),
				fileId: Number(folder.fileId || 0),
				storageId: String(folder.storageId || ''),
			}

			if (!hasFolderIdentity(selection)) {
				setBrowserStatus(translate('This folder cannot be selected.'), 'error')
				return
			}

			const existing = entries.find((entry) => entry.id === editingEntryId)
			if (existing) {
				existing.viewUserId = selection.viewUserId
				existing.fileId = selection.fileId
				existing.storageId = selection.storageId
				existing.path = selection.path
			} else {
				entries.push({
					id: createId(),
					key: createUniqueKey(selection.name),
					viewUserId: selection.viewUserId,
					fileId: selection.fileId,
					storageId: selection.storageId,
					path: selection.path,
					unit: defaultUnit,
					excludeFromTotal: false,
				})
			}

			closeDialog()
			renderEntries()
			setStatus(translate('The folder was added. Save to apply the change.'))
		}

		function createUniqueKey(name) {
			let base = String(name || 'folder')
				.normalize('NFKD')
				.replace(/[ßẞ]/g, 'ss')
				.replace(/[\u0300-\u036f]/g, '')
				.replace(/[^A-Za-z0-9_-]+/g, '_')
				.replace(/^[_-]+|[_-]+$/g, '')
			if (!/^[A-Za-z]/.test(base)) {
				base = 'folder_' + base
			}
			base = base.slice(0, 64) || 'folder'
			const usedKeys = new Set(entries.map((entry) => entry.key))
			if (!usedKeys.has(base)) {
				return base
			}

			let suffix = 2
			let candidate = ''
			do {
				const suffixText = '_' + suffix
				candidate = base.slice(0, 64 - suffixText.length) + suffixText
				suffix += 1
			} while (usedKeys.has(candidate))
			return candidate
		}

		function validateEntries() {
			const usedKeys = new Set()
			for (const entry of entries) {
				entry.key = entry.key.trim()
				if (!entry.key) {
					return translate('Enter a JSON key for every selected folder.')
				}
				if (!JSON_KEY_PATTERN.test(entry.key)) {
					return translate('Use 1–64 characters. Start with a letter; then use letters, numbers, underscores, or hyphens.')
				}
				if (usedKeys.has(entry.key)) {
					return translate('Every JSON key must be unique.')
				}
				if (!hasFolderIdentity(entry)) {
					return translate('Select a valid folder for every entry.')
				}
				usedKeys.add(entry.key)
			}

			return ''
		}

		async function saveEntries() {
			const validationMessage = validateEntries()
			if (validationMessage) {
				setStatus(validationMessage, 'error')
				return
			}

			saveButton.disabled = true
			setStatus(translate('Saving folder settings…'))

			try {
				const data = await requestJson(window.OC.generateUrl(SETTINGS_URL), {
					method: 'PUT',
					body: JSON.stringify({ entries }),
				})
				if (Array.isArray(data.entries)) {
					entries = data.entries.map(normaliseEntry)
				}
				renderEntries()
				setStatus(translate('Folder settings saved.'), 'success')
			} catch (error) {
				setStatus(
					error.message || translate('The folder settings could not be saved.'),
					'error',
				)
			} finally {
				saveButton.disabled = false
			}
		}

		async function requestJson(url, options) {
			const requestOptions = Object.assign({
				credentials: 'same-origin',
				headers: {},
			}, options || {})
			requestOptions.headers = Object.assign({
				Accept: 'application/json',
				requesttoken: window.OC.requestToken,
			}, requestOptions.headers)
			if (requestOptions.body) {
				requestOptions.headers['Content-Type'] = 'application/json'
			}

			const response = await window.fetch(url, requestOptions)
			let payload = {}
			try {
				payload = await response.json()
			} catch (error) {
				if (response.ok) {
					return payload
				}
			}

			if (!response.ok) {
				throw new Error(payload.message || translate('The request failed.'))
			}

			return payload
		}

		addButton.addEventListener('click', () => openFolderBrowser(null))
		saveButton.addEventListener('click', saveEntries)
		openApiButton.addEventListener('click', () => {
			const apiUrl = window.OC.generateUrl('/apps/storageusage/api/v1/usage')
			const apiWindow = window.open(apiUrl, '_blank', 'noopener,noreferrer')
			if (apiWindow) {
				apiWindow.opener = null
			}
		})
		dialogCloseButton.addEventListener('click', closeDialog)
		dialogCancelButton.addEventListener('click', closeDialog)
		selectCurrentButton.addEventListener('click', () => {
			if (currentBrowserData) {
				selectFolder(getCurrentFolder(currentBrowserData))
			}
		})
		dialog.addEventListener('cancel', (event) => {
			event.preventDefault()
			closeDialog()
		})

		renderEntries()
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialise)
	} else {
		initialise()
	}
})()
