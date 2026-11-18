'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(
    path.resolve(__dirname, '../../public/js/github-connection.js'),
    'utf8'
);

class FakeElement {
    constructor() {
        this.value = '';
        this.disabled = false;
        this.textContent = '';
        this.innerHTML = 'Original button';
        this.className = '';
        this.dataset = {};
        this.attributes = {};
        this.listeners = new Map();
        this.dispatchedEvents = [];
    }

    addEventListener(type, callback) {
        this.listeners.set(type, callback);
    }

    trigger(type) {
        const callback = this.listeners.get(type);

        assert.ok(callback, `Missing listener: ${type}`);

        return callback({
            preventDefault() {},
            target: this,
        });
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }

    querySelector(selector) {
        if (selector === 'input[name="_token"]') {
            return { value: 'test-csrf-token' };
        }

        return null;
    }

    dispatchEvent(event) {
        this.dispatchedEvents.push(event);
        return true;
    }
}

function setup() {
    const ids = [
        'github-panel',
        'github-connection-form',
        'github-repo',
        'github-pat',
        'github-test-btn',
        'github-save-btn',
        'github-feedback',
    ];

    const nodes = new Map(
        ids.map(id => [id, new FakeElement()])
    );

    const panel = nodes.get('github-panel');

    panel.dataset.testUrl = '/github/test';
    panel.dataset.saveUrl = '/github/save';
    panel.dataset.connectedRepo = '';

    const requests = [];

    const fetch = (url, options) => new Promise(
        (resolve, reject) => {
            requests.push({ url, options, resolve, reject });
        }
    );

    class FakeCustomEvent {
        constructor(type, options = {}) {
            this.type = type;
            this.detail = options.detail;
        }
    }

    const document = {
        readyState: 'complete',
        getElementById: id => nodes.get(id) || null,
    };

    vm.runInNewContext(script, {
        document,
        fetch,
        CustomEvent: FakeCustomEvent,
        console,
    }, {
        filename: 'github-connection.js',
    });

    return { nodes, requests };
}

function enterCredentials(fixture) {
    const repo = fixture.nodes.get('github-repo');
    const pat = fixture.nodes.get('github-pat');

    repo.value = 'demo/bugs';
    pat.value = 'dummy-secret-token';

    repo.trigger('input');
    pat.trigger('input');
}

function reply(request, data, status = 200) {
    request.resolve({
        ok: status >= 200 && status < 300,
        status,
        json: async () => data,
    });
}

async function settle() {
    for (let i = 0; i < 12; i++) {
        await Promise.resolve();
    }
}

test('buttons require both repository and PAT', () => {
    const fixture = setup();

    const repo = fixture.nodes.get('github-repo');
    const pat = fixture.nodes.get('github-pat');
    const testButton = fixture.nodes.get('github-test-btn');
    const saveButton = fixture.nodes.get('github-save-btn');

    assert.equal(testButton.disabled, true);
    assert.equal(saveButton.disabled, true);

    repo.value = 'demo/bugs';
    repo.trigger('input');

    assert.equal(testButton.disabled, true);
    assert.equal(saveButton.disabled, true);

    pat.value = 'dummy-secret-token';
    pat.trigger('input');

    assert.equal(testButton.disabled, false);
    assert.equal(saveButton.disabled, false);

    pat.value = '   ';
    pat.trigger('input');

    assert.equal(testButton.disabled, true);
    assert.equal(saveButton.disabled, true);
});

test('test connection sends credentials and shows success', async () => {
    const fixture = setup();
    enterCredentials(fixture);

    const button = fixture.nodes.get('github-test-btn');
    const feedback = fixture.nodes.get('github-feedback');

    button.trigger('click');

    assert.equal(fixture.requests.length, 1);
    assert.equal(button.disabled, true);

    const request = fixture.requests[0];

    assert.equal(request.url, '/github/test');
    assert.equal(request.options.method, 'POST');
    assert.equal(
        request.options.headers['X-CSRF-TOKEN'],
        'test-csrf-token'
    );

    const body = JSON.parse(request.options.body);

    assert.equal(body.repo_full_name, 'demo/bugs');
    assert.equal(body.pat, 'dummy-secret-token');

    reply(request, { success: true });
    await settle();

    assert.match(feedback.textContent, /berhasil diuji/);
    assert.match(feedback.className, /alert-success/);
    assert.equal(button.disabled, false);
});

test('successful save clears PAT and updates connection state', async () => {
    const fixture = setup();
    enterCredentials(fixture);

    const panel = fixture.nodes.get('github-panel');
    const button = fixture.nodes.get('github-save-btn');
    const pat = fixture.nodes.get('github-pat');
    const feedback = fixture.nodes.get('github-feedback');

    button.trigger('click');

    assert.equal(fixture.requests.length, 1);
    assert.equal(button.disabled, true);

    reply(fixture.requests[0], { success: true });
    await settle();

    assert.equal(pat.value, '');
    assert.equal(panel.dataset.connectedRepo, 'demo/bugs');

    assert.equal(panel.dispatchedEvents.length, 1);
    assert.equal(
        panel.dispatchedEvents[0].type,
        'github:connection-saved'
    );
    assert.equal(
        panel.dispatchedEvents[0].detail.repo,
        'demo/bugs'
    );

    assert.match(feedback.className, /alert-success/);
    assert.equal(button.disabled, true);
});

test('failed save restores controls and allows retry', async () => {
    const fixture = setup();
    enterCredentials(fixture);

    const panel = fixture.nodes.get('github-panel');
    const button = fixture.nodes.get('github-save-btn');
    const pat = fixture.nodes.get('github-pat');
    const feedback = fixture.nodes.get('github-feedback');

    button.trigger('click');

    reply(
        fixture.requests[0],
        {
            success: false,
            message: 'Token tidak valid.',
        },
        422
    );

    await settle();

    assert.equal(pat.value, 'dummy-secret-token');
    assert.equal(panel.dataset.connectedRepo, '');
    assert.equal(panel.dispatchedEvents.length, 0);

    assert.match(feedback.textContent, /Token tidak valid/);
    assert.match(feedback.className, /alert-danger/);
    assert.equal(button.disabled, false);

    button.trigger('click');

    assert.equal(fixture.requests.length, 2);

    reply(fixture.requests[1], { success: true });
    await settle();

    assert.equal(pat.value, '');
    assert.equal(panel.dataset.connectedRepo, 'demo/bugs');
    assert.equal(button.disabled, true);
});
