/* Read-only, same-origin registry views. Only selection performs consumer inspection. */
(function () {
    'use strict';
    const root = document.getElementById('diviops-design-system');
    if (!root || !window.diviopsDesignSystem) return;
    const config = window.diviopsDesignSystem;
    const find = selector => root.querySelector(selector);
    const detail = find('[data-detail]');
    const list = find('[data-list]');
    const status = find('[data-status]');
    const notices = find('[data-notices]');
    const stores = {
        presets: { rows: [], at: 0, state: 'idle', epoch: 0 },
        variables: { rows: [], at: 0, state: 'idle', epoch: 0 }
    };
    let view = 'presets', selected = null, selectionEpoch = 0, limit = 50, filteredCount = 0;
    const text = value => typeof value === 'string' ? value : JSON.stringify(value, null, 2) ?? '';
    function el(tag, value, className) {
        const node = document.createElement(tag);
        if (value !== undefined) node.textContent = text(value);
        if (className) node.className = className;
        return node;
    }
    function message(target, value) { target.replaceChildren(el('p', value)); }
    function button(label, action, className = 'button') {
        const node = el('button', label, className);
        node.type = 'button';
        node.addEventListener('click', action);
        return node;
    }
    function raw(target, title, value) {
        const disclosure = el('details');
        disclosure.append(el('summary', title), el('pre', value == null ? 'Not recorded' : text(value)));
        target.append(disclosure);
    }
    function facts(target, entries) {
        const dl = el('dl', undefined, 'diviops-facts');
        entries.forEach(([label, value]) => {
            const row = el('div');
            row.append(el('dt', label), el('dd', value == null || value === '' ? 'Not recorded' : value));
            dl.append(row);
        });
        target.append(dl);
    }
    function swatch(value) {
        // No URLs, variables, gradients, external fonts or arbitrary CSS are applied.
        if (typeof value !== 'string' || !/^(#[a-f\d]{3,4}|#[a-f\d]{6}|#[a-f\d]{8}|(?:rgb|hsl)a?\([\d\s.,%+\-/deg]+\))$/i.test(value) || !CSS.supports('color', value)) return null;
        const node = el('span', undefined, 'diviops-ds-swatch');
        node.style.backgroundColor = value;
        node.setAttribute('aria-hidden', 'true');
        return node;
    }
    function variableValue(target, item) {
        const line = el('div', undefined, 'diviops-ds-value');
        const chip = item.type === 'colors' ? swatch(item.value) : null;
        if (chip) line.append(chip);
        line.append(el('code', text(item.value)));
        target.append(line);
    }
    async function request(path) {
        const url = new URL(config.root + path, location.href);
        if (url.origin !== location.origin) throw new Error('REST endpoint must be same-origin.');
        const response = await fetch(url.href, { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json' } });
        let body;
        try { body = await response.json(); } catch (_) { throw new Error('Invalid server response.'); }
        if (!response.ok || body.ok !== true) throw new Error(body.error?.message || body.message || 'Request failed (' + response.status + ').');
        if (!body.data || typeof body.data !== 'object') throw new Error('Missing response data.');
        return body;
    }
    function freshness(store) {
        if (!store.at) return 'Not loaded.';
        const age = Date.now() - store.at;
        return (age >= 300000 || store.state === 'error' ? 'Stale snapshot. ' : 'Snapshot. ') + 'Fetched ' + new Date(store.at).toLocaleTimeString() + '.';
    }
    function clearSelection() {
        selectionEpoch++;
        selected = null;
        detail.setAttribute('aria-busy', 'false');
        message(detail, 'No entry selected.');
    }
    function listCoordinates(row) {
        if (view !== 'presets') return row;
        const meta = stores.presets.meta;
        const source = meta?.entry_sources?.[row.id];
        // Legacy metadata may shadow D5 IDs. Collision warnings make fallback ambiguous.
        const usable = source && ['d5_top_level', 'd5_nested_scratchpad'].includes(source.provenance)
            && ['module', 'group'].includes(source.bucket) && typeof source.bucket_key === 'string' && source.bucket_key !== ''
            && !(meta.warnings || []).some(warning => warning.id === row.id && ['id_collision', 'shape_inconsistency'].includes(warning.type));
        return {
            type: row.type ?? (usable ? source.bucket : undefined),
            moduleName: row.moduleName ?? (usable && source.bucket === 'module' ? source.bucket_key : undefined),
            groupName: row.groupName ?? (usable && source.bucket === 'group' ? source.bucket_key : undefined)
        };
    }
    function renderStatus() {
        const store = stores[view];
        status.textContent = (store.state === 'loading' ? 'Loading ' + view + '... ' : filteredCount + ' ' + view + '. ') + freshness(store);
    }
    function renderList() {
        const store = stores[view];
        const query = find('[data-search]').value.toLocaleLowerCase().trim();
        const rows = store.rows.filter(row => {
            const coordinates = listCoordinates(row);
            return [row.id, row.name, row.label, coordinates.type, coordinates.moduleName, coordinates.groupName].some(value => text(value).toLocaleLowerCase().includes(query));
        });
        filteredCount = rows.length;
        list.replaceChildren();
        rows.slice(0, limit).forEach(row => {
            const item = el('li');
            const control = button('', () => select(row), 'diviops-ds-entry');
            control.setAttribute('aria-pressed', String(selected === row.id));
            const chip = row.type === 'colors' ? swatch(row.value) : null;
            if (chip) control.append(chip);
            const label = el('span');
            const coordinates = listCoordinates(row);
            label.append(el('strong', row.name || row.label || row.id), el('small', [coordinates.type, coordinates.moduleName || coordinates.groupName].filter(Boolean).join(' / ') || 'Coordinates unavailable'), el('code', row.id));
            control.append(label);
            item.append(control);
            list.append(item);
        });
        if (!rows.length && store.state !== 'loading') list.append(el('li', store.at ? (query ? 'No matching entries.' : 'No entries in this registry.') : 'Registry unavailable.'));
        renderStatus();
        list.setAttribute('aria-busy', String(store.state === 'loading'));
        find('[data-more]').hidden = rows.length <= limit;
        notices.replaceChildren();
        if (store.error) notices.append(el('p', store.error + ' Refresh to retry.', 'diviops-callout diviops-callout--error'));
        if (store.meta?.warnings?.length) raw(notices, 'Storage notices (' + store.meta.warnings.length + ')', store.meta.warnings);
        if (store.at) raw(notices, 'Registry source / coverage', { ...store.meta, coverage: 'Registry snapshot only. No consumer scan performed for this list.' });
    }
    async function load(kind, force = false) {
        const store = stores[kind];
        if (!force && store.promise) return store.promise;
        if (!force && store.at) return;
        const epoch = ++store.epoch;
        store.state = 'loading';
        store.error = '';
        if (kind === view) renderList();
        store.promise = (async () => {
            try {
                const body = await request(kind === 'presets' ? 'preset/audit-storage' : 'variable/list');
                if (epoch !== store.epoch) return;
                if (kind === 'presets') {
                    if (!body.data.aggregated || typeof body.data.aggregated !== 'object') throw new Error('Missing preset registry.');
                    store.rows = Object.entries(body.data.aggregated).map(([id, entry]) => ({ ...entry, id }));
                } else {
                    if (!Array.isArray(body.data.variables)) throw new Error('Missing variable registry.');
                    store.rows = body.data.variables;
                }
                store.meta = body._meta || {};
                store.at = Date.now();
                store.state = 'ready';
            } catch (error) {
                if (epoch !== store.epoch) return;
                store.state = 'error';
                store.error = error.message;
            } finally {
                if (epoch === store.epoch) {
                    store.promise = null;
                    if (kind === view) renderList();
                }
            }
        })();
        return store.promise;
    }
    function renderVariable(item) {
        detail.append(el('h3', item.label || item.id));
        const nativeColor = /^gcid-(primary|secondary|heading|body|link)-color$/.test(item.id) && item.type === 'colors';
        facts(detail, [['ID', item.id], ['Type', item.type], ['Provenance', nativeColor ? 'Native WordPress / Divi customizer color' : 'Stored variable; author provenance not recorded'], ['Status', item.status], ['Last updated (stored)', item.lastUpdated], ['Registry freshness', freshness(stores.variables)]]);
        variableValue(detail, item);
        detail.append(el('p', 'Variable usage was not scanned. This value is stored data, not a computed result.', 'diviops-muted'));
        raw(detail, 'Registry provenance', stores.variables.meta);
    }
    function renderPreset(data) {
        detail.append(el('h3', data.name || data.preset_id));
        const coordinates = data.coordinates || {};
        facts(detail, [['ID', data.preset_id], ['Type / bucket', coordinates.type || coordinates.bucket], ['Module', coordinates.module_name], ['Group / slot', [coordinates.group_name, coordinates.group_id].filter(Boolean).join(' / ')], ['Bucket key', coordinates.bucket_key], ['Bucket default', coordinates.is_default === true ? 'Yes' : coordinates.is_default === false ? 'No' : 'Unknown'], ['Fetched', new Date().toLocaleTimeString()]]);
        raw(detail, 'Storage provenance', data.storage);
        detail.append(el('h4', 'Referenced variables'));
        const refs = data.variable_references;
        if (!refs || !Array.isArray(refs.ids)) messageAppend('Variable reference coverage unavailable.');
        else {
            messageAppend(refs.coverage);
            messageAppend('Variable registry: ' + freshness(stores.variables));
            if (stores.variables.error) messageAppend('Variable lookup unavailable: ' + stores.variables.error);
            if (!refs.ids.length) messageAppend('No direct variable IDs found within this coverage.');
            refs.ids.forEach(id => {
                const matches = stores.variables.rows.filter(variable => variable.id === id);
                detail.append(el('code', id));
                if (!matches.length) messageAppend('Unresolved in the current variable-list snapshot.');
                matches.forEach(item => {
                    detail.append(button(item.label || item.id, () => { switchView('variables'); select(item); }));
                    variableValue(detail, item);
                });
            });
        }
        detail.append(el('h4', 'Known consumers'));
        const refsData = data.references || {};
        const coverage = data.coverage;
        messageAppend(coverage?.block_scan === 'complete_within_scope' ? String(refsData.total ?? 'Unknown') + ' explicit references found within partial coverage.' : 'Consumer coverage unavailable or incomplete; counts are not conclusive.');
        facts(detail, [['Block references (covered scope)', coverage?.block_scan === 'complete_within_scope' ? refsData.block_ref_count : 'Unavailable'], ['Preset-chain references (covered scope)', coverage ? refsData.preset_ref_count : 'Unavailable']]);
        messageAppend('Zero references never means safe to delete.');
        raw(detail, 'Scan coverage', coverage || 'Unavailable');
        raw(detail, 'Sample consumers (up to 10)', refsData.sample_consumers || []);
        if (data.warnings?.length) raw(detail, 'Inspection notices (' + data.warnings.length + ')', data.warnings);
        detail.append(el('h4', 'Stored definitions'));
        ['attrs', 'styleAttrs', 'renderAttrs'].forEach(bag => raw(detail, bag, data[bag]));
        function messageAppend(value) { detail.append(el('p', value, 'diviops-muted')); }
    }
    async function select(item) {
        const epoch = ++selectionEpoch;
        const kind = view;
        selected = item.id;
        renderList();
        detail.replaceChildren();
        if (kind === 'variables') { renderVariable(item); detail.focus(); return; }
        message(detail, 'Loading preset ' + item.id + '...');
        detail.setAttribute('aria-busy', 'true');
        detail.focus();
        try {
            const body = await request('preset/inspect/' + encodeURIComponent(item.id));
            if (epoch !== selectionEpoch) return;
            if (body.data.preset_id !== item.id) throw new Error('Preset identity mismatch; response not displayed.');
            await load('variables');
            if (epoch !== selectionEpoch || view !== kind) return;
            detail.replaceChildren();
            renderPreset(body.data);
            detail.append(button('Refresh selected preset', () => select(item)));
        } catch (error) {
            if (epoch !== selectionEpoch) return;
            message(detail, 'Could not inspect ' + item.id + ': ' + error.message);
            detail.append(button('Retry inspection', () => select(item)));
        } finally {
            if (epoch === selectionEpoch) detail.setAttribute('aria-busy', 'false');
        }
    }
    function switchView(next) {
        view = next;
        limit = 50;
        find('[data-search]').value = '';
        clearSelection();
        root.querySelectorAll('[data-view]').forEach(node => node.setAttribute('aria-pressed', String(node.dataset.view === view)));
        renderList();
        load(view);
    }
    root.querySelectorAll('[data-view]').forEach(node => node.addEventListener('click', () => switchView(node.dataset.view)));
    find('[data-search]').addEventListener('input', () => { limit = 50; renderList(); });
    find('[data-refresh]').addEventListener('click', () => { clearSelection(); load(view, true); });
    find('[data-more]').addEventListener('click', () => { limit += 50; renderList(); });
    window.addEventListener('focus', renderStatus);
    load(view);
})();
