(() => {
    "use strict";

    // Bootstrap adds modal backdrops to <body>. A modal nested in an animated
    // container (or a table/card) can be trapped in its stacking context and
    // appear beneath the backdrop, making the page seem unresponsive.
    // Move the existing modal nodes, not clones, so IDs and event handlers stay.
    function moveModalsToBody() {
        document.querySelectorAll("main .modal").forEach((modal) => {
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", moveModalsToBody);
    } else {
        moveModalsToBody();
    }
})();
