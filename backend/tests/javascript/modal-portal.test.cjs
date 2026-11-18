'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(
    path.resolve(__dirname, '../../public/js/modal-portal.js'), 'utf8'
);

function setup(readyState = 'complete') {
    const animatedParent = {id: 'animated-row'};
    const tableParent = {id: 'member-table'};
    const otherParent = {id: 'outside-main'};

    const recommendationModal = {id: 'recommendation-detail-modal', parentElement: animatedParent};
    const removeMemberModal = {id: 'modalRemoveMember1', parentElement: tableParent};
    const outsideModal = {id: 'other-modal', parentElement: otherParent};
    const appended = [];
    const listeners = new Map();

    const body = {
        appendChild(element) {
            appended.push(element);
            element.parentElement = body;
        },
    };

    const document = {
        readyState,
        body,
        querySelectorAll(selector) {
            assert.equal(selector, 'main .modal');
            return [recommendationModal, removeMemberModal]
                .filter((modal) => modal.parentElement !== body);
        },
        addEventListener(event, callback) {
            listeners.set(event, callback);
        },
    };

    vm.runInNewContext(script, {document}, {filename: 'modal-portal.js'});

    return {
        body, appended, listeners,
        recommendationModal, removeMemberModal, outsideModal, otherParent,
    };
}

test('moves recommendation and member modals above the backdrop stacking context', () => {
    const f = setup();
    assert.equal(f.recommendationModal.parentElement, f.body);
    assert.equal(f.removeMemberModal.parentElement, f.body);
    assert.deepEqual(f.appended.map((modal) => modal.id),
        ['recommendation-detail-modal', 'modalRemoveMember1']);
    assert.equal(f.outsideModal.parentElement, f.otherParent);
});

test('waits for DOMContentLoaded when markup has not finished loading', () => {
    const f = setup('loading');
    assert.equal(f.appended.length, 0);
    assert.equal(f.listeners.has('DOMContentLoaded'), true);
    f.listeners.get('DOMContentLoaded')();
    assert.equal(f.appended.length, 2);

    // Running again must not move or clone modals already under body.
    f.listeners.get('DOMContentLoaded')();
    assert.equal(f.appended.length, 2);
});
