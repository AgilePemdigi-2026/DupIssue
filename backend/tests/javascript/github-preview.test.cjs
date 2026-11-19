'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(
    path.resolve(__dirname, '../../public/js/github-preview.js'),
    'utf8'
);

class FakeElement {
    constructor(tag = 'div') {
        this.tagName = tag;
        this.value = '';
        this.disabled = false;
        this.textContent = '';
        this.innerHTML = 'Original button';
        this.className = '';
        this.dataset = {};
        this.children = [];
        this.attributes = {};
        this.listeners = new Map();
        const classes = new Set();
        this.classList = {
            add: name => classes.add(name),
            remove: name => classes.delete(name),
            contains: name => classes.has(name),
        };
    }

    addEventListener(type, callback) {
        this.listeners.set(type, callback);
    }

    trigger(type, detail = {}) {
        const callback = this.listeners.get(type);
        assert.ok(callback, `Missing ${type} listener`);
        return callback({target: this, detail, preventDefault() {}});
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    querySelector(selector) {
        if (selector === 'input[name="_token"]') {
            return {value: 'test-csrf-token'};
        }
        return null;
    }

    appendChild(child) {
        if (child.tagName === '#fragment') {
            this.children.push(...child.children);
        } else {
            this.children.push(child);
        }
        return child;
    }

    replaceChildren(...children) {
        this.children = [];
        for (const child of children) this.appendChild(child);
    }
}

function setup(connectedRepo = 'demo/bugs') {
    const ids = [
        'github-panel', 'github-connection-form', 'github-repo',
        'github-preview-btn', 'github-import-btn',
        'github-preview-section', 'github-feedback',
        'github-preview-rows', 'github-total-count',
        'github-valid-count', 'github-invalid-count',
        'github-imported-count',
    ];
    const nodes = new Map(ids.map(id => [id, new FakeElement()]));
    const panel = nodes.get('github-panel');
    panel.dataset.connectedRepo = connectedRepo;
    panel.dataset.previewUrl = '/github/preview';
    panel.dataset.importUrl = '/github/import';
    nodes.get('github-repo').value = 'demo/bugs';

    const requests = [];
    const fetch = (url, options) => new Promise((resolve, reject) => {
        requests.push({url, options, resolve, reject});
    });

    let confirmation = true;
    let confirmationCalls = 0;
    const window = {
        confirm: () => {
            confirmationCalls++;
            return confirmation;
        },
    };
    const document = {
        readyState: 'complete',
        getElementById: id => nodes.get(id) || null,
        createElement: tag => new FakeElement(tag),
        createDocumentFragment: () => new FakeElement('#fragment'),
    };
    vm.runInNewContext(script, {
        document, window, fetch, URL, console,
    }, {filename: 'github-preview.js'});

    return {
        nodes, requests,
        setConfirmation: value => {confirmation = value;},
        get confirmationCalls() {return confirmationCalls;},
    };
}

function makePreview(valid = 1, invalid = 1, imported = 1) {
    const rows = [];
    for (const [status, count] of [
        ['VALID', valid], ['INVALID', invalid],
        ['ALREADY_IMPORTED', imported],
    ]) {
        for (let i = 0; i < count; i++) {
            const number = 100 + rows.length;
            rows.push({
                record_number: rows.length + 1,
                status,
                data: {
                    github_number: number,
                    title: 'Test issue',
                    source_url: `https://github.com/demo/bugs/issues/${number}`,
                },
            });
        }
    }
    return {
        rows,
        total_rows: rows.length,
        valid_count: valid,
        invalid_count: invalid,
        already_imported_count: imported,
    };
}

function reply(request, data, status = 200) {
    request.resolve({
        ok: status >= 200 && status < 300,
        status,
        json: async () => data,
    });
}

async function settle() {
    for (let i = 0; i < 12; i++) await Promise.resolve();
}

async function loadPreview(fixture, preview = makePreview()) {
    fixture.nodes.get('github-preview-btn').trigger('click');
    const request = fixture.requests.at(-1);
    reply(request, {success: true, preview});
    await settle();
}

test('preview button requires the repository matching the saved connection', () => {
    const fixture = setup('');
    const repo = fixture.nodes.get('github-repo');
    const panel = fixture.nodes.get('github-panel');
    const previewButton = fixture.nodes.get('github-preview-btn');
    const importButton = fixture.nodes.get('github-import-btn');

    assert.equal(previewButton.disabled, true);
    assert.equal(importButton.disabled, true);
    panel.trigger('github:connection-saved', {repo: 'demo/bugs'});
    assert.equal(previewButton.disabled, false);
    assert.equal(importButton.disabled, true);

    repo.value = 'demo/other';
    repo.trigger('input');
    assert.equal(previewButton.disabled, true);

    repo.value = 'DEMO/BUGS';
    repo.trigger('input');
    assert.equal(previewButton.disabled, false);
});

test('preview renders VALID, INVALID and ALREADY_IMPORTED counts', async () => {
    const fixture = setup();
    const previewButton = fixture.nodes.get('github-preview-btn');
    const importButton = fixture.nodes.get('github-import-btn');
    const section = fixture.nodes.get('github-preview-section');
    const feedback = fixture.nodes.get('github-feedback');

    previewButton.trigger('click');
    assert.equal(previewButton.disabled, true);
    assert.equal(importButton.disabled, true);
    assert.equal(fixture.requests.length, 1);
    assert.equal(fixture.requests[0].url, '/github/preview');
    assert.equal(fixture.requests[0].options.method, 'POST');
    assert.equal(
        fixture.requests[0].options.headers['X-CSRF-TOKEN'],
        'test-csrf-token'
    );
    reply(fixture.requests[0], {success: true, preview: makePreview()});
    await settle();

    assert.equal(fixture.nodes.get('github-total-count').textContent, '3');
    assert.equal(fixture.nodes.get('github-valid-count').textContent, '1');
    assert.equal(fixture.nodes.get('github-invalid-count').textContent, '1');
    assert.equal(fixture.nodes.get('github-imported-count').textContent, '1');
    const rows = fixture.nodes.get('github-preview-rows').children;
    assert.equal(rows.length, 3);
    assert.deepEqual(
        rows.map(row => row.children[2].children[0].textContent),
        ['VALID', 'INVALID', 'ALREADY_IMPORTED']
    );
    assert.equal(section.classList.contains('d-none'), false);
    assert.equal(previewButton.disabled, false);
    assert.equal(importButton.disabled, false);
    assert.match(feedback.className, /alert-success/);
});

test('preview with zero valid issues cannot be imported', async () => {
    const fixture = setup();
    await loadPreview(fixture, makePreview(0, 1, 1));
    assert.equal(fixture.nodes.get('github-preview-btn').disabled, false);
    assert.equal(fixture.nodes.get('github-import-btn').disabled, true);
    assert.match(fixture.nodes.get('github-feedback').className, /alert-warning/);
});

test('cancelled import never sends an import request', async () => {
    const fixture = setup();
    await loadPreview(fixture);
    fixture.setConfirmation(false);
    fixture.nodes.get('github-import-btn').trigger('click');
    assert.equal(fixture.confirmationCalls, 1);
    assert.equal(fixture.requests.length, 1);
    assert.equal(fixture.nodes.get('github-import-btn').disabled, false);
});

test('successful import consumes preview and prevents reuse', async () => {
    const fixture = setup();
    await loadPreview(fixture);
    fixture.nodes.get('github-import-btn').trigger('click');
    assert.equal(fixture.requests.length, 2);
    assert.equal(fixture.requests[1].url, '/github/import');
    assert.equal(JSON.parse(fixture.requests[1].options.body).confirmed, true);
    assert.equal(fixture.nodes.get('github-preview-btn').disabled, true);
    reply(fixture.requests[1], {success: true, result: {imported_count: 1}});
    await settle();

    assert.equal(fixture.nodes.get('github-import-btn').disabled, true);
    assert.equal(fixture.nodes.get('github-preview-rows').children.length, 0);
    assert.equal(fixture.nodes.get('github-valid-count').textContent, '0');
    assert.match(fixture.nodes.get('github-feedback').textContent, /Impor berhasil/);
    fixture.nodes.get('github-import-btn').trigger('click');
    assert.equal(fixture.requests.length, 2);
    assert.equal(fixture.nodes.get('github-preview-btn').disabled, false);
});

for (const status of [409, 422]) {
    test(`import HTTP ${status} clears stale preview and permits retry`, async () => {
        const fixture = setup();
        await loadPreview(fixture);
        fixture.nodes.get('github-import-btn').trigger('click');
        reply(fixture.requests[1], {
            success: false,
            message: 'Preview kedaluwarsa atau data berubah.',
        }, status);
        await settle();

        assert.equal(fixture.nodes.get('github-import-btn').disabled, true);
        assert.equal(fixture.nodes.get('github-preview-rows').children.length, 0);
        assert.equal(fixture.nodes.get('github-preview-btn').disabled, false);
        assert.match(fixture.nodes.get('github-feedback').className, /alert-danger/);
        await loadPreview(fixture);
        assert.equal(fixture.nodes.get('github-import-btn').disabled, false);
        assert.equal(fixture.requests.length, 3);
    });
}

test('failed GitHub preview clears old results and allows retry', async () => {
    const fixture = setup();
    await loadPreview(fixture);
    fixture.nodes.get('github-preview-btn').trigger('click');
    assert.equal(fixture.nodes.get('github-import-btn').disabled, true);
    reply(fixture.requests[1], {
        success: false,
        message: 'GitHub API sementara tidak tersedia.',
    }, 503);
    await settle();

    assert.equal(fixture.nodes.get('github-preview-rows').children.length, 0);
    assert.equal(fixture.nodes.get('github-preview-btn').disabled, false);
    assert.equal(fixture.nodes.get('github-import-btn').disabled, true);
    assert.match(fixture.nodes.get('github-feedback').textContent, /tidak tersedia/);
    assert.match(fixture.nodes.get('github-feedback').className, /alert-danger/);
    await loadPreview(fixture);
    assert.equal(fixture.requests.length, 3);
    assert.equal(fixture.nodes.get('github-import-btn').disabled, false);
});
