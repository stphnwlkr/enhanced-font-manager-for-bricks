import { prepareFont, startCodec } from './font-files.js?ver=0.3.0';

const manager = document.getElementById('bvf-manager');
if (manager) start(manager);

function start(root) {
	const nav = root.querySelector('.bvf-tabs');
	const outlet = root.querySelector('.bvf-content');
	const status = root.querySelector('.bvf-status');
	const canUpload = root.dataset.upload === '1';
	const inBuilder = root.dataset.builder === '1';
	const localFonts = { sans: 'Arial', serif: 'Times New Roman', mono: 'Courier New' };
	const sampleDefault = 'The quick brown fox jumps over the lazy dog.';
	const defaults = { enabled: true, preload: false, display: 'swap', variable: '', fallback: 'system-ui, sans-serif', roles: [], metrics: {} };
	const fontStyle = document.createElement('style');
	const googleStyle = document.createElement('link');
	googleStyle.rel = 'stylesheet';
	const prefs = { sample: '', size: 32, layout: 'grid' };
	try { Object.assign(prefs, JSON.parse(localStorage.getItem('bvf-preview-settings')) || {}); } catch {}
	let library = { families: [], files: {} };
	let currentView = 'library';
	let edit = null;
	let catalogue;
	let busy = false;
	let previewSequence = 0;
	let activeFonts = [];
	let pendingFamilies = [];
	let refreshTimer;

	function el(tag, props = {}, ...children) {
		const node = Object.assign(document.createElement(tag), props);
		node.append(...children.filter((child) => child !== undefined && child !== null));
		return node;
	}
	const text = (tag, value, className = '') => el(tag, { textContent: value, className });
	const button = (label, onclick, kind = '') => el('button', {
		type: 'button', textContent: label, onclick, className: `bvf-button ${kind}`,
	});
	let labelId = 0;
	const field = (label, input) => {
		const title = text('span', label, 'bvf-field-label');
		title.id = `bvf-label-${++labelId}`;
		input.setAttribute('aria-labelledby', title.id);
		const control = input.tagName === 'SELECT' ? el('span', { className: 'bvf-select' }, input) : input;
		return el('label', { className: 'bvf-field' }, title, control);
	};
	const row = (...children) => el('div', { className: 'bvf-row' }, ...children);
	const fieldInput = (value, oninput, props = {}) => el('input', {
		type: 'text', value, ...props,
		oninput: (event) => oninput(event.target.value),
	});
	const select = (options, value, onchange) => {
		const control = el('select', { onchange: (event) => onchange(event.target.value) });
		for (const [key, label] of options) control.add(new Option(label, key));
		control.value = value;
		return control;
	};
	const checkbox = (label, checked, change) => el('label', { className: 'bvf-check' },
		el('input', { type: 'checkbox', checked, onchange: (event) => change(event.target.checked) }), label);
	const section = (name, ...children) => el('section', { className: 'bvf-panel' }, text('h2', name), ...children);
	const fontStack = (family) => `${JSON.stringify(family.name)}, ${family.settings.fallback}`;
	const bytes = (count) => `${Number((count / 1024).toFixed(1))} KB`;

	function announce(message = '', error = false) {
		status.textContent = message;
		status.classList.toggle('bvf-error', error);
	}

	async function request(op, payload = {}, uploads = []) {
		const form = new FormData();
		form.set('action', 'bvf_library');
		form.set('nonce', root.dataset.nonce);
		form.set('payload', JSON.stringify({ op, ...payload }));
		uploads.forEach((file, i) => {
			if (file instanceof File) form.set(`face_${i}`, file);
			else if (file) for (const [format, asset] of Object.entries(file)) form.set(`face_${i}_${format}`, asset);
		});
		const reply = await fetch(root.dataset.endpoint, { method: 'POST', body: form });
		const result = await reply.json().catch(() => null);
		if (!result?.success) throw new Error(typeof result?.data === 'string' ? result.data : 'The site could not complete the request. Check the request size or reload the font manager.');
		return result.data;
	}

	function builderState() {
		for (const node of document.querySelectorAll('.brx-body, [data-v-app]')) {
			const app = node.__vue_app__;
			const state = app?.config?.globalProperties?.$_state || app?._instance?.proxy?.$_state;
			if (state?.fonts) return state;
		}
		return null;
	}

	// Bricks applies the raw custom family name to its cards, which fails for names containing quotes.
	function updateNativeFontCardPreviews() {
		const cards = document.querySelectorAll('.bricks-popup .fonts-grid .font-item');
		if (!cards.length) return;

		const families = new Set(Object.values(builderState()?.fonts.custom || {}).map((font) => font.family));
		for (const card of cards) {
			if (card.querySelector('.font-type')?.textContent !== 'custom') continue;
			const family = card.querySelector('.font-title')?.textContent;
			const preview = card.querySelector('.font-preview');
			if (!preview || !families.has(family) || !/['",\\]/.test(family)) continue;

			const quoted = JSON.stringify(family);
			if (preview.style.fontFamily !== quoted) preview.style.fontFamily = quoted;
		}
	}

	function pendingNativeFontEdits() {
		const changes = builderState()?.fontChanges;
		return changes && Object.values(changes).some((group) => Object.values(group || {}).some((items) => Object.keys(items || {}).length));
	}

	function syncBuilder() {
		const state = inBuilder && builderState();
		if (!state || pendingNativeFontEdits() || state.draftView || state.isApplyingAuthoritativeSaveResult) return;

		const native = library.nativeFonts;
		if (state.globalSaveOwnership?.schemaVersion) {
			if (!native?.items || !native.ownership) return;

			state.isApplyingAuthoritativeSaveResult = true;
			try {
				state.globalSaveOwnership = { ...state.globalSaveOwnership, customFonts: native.ownership };
				state.fonts = { ...state.fonts, custom: native.items };
			} finally {
				queueMicrotask(() => { state.isApplyingAuthoritativeSaveResult = false; });
			}
		} else {
			const custom = {};
			for (const family of published()) {
				const id = `custom_font_${family.id}`;
				const old = state.fonts.custom?.[id];
				custom[id] = old?.family === family.name && old.fontFaces === family.css
					? old : { id, family: family.name, fontFaces: family.css };
			}
			state.fonts = { ...state.fonts, custom };
		}
		const frame = document.querySelector('#bricks-builder-iframe, iframe#bricks-iframe');
		const doc = frame?.contentDocument;
		if (doc?.head) {
			let sheet = doc.getElementById('bvf-builder-fonts');
			if (!sheet) {
				sheet = doc.createElement('style');
				sheet.id = 'bvf-builder-fonts';
				doc.head.append(sheet);
			}
			sheet.textContent = liveCss();
		}
	}

	const published = () => library.families.filter((family) => family.status === 'publish');
	const liveCss = () => published().map((family) => family.served).join('\n');

	async function reload(syncNative = false) {
		library = await request('read', { native: inBuilder && syncNative });
		fontStyle.textContent = liveCss();
		if (syncNative) syncBuilder();
	}

	function work(message, callback) {
		if (busy) return;
		busy = true;
		root.setAttribute('aria-busy', 'true');
		announce(message);
		return Promise.resolve().then(callback).catch((error) => announce(error.message, true)).finally(() => {
			busy = false;
			root.removeAttribute('aria-busy');
		});
	}

	function requireQuietBuilder() {
		if (pendingNativeFontEdits()) throw new Error('Save the changes in Bricks’ native Font Manager first, then return here.');
	}

	function prefsBar(update, allowLayout = false) {
		const sample = fieldInput(prefs.sample, set('sample'), { type: 'search', placeholder: sampleDefault });
		function set(key) {
			return (value) => {
				prefs[key] = value;
				try { localStorage.setItem('bvf-preview-settings', JSON.stringify(prefs)); } catch {}
				update();
			};
		}
		return row(
			field('Sample text', sample),
			field('Preview size', fieldInput(prefs.size, set('size'), { type: 'range', min: 16, max: 76 })),
			button('Reset sample', () => { sample.value = ''; set('sample')(''); }),
			allowLayout ? field('Layout', select([['grid', 'Grid'], ['list', 'List']], prefs.layout, set('layout'))) : null,
		);
	}

	function go(view) {
		if (busy) return;
		edit = null;
		pendingFamilies = [];
		currentView = view;
		announce();
		render();
	}

	function render() {
		previewSequence++;
		activeFonts.forEach((font) => document.fonts.delete(font));
		activeFonts = [];
		googleStyle.remove();
		for (const control of nav.querySelectorAll('[data-view]')) {
			control.setAttribute('aria-pressed', String(control.dataset.view === currentView));
			control.hidden = control.dataset.view === 'upload' && !canUpload;
		}
		if (currentView === 'library') showLibrary();
		if (currentView === 'files') showFiles();
		if (currentView === 'upload') showUpload();
		if (currentView === 'family') showEditor();
		if (currentView === 'google') showGoogle();
		if (currentView === 'transfer') showTransfer();
		if (currentView === 'settings') showSettings();
	}

	function showLibrary() {
		const cards = el('div', { className: 'bvf-cards' });
		const search = fieldInput('', draw, { type: 'search', placeholder: 'Search families' });
		const filter = select([['active', 'Active'], ['trash', 'Trash']], 'active', draw);
		function draw() {
			cards.classList.toggle('bvf-list', prefs.layout === 'list');
			const results = library.families.filter((item) => item.name.toLowerCase().includes(search.value.toLowerCase())
				&& (filter.value === 'trash' ? item.status === 'trash' : item.status === 'publish'));
			cards.replaceChildren(...results.map((family) => {
				const specimen = text('p', prefs.sample || sampleDefault, 'bvf-card-sample');
				specimen.style.fontSize = `${prefs.size}px`;
				if (family.status === 'publish' && family.settings.enabled) specimen.style.fontFamily = fontStack(family);
				const range = family.faces.map(({ min, max }) => min === max ? min : `${min}–${max}`);
				return el('article', { className: 'bvf-card' },
					text('h3', family.name), specimen,
					text('p', !family.settings.enabled ? 'Disabled' : `${family.faces.length} faces · ${[...new Set(range)].join(', ')}`, 'bvf-muted'),
					family.status === 'trash'
						? button('Restore', () => changeStatus('restore', family))
						: button(`Edit ${family.name}`, () => openFamily(family)),
				);
			}));
			if (!results.length) cards.append(text('p', filter.value === 'trash' ? 'No families in the trash.' : 'No matching font families.'));
		}
		outlet.replaceChildren(row(field('Search families', search), field('Show', filter),
			canUpload ? button('Add fonts', () => go('upload'), 'bvf-primary') : null), prefsBar(draw, true), cards);
		draw();
	}

	function changeStatus(op, family) {
		work(op === 'trash' ? 'Moving family to the trash…' : 'Restoring family…', async () => {
			requireQuietBuilder();
			await request(op, { id: family.id, revision: family.revision });
			await reload(true);
			edit = null;
			currentView = 'library';
			render();
			announce(op === 'trash' ? 'Family moved to the trash. Its files are still available.' : 'Family restored.');
		});
	}

	function openFamily(family) {
		edit = structuredClone(family);
		currentView = 'family';
		announce();
		render();
	}

	function showFiles() {
		const picked = new Set();
		const records = Object.values(library.files);
		const table = el('table', { className: 'bvf-table' });
		const filter = select([['all', 'All'], ['used', 'In a family'], ['unused', 'Unused']], 'all', draw);
		const search = fieldInput('', draw, { type: 'search', placeholder: 'Search files' });
		const destination = select([['', 'New family'], ...published().map((f) => [String(f.id), f.name])], '', () => {});
		const convertible = (file) => /^\d+$/.test(file.id) && ['woff', 'ttf', 'otf'].includes(file.format);
		const convert = canUpload ? button('Convert to WOFF2', () => {
			const selected = [...picked].map((id) => library.files[id]);
			work('Preparing existing font files…', () => convertFiles(selected.filter(convertible), selected.length));
		}) : null;
		function updateSelection() {
			if (convert) convert.disabled = ![...picked].some((id) => convertible(library.files[id]));
		}
		updateSelection();
		const bulk = row(
			field('Add selected files to', destination),
			button('Add files', () => {
				if (!picked.size) return announce('Select one or more font files.');
				const target = published().find((item) => String(item.id) === destination.value);
				const files = [...picked].map((id) => library.files[id]);
				if (files.some((file) => !/^\d+$/.test(file.id))) {
					return announce('Fonts downloaded by the native Bricks manager belong to their original family.', true);
				}
				work('Reading files…', async () => {
					edit = target ? structuredClone(target) : newFamily('');
					for (const file of files) {
						const url = await fetch(file.url);
						if (!url.ok) throw new Error(`Could not read ${file.name}.`);
						const preserved = library.families.flatMap((item) => item.faces).find((face) => Object.values(face.sources).some((source) => String(source) === String(file.id)));
						const meta = preserved || await processFiles([new File([await url.blob()], file.name)]).then((results) => results[0]);
						edit.name ||= meta.family || file.name.replace(/\.[^.]+$/, '');
						edit.faces.push({ ...meta, sources: { [file.format]: file.id }, upload: undefined, previewBytes: undefined });
					}
					currentView = 'family';
					render();
					announce('Confirm the face descriptors, then save the family.');
				});
			}),
			convert,
			button('Delete permanently', () => {
				if (!picked.size) return announce('Select one or more font files to delete.');
				const files = [...picked].map((id) => library.files[id]);
				if (files.length > 100) return announce('Select up to 100 files at a time.', true);
				const names = [...new Set(files.flatMap((file) => Object.values(file.families)))];
				const selected = files.map((file) => file.name).join(', ');
				const affected = names.length
					? `\n\nBricks will remove these files from: ${names.join(', ')}. Faces that lose their last file will be removed. Where a face has no remaining source, pages may display a fallback font.`
					: '';
				if (!confirm(`Permanently delete ${files.length === 1 ? selected : `${files.length} files: ${selected}`}?\n\nThis cannot be undone.${affected}`)) return;
				work('Deleting selected files…', async () => {
					requireQuietBuilder();
					const result = await request('delete-files', {
						files: files.map((file) => ({ id: file.id, families: Object.keys(file.families) })),
					});
					await reload(result.families > 0);
					render();
					const affectedCount = result.families ? ` Updated ${result.families} Bricks ${result.families === 1 ? 'family' : 'families'}.` : '';
					const errors = result.failed.length ? ` Could not remove: ${result.failed.join(', ')}.` : '';
					announce(`Permanently deleted ${result.deleted.length} ${result.deleted.length === 1 ? 'file' : 'files'}.${affectedCount}${errors}`, !!result.failed.length);
				});
			}, 'bvf-danger'),
		);
		function draw() {
			const matches = records.filter((file) => file.name.toLowerCase().includes(search.value.toLowerCase())
				&& (filter.value === 'all' || (filter.value === 'used') === !!Object.keys(file.families).length));
			const tbody = el('tbody');
			for (const file of matches) {
				const id = String(file.id);
				const checked = el('input', { type: 'checkbox', checked: picked.has(id) });
				checked.setAttribute('aria-label', `Select ${file.name}`);
				checked.onchange = () => {
					if (checked.checked) picked.add(id);
					else picked.delete(id);
					updateSelection();
				};
				tbody.append(el('tr', {}, el('td', {}, checked), el('td', {}, el('a', { href: file.url, textContent: file.name, download: file.name })),
					text('td', file.format.toUpperCase()), text('td', bytes(file.bytes)), text('td', Object.values(file.families).join(', ') || 'Unused')));
			}
			const all = el('input', { type: 'checkbox' });
			all.setAttribute('aria-label', 'Select all filtered files');
			all.checked = matches.length > 0 && matches.every((file) => picked.has(String(file.id)));
			all.onchange = () => {
				for (const file of matches) all.checked ? picked.add(String(file.id)) : picked.delete(String(file.id));
				updateSelection();
				draw();
			};
			table.replaceChildren(el('thead', {}, el('tr', {}, el('th', {}, all),
				...['File', 'Format', 'Size', 'Families'].map((label) => text('th', label)))), tbody);
			if (!matches.length) tbody.append(el('tr', {}, el('td', { colSpan: 5, textContent: 'No matching font files.' })));
		}
		outlet.replaceChildren(row(field('Search files', search), field('Filter', filter)), table, bulk,
			canUpload ? uploadControl('Upload unassigned files', (files) => work('Converting and uploading font files…', async () => {
				if (files.length > 20) throw new Error('Choose up to 20 font files.');
				const faces = await processFiles(files);
				await request('upload-files', {}, faces.map((face) => face.upload));
				await reload();
				render();
				announce(`Uploaded ${faces.length} unassigned font files.`);
			})) : el('span'));
		if (convert) outlet.append(text('p', 'Convert saved WOFF, TTF or OTF files to WOFF2 and update their Bricks families. Original files stay in the library.', 'bvf-muted'));
		draw();
	}

	async function convertFiles(files, selectedCount) {
		requireQuietBuilder();
		const results = [];
		let completed = 0;
		let converted = 0;
		const codec = startCodec(root.dataset.worker);
		try {
			for (const file of files) {
				announce(`Converting ${++completed} of ${files.length} files: ${file.name}`);
				try {
					if (file.bytes > Number(root.dataset.max)) throw new Error('The file exceeds the upload limit.');
					const response = await fetch(file.url);
					if (!response.ok) throw new Error('The source file could not be downloaded.');
					const blob = await response.blob();
					if (!blob.size || blob.size > Number(root.dataset.max)) throw new Error('The file is empty or exceeds the upload limit.');
					const source = new File([blob], file.name);
					const face = await prepareFont(source, codec);
					if (face.upload === source) throw new Error('The converter could not produce a WOFF2 file.');
					const checked = await prepareFont(face.upload, codec, false);
					const axes = (font) => JSON.stringify(font.axes.map(({ tag, min, initial, max }) => [tag, min, initial, max]).sort());
					if (axes(face) !== axes(checked)) throw new Error('The WOFF2 file changed the font’s variation axes.');
					await new FontFace('bvf-conversion-check', checked.previewBytes).load();
					const digest = await globalThis.crypto?.subtle?.digest('SHA-256', await blob.arrayBuffer());
					const sha256 = digest ? Array.from(new Uint8Array(digest), (value) => value.toString(16).padStart(2, '0')).join('') : undefined;
					const result = await request('convert-file', { source: file.id, sha256 }, [face.upload]);
					if (result.status === 'converted') {
						converted++;
						const families = result.families === 1 ? '1 Bricks family' : `${result.families} Bricks families`;
						results.push(`${file.name}: ${result.families ? `updated ${families}.` : 'WOFF2 copy added to Files.'}`);
					} else {
						results.push(`${file.name}: ${result.message}`);
					}
				} catch (error) {
					results.push(`${file.name}: ${error.message}`);
				}
			}
		} finally {
			codec.close();
		}
		await reload(converted > 0);
		render();
		const other = selectedCount - files.length;
		announce(`Converted ${converted} of ${files.length} eligible files.${other ? ` ${other} ineligible selection(s) skipped.` : ''}`);
		outlet.prepend(section('Conversion results', el('ul', {}, ...results.map((result) => text('li', result)))));
	}

	function newFamily(name) {
		return { id: 0, name, status: 'publish', faces: [], settings: structuredClone(defaults), css: '', served: '' };
	}

	async function processFiles(files, autoConvert = true) {
		if (!files.length || files.some((file) => !file.size || file.size > Number(root.dataset.max))) {
			throw new Error('Choose font files within the site’s upload limit.');
		}
		let codec;
		try { codec = startCodec(root.dataset.worker); } catch {}
		try {
			const faces = [];
			for (const file of files) {
				const face = await prepareFont(file, codec, autoConvert);
				faces.push({ ...face, sources: {}, unicode: '' });
			}
			return faces;
		} finally {
			codec?.close();
		}
	}

	function uploadControl(label, handler) {
		const input = el('input', { type: 'file', accept: '.woff2,.woff,.ttf,.otf', multiple: true });
		input.onchange = () => {
			const files = Array.from(input.files);
			input.value = '';
			if (files.length) handler(files);
		};
		return field(label, input);
	}

	function showUpload() {
		const begin = (files) => work('Reading OpenType metadata…', async () => {
			const faces = await processFiles(files);
			const groups = Map.groupBy ? Map.groupBy(faces, (face) => face.family || face.original.replace(/\.[^.]+$/, '')) : new Map();
			if (!Map.groupBy) {
				for (const face of faces) {
					const name = face.family || face.original.replace(/\.[^.]+$/, '');
					groups.set(name, [...(groups.get(name) || []), face]);
				}
			}
			const queue = [...groups].map(([name, faces]) => ({ ...newFamily(name), faces }));
			pendingFamilies = queue.slice(1);
			edit = queue[0];
			currentView = 'family';
			render();
			announce(queue.length > 1 ? `${queue.length} families detected. Review and save each family.` : 'Review the detected faces and save the family.');
		});
		const drop = section('Upload fonts', uploadControl('Choose files', begin),
			text('p', 'Drop WOFF2, WOFF, TTF or OTF files here. The browser converts supported files to WOFF2.', 'bvf-muted'));
		drop.classList.add('bvf-drop');
		drop.ondragover = (event) => { event.preventDefault(); drop.classList.add('bvf-drag'); };
		drop.ondragleave = () => drop.classList.remove('bvf-drag');
		drop.ondrop = (event) => {
			event.preventDefault();
			drop.classList.remove('bvf-drag');
			begin(Array.from(event.dataTransfer.files));
		};
		outlet.replaceChildren(drop);
	}

	function slug() {
		return edit.settings.variable || (edit.name.toLowerCase().match(/[a-z0-9]+/g) ? `--font-${edit.name.toLowerCase().match(/[a-z0-9]+/g).join('-')}` : `--font-${edit.id || 'family'}`);
	}

	async function copy(value) {
		try {
			await navigator.clipboard.writeText(value);
			announce('Copied.');
		} catch {
			const target = el('textarea', { value, readOnly: true });
			status.replaceChildren(text('span', 'Press your copy shortcut to copy the selected text.'), target);
			target.focus();
			target.select();
		}
	}

	function showEditor() {
		const faces = edit.faces;
		const settings = edit.settings;
		const form = el('form', { className: 'bvf-editor' });
		const specimen = text('p', '', 'bvf-specimen');
		const axes = el('div');
		const choice = select(faces.map((face, index) => [String(index), faceTitle(face)]), '0', updatePreview);
		const specRoot = section('Live preview', prefsBar(drawSample), field('Preview face', choice), axes, specimen);
		const metricsText = text('p', settings.metrics.local ? `Size matched ${localFonts[settings.metrics.local]} at ${settings.metrics.size}%.` : 'Match a local fallback to this family’s metrics.', 'bvf-muted');
		const fallback = select(Object.entries(localFonts), settings.metrics.local || 'sans', () => {});
		let activeFamily = '';
		let axisControls = [];
		function drawSample() {
			specimen.textContent = prefs.sample || sampleDefault;
			specimen.style.fontSize = `${prefs.size}px`;
			specimen.style.fontFamily = activeFamily || settings.fallback;
			specimen.style.fontVariationSettings = axisControls.length
				? axisControls.map(([axis, slider]) => `"${axis.tag}" ${slider.value}`).join(', ') : 'normal';
		}

		async function updatePreview() {
			const face = faces[Number(choice.value)];
			const seq = ++previewSequence;
			axes.replaceChildren();
			axisControls = [];
			if (!face) {
				specimen.textContent = 'Add a font file to preview this family.';
				return;
			}
			specimen.textContent = 'Loading specimen…';
			try {
				if (!face.previewBytes) {
					const file = Object.values(face.sources).map((source) => library.files[source]).find(Boolean);
					if (!file) throw new Error('Preview file missing.');
					const response = await fetch(file.url);
					if (!response.ok) throw new Error('Could not load the preview font.');
					const raw = new File([await response.blob()], file.name);
					if (file.format === 'eot') throw new Error('This older format has no browser preview.');
					const metadata = await processFiles([raw], false).then((results) => results[0]);
					face.axes = metadata.axes;
					face.previewBytes = metadata.previewBytes;
				}
				const font = await new FontFace(`bvf-specimen-${seq}`, face.previewBytes).load();
				if (seq !== previewSequence) return;
				activeFonts.forEach((old) => document.fonts.delete(old));
				activeFonts = [font];
				document.fonts.add(font);
				activeFamily = font.family;
				for (const axis of face.axes || []) {
					const value = text('output', String(axis.initial));
					const slider = fieldInput(axis.initial, (weight) => {
						value.textContent = String(Number(Number(weight).toFixed(2)));
						drawSample();
					}, { type: 'range', min: axis.min, max: axis.max, step: 'any' });
					axisControls.push([axis, slider]);
					axes.append(el('label', { className: 'bvf-axis' }, text('span', `${axis.name} (${axis.tag})`), slider, value));
				}
				drawSample();
			} catch (error) {
				if (seq === previewSequence) specimen.textContent = error.message;
			}
		}

		const variableInput = fieldInput(settings.variable, (value) => { settings.variable = value; }, { placeholder: edit.variableName || slug(), pattern: '--[a-z][a-z0-9_\\-]{0,75}' });
		const familyPanel = section('Family',
			field('Name', fieldInput(edit.name, (value) => { edit.name = value; variableInput.placeholder = slug(); }, { maxLength: 150, required: true })),
			row(field('CSS variable', variableInput), button('Copy variable', () => {
				if (!edit.variableName) return announce('Save the family to copy its published CSS variable.');
				copy(`var(${edit.variableName})`);
			})),
			field('Fallback stack', fieldInput(settings.fallback, (value) => { settings.fallback = value; })),
			field('Font display', select(['swap', 'fallback', 'optional', 'block', 'auto'].map((x) => [x, x]), settings.display, (value) => { settings.display = value; })),
			checkbox('Enabled', settings.enabled, (value) => { settings.enabled = value; }),
			checkbox('Preload', settings.preload, (value) => { settings.preload = value; }),
			row(field('Match fallback', fallback), button('Measure fallback', () => work('Measuring the specimen…', async () => {
				if (!activeFamily) throw new Error('Load a preview face before measuring.');
				settings.metrics = measure(activeFamily, fallback.value);
				metricsText.textContent = `Size matched ${localFonts[fallback.value]} at ${settings.metrics.size}%.`;
				announce('Fallback metrics measured. Save to apply.');
			})), button('Clear metrics', () => { settings.metrics = {}; metricsText.textContent = 'Fallback metrics cleared.'; })),
			metricsText,
		);
		const rolePanel = section(library.acss ? 'Automatic.css' : 'Typography roles',
			...[['heading', 'Headings'], ['text', 'Body text']].map(([role, name]) => {
				const previous = published().find((item) => item.id !== edit.id && item.settings.roles.includes(role));
				return el('div', {}, checkbox(`Use for ${name.toLowerCase()}`, settings.roles.includes(role), (value) => {
					settings.roles = value ? [...new Set([...settings.roles, role])] : settings.roles.filter((item) => item !== role);
				}), previous ? text('p', `Currently assigned to ${previous.name}.`, 'bvf-muted') : null);
			}),
		);
		const facePanel = section('Faces', ...faces.map((face, index) => showFace(face, index)));
		if (canUpload) facePanel.append(uploadControl('Upload more faces', (files) => work('Reading font files…', async () => {
			faces.push(...await processFiles(files));
			render();
			announce();
		})));
		const loose = Object.values(library.files).filter((file) => /^\d+$/.test(file.id));
		if (loose.length) {
			const existing = select(loose.map((file) => [String(file.id), file.name]), String(loose[0].id), () => {});
			facePanel.append(row(field('Existing font file', existing), button('Add file', () => {
				const file = library.files[existing.value];
				if (!file) return;
				const linked = library.families.flatMap((item) => item.faces).find((face) => Object.values(face.sources).includes(file.id));
				work('Reading font metadata…', async () => {
					const response = await fetch(file.url);
					if (!response.ok) throw new Error('Could not read the font file.');
					const loaded = linked || await processFiles([new File([await response.blob()], file.name)], false).then((items) => items[0]);
					faces.push({ ...loaded, sources: { [file.format]: file.id }, upload: undefined });
					render();
					announce();
				});
			})));
		}
		const cssPanel = edit.id ? section('Saved CSS',
			cssField('Exact Bricks-generated CSS', edit.css),
			cssField('CSS with font manager settings', edit.served),
		) : null;
		const save = el('button', { className: 'bvf-button bvf-primary', type: 'submit', textContent: edit.id ? 'Save family' : 'Add to Bricks' });
		const actions = row(save, button('Back to library', () => go('library')),
			edit.id ? button('Move to trash', () => {
				if (confirm(`Move ${edit.name} to the trash? Pages using it will fall back until it is restored.`)) changeStatus('trash', edit);
			}, 'bvf-danger') : null,
		);
		actions.classList.add('bvf-actions');
		form.append(el('div', { className: 'bvf-pair' }, familyPanel, specRoot), facePanel, rolePanel, cssPanel || '', actions);
		form.onsubmit = (event) => {
			event.preventDefault();
			work('Saving native Bricks font…', async () => {
				requireQuietBuilder();
				if (!faces.length) throw new Error('Add at least one face.');
				const result = await saveFamily(edit);
				await reload(true);
				edit = pendingFamilies.shift() || structuredClone(library.families.find((family) => family.id === result.id));
				render();
				announce(edit.id ? 'Saved. The family is available in the Bricks Typography picker.' : 'Saved. Review the next family.');
			});
		};
		outlet.replaceChildren(form);
		updatePreview();

		function faceTitle(face) {
			return face.original || Object.values(face.sources).map((key) => library.files[key]?.name || key).join(', ');
		}

		function showFace(face, index) {
			const style = ['normal', 'italic', 'oblique'].map((item) => [item, item]);
			const weights = (key) => fieldInput(face[key], (value) => { face[key] = value; }, { type: 'number', step: 'any', min: 1, max: 1000, required: true });
			return el('fieldset', { className: 'bvf-face' },
				text('legend', faceTitle(face)),
				row(field('Minimum weight', weights('min')), field('Maximum weight', weights('max')),
					field('Style', select(style, face.style, (value) => { face.style = value; })),
					field('Unicode range', fieldInput(face.unicode, (value) => { face.unicode = value; }, { placeholder: 'U+0000-00FF' })),
					button('Remove face', () => { faces.splice(index, 1); render(); }, 'bvf-danger')),
				face.note ? text('p', face.note, 'bvf-muted') : null,
			);
		}
	}

	function measure(family, local) {
		const canvas = document.createElement('canvas').getContext('2d');
		const word = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		canvas.font = `100px ${family}`;
		const primary = canvas.measureText(word);
		canvas.font = `100px "${localFonts[local]}"`;
		const fallback = canvas.measureText(word);
		const ratio = primary.width / fallback.width;
		if (!Number.isFinite(ratio) || ratio < 0.25 || ratio > 4 || !primary.fontBoundingBoxAscent) {
			throw new Error('The browser could not measure this fallback.');
		}
		return {
			local, size: Number((ratio * 100).toFixed(2)),
			ascent: Number((primary.fontBoundingBoxAscent / ratio).toFixed(2)),
			descent: Number((primary.fontBoundingBoxDescent / ratio).toFixed(2)),
			gap: 0,
		};
	}

	function cssField(label, value) {
		const area = el('textarea', { value, readOnly: true, spellcheck: false, rows: 4 });
		const action = button(label === 'Exact Bricks-generated CSS' ? 'Copy CSS' : 'Copy served CSS', () => copy(area.value));
		action.disabled = !value;
		return el('div', { className: 'bvf-code' }, field(label, area), action);
	}

	function saveFamily(family) {
		const { id, name, revision, settings } = family;
		const faces = family.faces.map(({ min, max, style, unicode, sources }) => ({ min, max, style, unicode, sources }));
		return request('save', { id, name, revision, settings, faces }, family.faces.map((face) => face.uploads || face.upload));
	}

	function showGoogle() {
		if (library.googleDisabled) {
			outlet.replaceChildren(section('Google Fonts', text('p', 'Google Fonts is disabled in Bricks settings. Locally hosted families are still in your library.')));
			return;
		}
		const catalog = el('div', { className: 'bvf-cards' });
		const query = fieldInput('', draw, { type: 'search', placeholder: 'Search Google Fonts' });
		const order = select([['popular', 'Popular'], ['new', 'Newest'], ['name', 'A–Z']], 'popular', draw);
		const category = select([['', 'All categories']], '', draw);
		const language = select([['', 'All languages']], '', draw);
		const variable = el('input', { type: 'checkbox', onchange: draw });
		const variableOnly = el('label', { className: 'bvf-check' }, variable, 'Variable only');
		let limit = 30;
		function draw() {
			const needle = query.value.toLowerCase();
			const fonts = Object.values(catalogue || {}).filter((font) =>
				font.name.toLowerCase().includes(needle)
				&& (!category.value || font.category === category.value)
				&& (!language.value || font.subsets.includes(language.value))
				&& (!variable.checked || font.weight.length),
			);
			fonts.sort((a, b) => {
				if (order.value === 'popular') return a.popularity - b.popularity || a.name.localeCompare(b.name);
				if (order.value === 'new') return b.date.localeCompare(a.date) || a.name.localeCompare(b.name);
				return a.name.localeCompare(b.name);
			});
			catalog.replaceChildren(...fonts.slice(0, limit).map((font) => el('article', { className: 'bvf-card' },
				text('h3', font.name), text('p', `${font.category} · ${font.weight.length ? font.weight.join('–') : `${font.cuts.length} styles`}`, 'bvf-muted'),
				button(`Preview ${font.name}`, () => showGoogleFamily(font)),
			)));
			if (fonts.length > limit) catalog.append(button('Show more fonts', () => { limit += 30; draw(); }));
			if (!fonts.length) catalog.append(text('p', 'No matching Google Fonts.'));
		}
		const controls = row(field('Search Google Fonts', query), field('Category', category),
			field('Language', language), field('Sort', order), variableOnly);
		outlet.replaceChildren(controls, catalog);
		const populate = () => {
			const fonts = Object.values(catalogue);
			for (const value of [...new Set(fonts.map((font) => font.category).filter(Boolean))].sort()) category.add(new Option(value, value));
			for (const value of [...new Set(fonts.flatMap((font) => font.subsets))].sort()) language.add(new Option(value, value));
			draw();
			announce();
		};
		work('Loading the Google Fonts catalogue…', async () => {
			const result = await request('google-list');
			if (result.disabled === true) {
				library.googleDisabled = true;
				catalogue = null;
				showGoogle();
				announce();
				return;
			}
			catalogue = result;
			populate();
		});
	}

	function showGoogleFamily(font) {
		const existing = published().find((item) => item.name.toLowerCase() === font.name.toLowerCase());
		const cuts = new Set(font.cuts.includes('400') ? ['400'] : font.cuts.slice(0, 1));
		const subsets = new Set(font.subsets.includes('latin') ? ['latin'] : font.subsets.slice(0, 1));
		let variable = !!font.weight.length;
		let weight = font.weight.length ? Math.min(Math.max(400, font.weight[0]), font.weight[1]) : Number.parseInt([...cuts][0], 10) || 400;
		let italic = false;
		const sample = text('p', prefs.sample || sampleDefault, 'bvf-specimen');
		function drawSample() {
			sample.textContent = prefs.sample || sampleDefault;
			sample.style.fontFamily = JSON.stringify(font.name) + ', sans-serif';
			sample.style.fontSize = `${prefs.size}px`;
			sample.style.fontWeight = weight;
			sample.style.fontStyle = italic ? 'italic' : 'normal';
		}
		const url = () => {
			const range = font.weight.length ? font.weight.join('..') : weight;
			const segment = italic ? `:ital,wght@1,${range}` : `:wght@${range}`;
			googleStyle.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(font.name)}${segment}&display=swap`;
			if (!googleStyle.isConnected) document.head.append(googleStyle);
			drawSample();
		};
		const pickGroup = (labels, selected, formatter) => el('div', { className: 'bvf-choices' },
			...labels.map((label) => checkbox(formatter(label), selected.has(label), (value) => value ? selected.add(label) : selected.delete(label))),
		);
		const save = button(existing ? 'Replace local Google styles' : 'Download to Bricks', () => work('Downloading selected styles to WordPress…', async () => {
			requireQuietBuilder();
			const settings = existing ? existing.settings : { ...defaults, fallback: font.category === 'serif' ? 'serif' : font.category === 'monospace' ? 'monospace' : defaults.fallback };
			const result = await request('google-save', {
				id: existing?.id || 0, name: font.name, revision: existing?.revision,
				settings, cuts: [...cuts], subsets: [...subsets], variable,
			});
			await reload(true);
			openFamily(published().find((item) => item.id === result.id));
			announce('Saved as local font files. The family is available in the Bricks Typography picker.');
		}), 'bvf-primary');

		outlet.replaceChildren(
			row(button('Back to Google Fonts', () => { googleStyle.remove(); showGoogle(); }), text('h2', font.name)),
			section('Preview', prefsBar(drawSample),
				font.weight.length ? field('Preview weight', fieldInput(weight, (value) => { weight = value; drawSample(); },
					{ type: 'range', min: font.weight[0], max: font.weight[1] }))
					: field('Preview weight', select([...new Set(font.cuts.map((cut) => String(Number.parseInt(cut, 10))))].map((v) => [v, v]), String(weight), (value) => { weight = value; url(); })),
				font.cuts.some((cut) => cut.endsWith('i')) ? checkbox('Preview italic', false, (value) => { italic = value; url(); }) : null,
				sample),
			section('Local install',
				font.weight.length ? checkbox('Download as variable font', variable, (value) => { variable = value; }) : null,
				text('h3', 'Styles'), pickGroup(font.cuts, cuts, (cut) => cut.endsWith('i') ? `${Number.parseInt(cut, 10)} Italic` : `${cut} Normal`),
				text('h3', 'Language subsets'), pickGroup(font.subsets, subsets, (subset) => subset),
				canUpload ? save : null,
			),
		);
		url();
	}

	async function base64(blob) {
		const reader = new FileReader();
		return new Promise((resolve, reject) => {
			reader.onload = () => resolve(String(reader.result).split(',')[1]);
			reader.onerror = reject;
			reader.readAsDataURL(blob);
		});
	}

	async function exportFamilies(families) {
		const files = {};
		let total = 0;
		const output = [];
		for (const family of families) {
			const faces = [];
			for (const face of family.faces) {
				const sources = {};
				for (const [format, id] of Object.entries(face.sources)) {
					const key = String(id);
					if (!Object.hasOwn(files, key)) {
						const file = library.files[key];
						if (!file) throw new Error('An attached file is missing. Check the family before exporting.');
						const response = await fetch(file.url);
						if (!response.ok) throw new Error(`Could not export ${file.name}.`);
						const blob = await response.blob();
						total += blob.size;
						if (total > 50 * 1024 * 1024) throw new Error('Export fewer families. The files exceed 50 MB.');
						files[key] = { name: file.name, content: await base64(blob) };
					}
					sources[format] = key;
				}
				faces.push({ ...face, sources });
			}
			output.push({ name: family.name, settings: family.settings, faces });
		}
		const bundle = new Blob([JSON.stringify({ bricksVariableFonts: 1, families: output, files })], { type: 'application/json' });
		const url = URL.createObjectURL(bundle);
		const link = el('a', { href: url, download: 'enhanced-font-manager-for-bricks.json' });
		link.click();
		setTimeout(() => URL.revokeObjectURL(url), 1000);
	}

	function readBundle(packageData) {
		if (!Array.isArray(packageData?.families) || !packageData.files
			|| (packageData.bricksVariableFonts !== 1 && packageData.etchToolkitFonts !== 1)) {
			throw new Error('Choose a Enhanced Font Manager for Bricks or Etch Toolkit font export.');
		}
		const isEtch = packageData.etchToolkitFonts === 1;
		const files = new Map();
		let total = 0;

		function getFile(key, format) {
			if (files.has(key)) return files.get(key);
			if (!Object.hasOwn(packageData.files, key)) throw new Error('A referenced font file is missing from the package.');
			const record = packageData.files[key];
			const name = isEtch ? key : record.name;
			const content = isEtch ? record : record.content;
			if (!/^[^/\\]+\.(woff2?|otf|ttf|eot)$/i.test(name) || !name.toLowerCase().endsWith(`.${format}`) || typeof content !== 'string') {
				throw new Error('The package includes an invalid font file.');
			}
			const raw = atob(content);
			const signatures = { woff2: ['wOF2'], woff: ['wOFF'], otf: ['OTTO'], ttf: ['\x00\x01\x00\x00', 'true', 'ttcf'] };
			const valid = format === 'eot' ? raw.slice(34, 36) === 'LP' : signatures[format]?.includes(raw.slice(0, 4));
			if (!valid) throw new Error(`The bytes for ${name} do not match the font format.`);
			total += raw.length;
			if (total > 50 * 1024 * 1024 || raw.length > Number(root.dataset.max)) {
				throw new Error('The package exceeds the file or bundle limit.');
			}
			const file = new File([Uint8Array.from(raw, (character) => character.charCodeAt(0))], name);
			files.set(key, file);
			return file;
		}

		const names = new Set();
		return packageData.families.map((family) => {
			const name = (family.name || '').trim();
			if (!name || names.has(name.toLowerCase())) throw new Error('Each imported family needs a distinct name.');
			names.add(name.toLowerCase());
			const rows = isEtch
				? family.variants?.map(({ file, weight, style, range }) => {
					const [min, max = min] = String(weight || 400).split(' ');
					return { min, max, style, unicode: range || '', sources: { [file.split('.').pop().toLowerCase()]: file } };
				}) : family.faces;
			if (!Array.isArray(rows) || !rows.length) throw new Error(`The exported family ${name} has no font faces.`);
			const settings = isEtch ? { ...defaults, ...family, variable: family.variable ? `--${family.variable.replace(/^--/, '')}` : '' } : family.settings;
			return {
				...newFamily(name), settings,
				faces: rows.map((face) => {
					const min = Number(face.min);
					const max = Number(face.max);
					if (!(min >= 1 && min <= max && max <= 1000) || !['normal', 'italic', 'oblique'].includes(face.style)
						|| !face.sources || !Object.keys(face.sources).length) {
						throw new Error(`Check the font face descriptors for ${name}.`);
					}
					return {
						...face,
						uploads: Object.fromEntries(Object.entries(face.sources).map(([format, key]) => [format, getFile(key, format)])),
						sources: {},
					};
				}),
			};
		});
	}

	function showTransfer() {
		const picked = new Set(published().map((family) => family.id));
		const choices = el('div', { className: 'bvf-choices' },
			...published().map((family) => checkbox(family.name, true, (value) => value ? picked.add(family.id) : picked.delete(family.id))),
		);
		const output = section('Export families', choices, button('Download selected families', () => work('Preparing export…', async () => {
			if (!picked.size) throw new Error('Select at least one family.');
			await exportFamilies(published().filter((family) => picked.has(family.id)));
			announce('Font package downloaded.');
		})));
		if (!canUpload) {
			outlet.replaceChildren(output);
			return;
		}
		const input = el('input', { type: 'file', accept: '.json,application/json' });
		const review = el('div');
		input.onchange = () => work('Reading font package…', async () => {
			review.replaceChildren();
			const file = input.files[0];
			if (!file) return;
			if (file.size > 69 * 1024 * 1024) throw new Error('The package is too large.');
			const families = readBundle(JSON.parse(await file.text()));
			const conflicts = () => new Map(published().map((family) => [family.name.toLowerCase(), family]));
			const replacing = el('input', { type: 'checkbox' });
			review.append(
				el('ul', {}, ...families.map((family) => text('li', family.name + (conflicts().has(family.name.toLowerCase()) ? ' · already exists' : '')))),
				el('label', { className: 'bvf-check' }, replacing, 'Replace matching families'),
				button('Import reviewed families', () => work('Importing font families…', async () => {
					requireQuietBuilder();
					await reload();
					const known = conflicts();
					const imported = [];
					try {
						for (const family of families) {
							const match = known.get(family.name.toLowerCase());
							if (match && !replacing.checked) continue;
							if (match) Object.assign(family, { id: match.id, revision: match.revision });
							await saveFamily(family);
							imported.push(family.name);
						}
					} catch (error) {
						throw new Error(`${imported.length ? `Imported ${imported.join(', ')}. ` : ''}${error.message}`);
					} finally {
						await reload(imported.length > 0);
					}
					render();
					announce(imported.length ? `Imported ${imported.length} font families.` : 'All matching families were skipped.');
				}), 'bvf-primary'),
			);
			announce('Review the families before importing.');
		});
		outlet.replaceChildren(output, section('Import families', field('Bricks or Etch Toolkit font package', input), review));
	}

	function showSettings() {
		const privacy = library.manageSettings ? section('Privacy',
			checkbox('Block Google Fonts stylesheets on the frontend', library.blockGoogle, (value) =>
				work('Saving site privacy settings…', async () => {
					await request('settings', { blockGoogle: value });
					await reload();
					render();
					announce('Privacy settings saved.');
				})),
			text('p', 'Blocks Google Fonts links and connection hints added by WordPress and Bricks. Local fonts and previews in the builder still work.', 'bvf-muted'),
		) : null;
		outlet.replaceChildren(section('Stylesheet output',
			cssField('Exact Bricks-generated CSS', library.css),
			cssField('CSS with font manager settings', library.served),
		), ...(privacy ? [privacy] : []));
	}

	nav.addEventListener('click', (event) => {
		const view = event.target.closest('[data-view]')?.dataset.view;
		if (view) go(view);
	});

	document.head.append(fontStyle);
	function load() {
		return work('Loading font library…', async () => {
			await reload();
			render();
			announce();
		});
	}

	if (inBuilder) {
		const managerName = document.getElementById(root.getAttribute('aria-labelledby')).textContent;
		const svgNamespace = 'http://www.w3.org/2000/svg';
		const glyph = document.createElementNS(svgNamespace, 'svg');
		glyph.setAttribute('class', 'bvf-toolbar-glyph');
		glyph.setAttribute('viewBox', '0 0 640 640');
		glyph.setAttribute('aria-hidden', 'true');
		const artwork = document.createElementNS(svgNamespace, 'use');
		artwork.setAttribute('href', new URL('./circle-font-case.svg#glyph', import.meta.url).href);
		glyph.append(artwork);
		const launch = el('button', {
			id: 'bvf-launcher',
			type: 'button',
			className: 'toolbar-control',
			onclick: () => {
				if (!root.open) root.showModal();
				launch.setAttribute('aria-expanded', 'true');
				load();
			},
		}, glyph);
		launch.setAttribute('aria-label', managerName);
		launch.setAttribute('aria-haspopup', 'dialog');
		launch.setAttribute('aria-controls', root.id);
		launch.setAttribute('aria-expanded', 'false');

		const item = el('li', { className: 'bvf-launcher-slot' }, launch);
		item.dataset.balloon = 'Enhanced Font Mgr';
		function mount() {
			const toolbar = document.querySelector('.bricks-toolbar');
			const group = toolbar?.querySelector('.group-wrapper.start:not(.is-hidden)')
				|| toolbar?.querySelector('.group-wrapper:not(.is-hidden)');
			if (!group || item.parentElement === group) return;

			const position = toolbar.classList;
			item.dataset.balloonPos = position.contains('toolbar-left') ? 'right'
				: position.contains('toolbar-right') ? 'left'
				: position.contains('toolbar-bottom') ? 'up-left' : 'bottom-left';
			const manage = group.querySelector(':scope > li.settings');
			if (manage) {
				item.style.order = String(Number(manage.style.order || 0) + 1);
				manage.after(item);
			} else {
				item.style.order = '';
				group.append(item);
			}
		}
		mount();
		updateNativeFontCardPreviews();
		const observer = new MutationObserver(() => {
			if (refreshTimer) return;
			refreshTimer = requestAnimationFrame(() => {
				refreshTimer = null;
				mount();
				updateNativeFontCardPreviews();
			});
		});
		observer.observe(document.body, { childList: true, subtree: true });
		root.addEventListener('close', () => {
			launch.setAttribute('aria-expanded', 'false');
			googleStyle.remove();
			activeFonts.forEach((font) => document.fonts.delete(font));
			activeFonts = [];
		});
	} else {
		load();
	}
}
