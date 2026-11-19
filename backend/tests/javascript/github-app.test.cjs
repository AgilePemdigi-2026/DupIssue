'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(
    path.resolve(__dirname, '../../public/js/github-app.js'),
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
        this.token = 'test-csrf-token';

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

    trigger(type) {
        const callback = this.listeners.get(type);
        assert.ok(callback, `Missing ${type} listener`);

        return callback({
            target: this,
            preventDefault() {},
        });
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    querySelector(selector) {
        if (selector === 'input[name="_token"]') {
            return {value: this.token};
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

function setup({csrf = 'test-csrf-token'} = {}) {
    const ids = [
        'github-app-operations',
        'github-app-operations-form',
        'github-app-preview-btn',
        'github-app-import-btn',
        'github-app-preview-section',
        'github-app-feedback',
        'github-app-preview-rows',
        'github-app-total-count',
        'github-app-valid-count',
        'github-app-invalid-count',
        'github-app-imported-count',
    ];

    const nodes = new Map(
        ids.map(id => [id, new FakeElement()])
    );

    const operations = nodes.get('github-app-operations');
    operations.dataset.previewUrl = '/app/preview';
    operations.dataset.importUrl = '/app/import';

    nodes.get('github-app-operations-form').token = csrf;
    nodes.get('github-app-preview-section')
        .classList.add('d-none');

    const requests = [];

    const fetch = (url, options) =>
        new Promise((resolve, reject) => {
            requests.push({url, options, resolve, reject});
        });

    let approval = true;
    let confirmationCalls = 0;

    const window = {
        confirm: () => {
            confirmationCalls++;
            return approval;
        },
    };

    const document = {
        readyState: 'complete',
        getElementById: id => nodes.get(id) || null,
        createElement: tag => new FakeElement(tag),
        createDocumentFragment: () =>
            new FakeElement('#fragment'),
    };

    vm.runInNewContext(
        script,
        {document, window, fetch, URL, console},
        {filename: 'github-app.js'}
    );

    return {
        requests,
        node: id => nodes.get('github-app-' + id),
        setApproval: value => { approval = value; },
        get confirmationCalls() { return confirmationCalls; },
    };
}

function makePreview(valid = 1, invalid = 0, imported = 0) {
    const rows = [];

    for (const [status, count] of [
        ['VALID', valid],
        ['INVALID', invalid],
        ['ALREADY_IMPORTED', imported],
    ]) {
        for (let i = 0; i < count; i++) {
            const number = rows.length + 101;
            rows.push({
                record_number: rows.length + 1,
                status,
                reason: '',
                data: {
                    github_number: number,
                    title: 'Test issue',
                    source_url:
                        `https://github.com/demo/bugs/issues/${number}`,
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
    for (let i = 0; i < 20; i++) {
        await Promise.resolve();
    }
}

async function successfulPreview(ctx, preview = makePreview()) {
    ctx.node('preview-btn').trigger('click');
    const request = ctx.requests.at(-1);

    reply(request, {success: true, preview});
    await settle();
}

test('initial state requires CSRF for preview', () => {
    const normal = setup();
    assert.equal(normal.node('preview-btn').disabled, false);
    assert.equal(normal.node('import-btn').disabled, true);

    const missing = setup({csrf: ''});
    assert.equal(missing.node('preview-btn').disabled, true);
    assert.equal(missing.node('import-btn').disabled, true);
});

test('preview sends POST and renders issue counts', async () => {
    const ctx = setup();

    ctx.node('preview-btn').trigger('click');

    assert.equal(ctx.requests.length, 1);
    assert.equal(ctx.requests[0].url, '/app/preview');
    assert.equal(ctx.requests[0].options.method, 'POST');
    assert.equal(
        ctx.requests[0].options.headers['X-CSRF-TOKEN'],
        'test-csrf-token'
    );

    assert.equal(ctx.node('preview-btn').disabled, true);

    reply(ctx.requests[0], {
        success: true,
        preview: makePreview(2, 1, 1),
    });
    await settle();

    assert.equal(ctx.node('total-count').textContent, '4');
    assert.equal(ctx.node('valid-count').textContent, '2');
    assert.equal(ctx.node('invalid-count').textContent, '1');
    assert.equal(ctx.node('imported-count').textContent, '1');
    assert.equal(ctx.node('preview-rows').children.length, 4);
    assert.equal(ctx.node('import-btn').disabled, false);
    assert.equal(
        ctx.node('preview-section').classList.contains('d-none'),
        false
    );
});

test('zero valid issues disables import', async () => {
    const ctx = setup();
    await successfulPreview(ctx, makePreview(0, 1, 1));

    assert.equal(ctx.node('import-btn').disabled, true);
    assert.equal(ctx.requests.length, 1);
});

test('cancelled import never sends import request', async () => {
    const ctx = setup();
    await successfulPreview(ctx);

    ctx.setApproval(false);
    ctx.node('import-btn').trigger('click');
    await settle();

    assert.equal(ctx.confirmationCalls, 1);
    assert.equal(ctx.requests.length, 1);
    assert.equal(ctx.node('import-btn').disabled, false);
});

test('confirmed import sends confirmation and resets preview', async () => {
    const ctx = setup();
    await successfulPreview(ctx);

    ctx.node('import-btn').trigger('click');

    assert.equal(ctx.requests.length, 2);
    assert.equal(ctx.requests[1].url, '/app/import');
    assert.equal(ctx.requests[1].options.method, 'POST');
    assert.deepEqual(
        JSON.parse(ctx.requests[1].options.body),
        {confirmed: true}
    );

    reply(ctx.requests[1], {
        success: true,
        result: {imported_count: 1},
    });
    await settle();

    assert.equal(ctx.node('import-btn').disabled, true);
    assert.equal(ctx.node('preview-rows').children.length, 0);
    assert.match(ctx.node('feedback').textContent, /Impor berhasil/);
});

test('busy state prevents duplicate preview requests', async () => {
    const ctx = setup();

    ctx.node('preview-btn').trigger('click');
    ctx.node('preview-btn').trigger('click');

    assert.equal(ctx.requests.length, 1);

    reply(ctx.requests[0], {
        success: true,
        preview: makePreview(),
    });
    await settle();

    assert.equal(ctx.node('preview-btn').disabled, false);
});

test('failed preview clears previously valid results', async () => {
    const ctx = setup();
    await successfulPreview(ctx);

    assert.equal(ctx.node('import-btn').disabled, false);

    ctx.node('preview-btn').trigger('click');

    reply(ctx.requests[1], {
        success: false,
        message: 'GitHub API unavailable',
    }, 503);
    await settle();

    assert.equal(ctx.node('import-btn').disabled, true);
    assert.equal(ctx.node('preview-rows').children.length, 0);
    assert.match(ctx.node('feedback').textContent, /unavailable/);
});

for (const status of [409, 422]) {
    test(`import HTTP ${status} invalidates stale preview`, async () => {
        const ctx = setup();
        await successfulPreview(ctx);

        ctx.node('import-btn').trigger('click');

        reply(ctx.requests[1], {
            success: false,
            message: 'Preview no longer valid',
        }, status);
        await settle();

        assert.equal(ctx.node('import-btn').disabled, true);
        assert.equal(ctx.node('preview-rows').children.length, 0);
        assert.equal(ctx.node('preview-btn').disabled, false);
    });
}

test('untrusted source URL cannot become a clickable link', async () => {
    const ctx = setup();
    const preview = makePreview();

    preview.rows[0].data.source_url =
        'https://evil.example/demo/bugs/issues/101';

    await successfulPreview(ctx, preview);

    const row = ctx.node('preview-rows').children[0];
    const links = row.children.flatMap(cell =>
        cell.children.filter(child => child.tagName === 'a')
    );

    assert.equal(links.length, 0);
    assert.equal(ctx.node('import-btn').disabled, false);
});
