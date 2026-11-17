const maxDecoded = 32 * 1024 * 1024;
const tagAt = (view, offset) => String.fromCharCode(...Array.from({ length: 4 }, (_, i) => view.getUint8(offset + i)));
const validWeight = (weight) => weight >= 1 && weight <= 1000;

export function startCodec(url) {
	const worker = new Worker(url);
	let pending;
	let id = 0;
	const finish = (error, buffer) => {
		if (!pending) return;
		clearTimeout(pending.timer);
		const { resolve, reject } = pending;
		pending = null;
		if (error) reject(new Error(error));
		else resolve(buffer);
	};
	worker.onmessage = ({ data }) => {
		if (data.type === 'ready' || (data.id !== undefined && data.id !== id)) return;
		finish(data.type === 'error' ? data.error : null, data.buffer);
	};
	worker.onerror = () => finish('Could not start the font codec.');
	return {
		run(type, bytes) {
			return new Promise((resolve, reject) => {
				id++;
				const timer = setTimeout(() => {
					worker.terminate();
					finish('The font codec timed out.');
				}, 12000);
				pending = { resolve, reject, timer };
				const buffer = bytes.slice(0);
				worker.postMessage({ id, type, buffer }, [buffer]);
			});
		},
		close() {
			worker.terminate();
		},
	};
}

async function inflate(source, target) {
	const reader = new Blob([source]).stream().pipeThrough(new DecompressionStream('deflate')).getReader();
	let offset = 0;
	for (;;) {
		const { done, value } = await reader.read();
		if (done) break;
		if (offset + value.length > target.length) {
			await reader.cancel();
			throw new Error('Invalid WOFF table.');
		}
		target.set(value, offset);
		offset += value.length;
	}
	if (offset !== target.length) throw new Error('Incomplete WOFF table.');
}

// Rebuild the OpenType directory from WOFF's individually compressed tables.
async function unwrapWoff(bytes) {
	const source = new DataView(bytes);
	const count = source.getUint16(12);
	const length = source.getUint32(16);
	if (source.getUint32(8) !== bytes.byteLength || !count || count > 4095
		|| 44 + count * 20 > bytes.byteLength || length > maxDecoded || length < 12 + count * 16) {
		throw new Error('Invalid WOFF directory.');
	}

	const output = new DataView(new ArrayBuffer(length));
	const exponent = Math.floor(Math.log2(count));
	const searchRange = 16 * 2 ** exponent;
	output.setUint32(0, source.getUint32(4));
	output.setUint16(4, count);
	output.setUint16(6, searchRange);
	output.setUint16(8, exponent);
	output.setUint16(10, 16 * count - searchRange);

	let offset = 12 + 16 * count;
	for (let i = 0; i < count; i++) {
		const entry = 44 + 20 * i;
		const position = source.getUint32(entry + 4);
		const stored = source.getUint32(entry + 8);
		const size = source.getUint32(entry + 12);
		if (position < 44 + count * 20 || stored > size || position + stored > bytes.byteLength || offset + size > length) {
			throw new Error('Invalid WOFF table.');
		}
		const target = new Uint8Array(output.buffer, offset, size);
		const data = new Uint8Array(bytes, position, stored);
		if (size === stored) target.set(data);
		else await inflate(data, target);
		const record = 12 + 16 * i;
		output.setUint32(record, source.getUint32(entry));
		output.setUint32(record + 4, source.getUint32(entry + 16));
		output.setUint32(record + 8, offset);
		output.setUint32(record + 12, size);
		offset += Math.ceil(size / 4) * 4;
	}
	if (offset !== length) throw new Error('Invalid WOFF output size.');
	return output.buffer;
}

function readName(table, ids) {
	if (!table) return '';
	const count = table.getUint16(2);
	const storage = table.getUint16(4);
	const names = [];
	for (let i = 0; i < count; i++) {
		const record = 6 + 12 * i;
		const platform = table.getUint16(record);
		const language = table.getUint16(record + 4);
		const id = table.getUint16(record + 6);
		if (!ids.includes(id) || ![0, 3].includes(platform)) continue;
		const size = table.getUint16(record + 8);
		const start = storage + table.getUint16(record + 10);
		if (start + size > table.byteLength) continue;
		const text = new TextDecoder('utf-16be').decode(new Uint8Array(table.buffer, table.byteOffset + start, size)).trim();
		if (text) names.push({ id, language, text });
	}
	for (const id of ids) {
		const name = names.find((entry) => entry.id === id && entry.language === 0x0409)
			|| names.find((entry) => entry.id === id);
		if (name) return name.text;
	}
	return '';
}

function readMetadata(bytes) {
	const font = new DataView(bytes);
	const tables = new Map();
	const count = font.getUint16(4);
	for (let i = 0; i < count; i++) {
		const record = 12 + 16 * i;
		const tag = tagAt(font, record);
		const offset = font.getUint32(record + 8);
		const length = font.getUint32(record + 12);
		tables.set(tag, new DataView(bytes, offset, length));
	}
	const axes = [];
	const fvar = tables.get('fvar');
	if (fvar) {
		const start = fvar.getUint16(4);
		const count = fvar.getUint16(8);
		const size = fvar.getUint16(10);
		if (size < 20 || start + count * size > fvar.byteLength) throw new Error('Invalid variation table.');
		for (let i = 0; i < count; i++) {
			const offset = start + i * size;
			const tag = tagAt(fvar, offset);
			const [min, initial, max] = [4, 8, 12].map((delta) => fvar.getInt32(offset + delta) / 65536);
			if (/^[A-Za-z0-9 ]{4}$/.test(tag) && min < max && min <= initial && initial <= max) {
				const name = readName(tables.get('name'), [fvar.getUint16(offset + 18)]);
				axes.push({ tag, name: name || tag, min, initial, max });
			}
		}
	}

	const os = tables.get('OS/2');
	const head = tables.get('head');
	const weight = os?.byteLength >= 6 ? os.getUint16(4) : 400;
	const selection = os?.byteLength >= 64 ? os.getUint16(62) : 0;
	const italic = (selection & 1) || (head?.byteLength >= 46 && (head.getUint16(44) & 2));
	const names = tables.get('name');
	const subfamily = readName(names, [17, 2]);
	return {
		family: readName(names, [16, 1]),
		style: (selection & 512) || /oblique/i.test(subfamily) ? 'oblique' : italic || /italic/i.test(subfamily) ? 'italic' : 'normal',
		weight: validWeight(weight) ? weight : 400,
		axes,
	};
}

export async function prepareFont(file, codec, autoConvert = true) {
	const bytes = await file.arrayBuffer();
	const header = new DataView(bytes);
	const signature = header.getUint32(0);
	const ext = file.name.split('.').pop().toLowerCase();
	const validSignatures = { woff2: 0x774f4632, woff: 0x774f4646, otf: 0x4f54544f, ttf: 0x00010000 };
	if (signature !== validSignatures[ext] && !(ext === 'ttf' && signature === 0x74727565)) throw new Error('The font file does not match its extension.');

	let sfnt;
	let metadata;
	let note;
	try {
		if (ext === 'woff2') {
			if (header.getUint32(16) > maxDecoded) throw new Error('Font is too large to inspect.');
			sfnt = await codec?.run('decode', bytes);
		} else {
			sfnt = ext === 'woff' ? await unwrapWoff(bytes) : bytes;
		}
		if (sfnt) metadata = readMetadata(sfnt);
	} catch {
		// A file the browser can render may still need manually entered descriptors.
	}
	if (!metadata) note = 'Metadata unavailable. Confirm the weight and style manually.';

	let upload = file;
	let previewBytes = bytes;
	if (autoConvert && ext !== 'woff2' && sfnt && codec) {
		try {
			const result = await codec.run('convert', sfnt);
			if (result.byteLength < 48 || new DataView(result).getUint32(0) !== validSignatures.woff2) {
				throw new Error('Invalid WOFF2 output.');
			}
			upload = new File([result], file.name.replace(/\.[^.]+$/, '.woff2'), { type: 'font/woff2' });
			previewBytes = result;
			note = [`Converted from ${ext.toUpperCase()} to WOFF2.`, note].filter(Boolean).join(' ');
		} catch {
			note = [`Kept original ${ext.toUpperCase()}.`, note].filter(Boolean).join(' ');
		}
	} else if (autoConvert && ext !== 'woff2') {
		note = [`Kept original ${ext.toUpperCase()}.`, note].filter(Boolean).join(' ');
	}

	const weight = metadata?.axes.find(({ tag }) => tag === 'wght');
	const range = weight && validWeight(weight.min) && validWeight(weight.max)
		? [weight.min, weight.max] : [metadata?.weight ?? 400, metadata?.weight ?? 400];
	return {
		upload,
		original: file.name,
		previewBytes,
		family: metadata?.family || '',
		style: metadata?.style ?? 'normal',
		axes: metadata?.axes ?? [],
		min: String(Number(range[0].toFixed(5))),
		max: String(Number(range[1].toFixed(5))),
		note: note || '',
	};
}
