# DupIssue browser extension
Native Manifest V3, no npm dependencies or build required for production.
Load this directory with **Load unpacked** in Chrome's extension developer mode,
or Edge's extension developer mode. Minimum Chromium version: 114.

The packaged API origin is https://dupissue.caiis.ai.id. Do not run automated tests
against that origin. To use an isolated local Laravel server on
http://127.0.0.1:8000, run:
```sh
node browser-extension/tools/build-local.mjs
```
Then load the generated .local-extension directory instead. This development copy
has only localhost API permission. Arbitrary origins cannot be configured at runtime.
For the isolated interactive QA server on port18080, use
`node browser-extension/tools/build-local.mjs --qa`. This selects one fixed loopback
origin; it does not accept arbitrary URLs. See [local QA guide](../docs/LOCAL_QA_GUIDE.md).

Open DupIssue's popup, choose **Connect to DupIssue**, log in as Reporter, create
a pairing code and paste it into the popup. Codes are single-use and expire in
five minutes. Tokens expire in eight hours, are stored only in storage.session
with TRUSTED_CONTEXTS, and are never returned to content scripts.
Closing the browser clears session storage; pair again after restart.
Service-worker restarts preserve the current session.

Disconnect revokes the server credential. On network failure the popup reports
unconfirmed revocation and retains the session for a retry. Revoke lost sessions
from /extension/connect on the website. If pairing was interrupted after the
server exchanged a code, inspect and revoke unrecognized sessions there.

Recommendations use a separate extension-owned window to keep private issue data
out of the GitHub DOM. No web-accessible extension resources or page messaging
bridge is enabled. GitHub scripts can still observe their own form inputs.

Run tests with:
```sh
node --test browser-extension/tests/*.test.mjs
```
Browser API mocks prove contracts and local behavior, not successful installation
or live Chrome/Edge/GitHub compatibility. See the development progress log for
actual validation evidence.

## Use recommendations

Open a connected repository's New Issue page, click the extension action, then
open the recommendation workspace. Select one authorized DupIssue project and
click **Aktifkan rekomendasi** after reading the draft transmission notice.
Typing a valid title/body starts a 500 ms debounce; changing project, repository,
closing the last workspace window or restarting the worker resets consent.
The workspace refreshes authorization and actual vote status every 15 seconds.
It reads trusted local worker state every500ms so completed recommendations render
promptly without increasing API polling. Failed refresh clears cached private results.
The backend rechecks authorization on every recommendation/vote request.

Only a single recognized Markdown title/body pair is captured. Structured GitHub
issue forms and ambiguous template selection screens fail closed. Live GitHub
selectors still require Chrome and Edge testing; do not claim universal templates.
Private titles/descriptions are rendered with textContent in an extension page.
They are never inserted into GitHub DOM, Shadow DOM, or a web-accessible iframe.
The window is an intentional confidentiality tradeoff against embedded-page UX.

## Manual Chrome / Edge checklist

1. Use an isolated local test application/database, never production fixtures.
2. Run the local build command above; inspect its origin and host permission.
3. Chrome: `chrome://extensions`; Edge: `edge://extensions`. Enable developer
   mode, Load unpacked, choose `.local-extension`, and inspect service-worker errors.
4. Pair a local Reporter, verify name, close/reopen popup, restart worker, and
   confirm the session remains while draft consent resets.
5. Open New Issue for a connected test repository. Test one/multiple projects,
   empty/short/long drafts, rapid edits, SPA navigation and Markdown templates.
6. Verify at most three real signed cosine results; inspect matching web details.
   Vote/unvote on both surfaces and refresh actual counts. Test duplicate parent.
7. Remove membership, replace repository, revoke session in the website, expire
   token, disconnect network and simulate 429/503 in an isolated environment.
8. Inspect GitHub DOM and content-script messages: no token or private results.
   Inspect requests: exact API origin, no cookies, Bearer header, redirect rejection.
9. Confirm native issue creation remains fully controlled by the user. Creating
   an actual GitHub issue requires separate user approval.

Actual unpacked installation and interaction passed in dedicated Chrome for Testing
158.0.8127.88 with the local backend and original offline NLP model. GitHub HTML was
a routed fixture, not the live site. Branded Chrome, Microsoft Edge and actual GitHub
DOM/templates remain pending. The 21 Node tests remain simulations; the separate
`tests/qa-browser.mjs` records real browser/local-service evidence. It requires the
QA tooling/profile/server described in the local QA guide and never contacts GitHub.

See [operations and architecture](../docs/EXTENSION_SYNC_OPERATIONS.md),
[final QA report](../docs/FINAL_QA_REPORT.md), and
[development checkpoints](../docs/DEVELOPMENT_PROGRESS.md).
