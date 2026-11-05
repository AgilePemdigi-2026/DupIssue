'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(
    path.resolve(__dirname, '../../public/js/issue-recommendations.js'),
    'utf8'
);

class FakeElement {
    constructor(tag = 'div') {
        this.tagName = tag.toLowerCase();
        this.children = [];
        this.textContent = '';
        this.value = '';
        this.className = '';
        this.dataset = {};
        this.style = {};
        this.listeners = new Map();
        this.attributes = {};
        const classes = new Set();
        this.classList = {
            add: (name) => classes.add(name),
            remove: (name) => classes.delete(name),
            toggle: (name, force) => {
                if (force === true) classes.add(name);
                else if (force === false) classes.delete(name);
                else if (classes.has(name)) classes.delete(name);
                else classes.add(name);
                return classes.has(name);
            },
            contains: (name) => classes.has(name),
        };
    }

    append(...children) {
        for (const child of children) {
            if (child.tagName === '#fragment') this.children.push(...child.children);
            else this.children.push(child);
        }
    }

    replaceChildren(...children) {
        this.children = [];
        this.append(...children);
    }

    addEventListener(event, callback) {
        this.listeners.set(event, callback);
    }

    trigger(event) {
        const callback = this.listeners.get(event);
        if (!callback) throw new Error('Missing ' + event + ' listener');
        callback({target: this});
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    querySelector(selector) {
        if (selector === 'input[name="_token"]') return {value: 'test-csrf'};
        return null;
    }
}

class ManualClock {
    constructor() {
        this.now = 0;
        this.sequence = 1;
        this.tasks = new Map();
    }

    setTimeout(callback, ms) {
        const id = this.sequence++;
        this.tasks.set(id, {due: this.now + ms, callback});
        return id;
    }

    clearTimeout(id) {
        this.tasks.delete(id);
    }

    advance(ms) {
        const target = this.now + ms;
        while (true) {
            const due = Array.from(this.tasks.entries())
                .filter(([, task]) => task.due <= target)
                .sort((a, b) => a[1].due - b[1].due || a[0] - b[0]);
            if (!due.length) break;
            const [id, task] = due[0];
            this.tasks.delete(id);
            this.now = task.due;
            task.callback();
        }
        this.now = target;
    }
}

function setup() {
    const clock = new ManualClock();
    const requests = [];
    const nodes = new Map();
    let navigation = '';
    let modalOpened = false;

    const ids = [
        'issue-submit-form',
        'title',
        'description',
        'recommendation-status',
        'recommendation-loading',
        'recommendation-list',
        'recommendation-detail-modal',
        'recommendation-detail-id',
        'recommendation-detail-title',
        'recommendation-detail-status',
        'recommendation-detail-description',
        'recommendation-detail-parent',
        'recommendation-detail-content',
        'recommendation-confirm-content',
        'recommendation-detail-actions',
        'recommendation-confirm-actions',
        'recommendation-cancel-submit',
        'recommendation-back-to-detail',
        'recommendation-open-issue',
    ];

    for (const id of ids) nodes.set(id, new FakeElement());
    const form = nodes.get('issue-submit-form');
    form.dataset.recommendationsUrl = '/projects/1/issues/recommendations';

    const document = {
        readyState: 'complete',
        getElementById: (id) => nodes.get(id) || null,
        createElement: (tag) => new FakeElement(tag),
        createDocumentFragment: () => new FakeElement('#fragment'),
    };

    const window = {
        setTimeout: (callback, ms) => clock.setTimeout(callback, ms),
        clearTimeout: (id) => clock.clearTimeout(id),
        location: {
            origin: 'http://localhost',
            assign: (url) => {navigation = url;},
        },
        bootstrap: {
            Modal: {
                getOrCreateInstance: () => ({
                    show: () => {modalOpened = true;},
                }),
            },
        },
        alert: (message) => {throw new Error(message);},
    };

    const fetch = (url, options) => new Promise((resolve, reject) => {
        requests.push({url, options, resolve, reject});
    });

    vm.runInNewContext(script, {
        document, window, fetch, AbortController, URL, Intl, Number, Array,
        console,
    }, {filename: 'issue-recommendations.js'});

    return {
        nodes, clock, requests, document,
        get navigation() {return navigation;},
        get modalOpened() {return modalOpened;},
    };
}

function validInput(fixture, title = 'Tombol bayar tidak berfungsi') {
    fixture.nodes.get('title').value = title;
    fixture.nodes.get('description').value =
        'Saat melakukan checkout, transaksi pembayaran tidak diproses.';
    fixture.nodes.get('description').trigger('input');
}

function recommendation(id, title, cosine) {
    return {
        issue_id: id,
        target_issue_id: id,
        title,
        description: 'Deskripsi pengujian issue dengan teks yang cukup panjang.',
        status: 'OPEN',
        similarity: Math.max(0, cosine),
        cosine_similarity: cosine,
        detail_url: '/projects/1/issues/' + id,
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
    for (let i = 0; i < 8; i++) await Promise.resolve();
}

function textOf(element) {
    return String(element.textContent || '') + ' ' +
        element.children.map(textOf).join(' ');
}

function findByText(element, label) {
    if (element.tagName === 'button' && element.textContent === label) {
        return element;
    }
    for (const child of element.children) {
        const found = findByText(child, label);
        if (found) return found;
    }
    return null;
}

test('valid input is debounced for 500ms after the last edit', () => {
    const f = setup();
    validInput(f);
    f.clock.advance(300);
    f.nodes.get('title').value += ' lagi';
    f.nodes.get('title').trigger('input');
    f.clock.advance(499);
    assert.equal(f.requests.length, 0);
    f.clock.advance(1);
    assert.equal(f.requests.length, 1);
    assert.equal(f.requests[0].options.method, 'POST');
    assert.equal(JSON.parse(f.requests[0].options.body).title,
        'Tombol bayar tidak berfungsi lagi');
});

test('an older response finishing late never replaces a newer response', async () => {
    const f = setup();
    validInput(f, 'Draft versi pertama');
    f.clock.advance(500);
    assert.equal(f.requests.length, 1);

    f.nodes.get('title').value = 'Draft versi kedua';
    f.nodes.get('title').trigger('input');
    assert.equal(f.requests[0].options.signal.aborted, true);
    f.clock.advance(500);
    assert.equal(f.requests.length, 2);

    reply(f.requests[1], {
        recommendations: [recommendation(2, 'Kandidat terbaru', 0.92)],
    });
    await settle();
    assert.match(textOf(f.nodes.get('recommendation-list')), /Kandidat terbaru/);

    // Simulate a transport that ignores AbortSignal and still returns old data.
    reply(f.requests[0], {
        recommendations: [recommendation(1, 'Kandidat lama', 0.99)],
    });
    await settle();
    const displayed = textOf(f.nodes.get('recommendation-list'));
    assert.match(displayed, /Kandidat terbaru/);
    assert.doesNotMatch(displayed, /Kandidat lama/);
});

test('renders no more than three cards even if API returns four', async () => {
    const f = setup();
    validInput(f);
    f.clock.advance(500);
    reply(f.requests[0], {
        recommendations: [
            recommendation(1, 'Issue 1', 1),
            recommendation(2, 'Issue 2', 0.7),
            recommendation(3, 'Issue 3', 0),
            recommendation(4, 'Issue 4', -0.2),
        ],
    });
    await settle();
    assert.equal(f.nodes.get('recommendation-list').children.length, 3);
    assert.doesNotMatch(textOf(f.nodes.get('recommendation-list')), /Issue 4/);
});

test('detail modal and return do not modify the unsaved draft', async () => {
    const f = setup();
    validInput(f);
    const savedTitle = f.nodes.get('title').value;
    const savedDescription = f.nodes.get('description').value;
    f.clock.advance(500);
    reply(f.requests[0], {
        recommendations: [recommendation(5, 'Existing issue', 0.88)],
    });
    await settle();

    const open = findByText(f.nodes.get('recommendation-list'), 'Lihat Detail');
    assert.ok(open);
    open.trigger('click');
    assert.equal(f.modalOpened, true);
    assert.equal(f.nodes.get('title').value, savedTitle);
    assert.equal(f.nodes.get('description').value, savedDescription);

    f.nodes.get('recommendation-cancel-submit').onclick();
    f.nodes.get('recommendation-back-to-detail').onclick();
    assert.equal(f.navigation, '');
    assert.equal(f.nodes.get('title').value, savedTitle);
    assert.equal(f.nodes.get('description').value, savedDescription);

    f.nodes.get('recommendation-cancel-submit').onclick();
    f.nodes.get('recommendation-open-issue').onclick();
    assert.equal(f.navigation, 'http://localhost/projects/1/issues/5');
});

test('a 503 response shows a safe fallback without submitting the form', async () => {
    const f = setup();
    validInput(f);
    f.clock.advance(500);
    reply(f.requests[0], {code: 'NLP_UNAVAILABLE'}, 503);
    await settle();

    assert.equal(f.nodes.get('recommendation-status').textContent,
        'Rekomendasi belum dapat dimuat');
    assert.equal(f.nodes.get('title').value, 'Tombol bayar tidak berfungsi');
    assert.equal(f.navigation, '');
});
