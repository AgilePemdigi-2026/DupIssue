(function () {
    const sidebar = document.querySelector('.docs-sidebar');
    const menuButton = document.querySelector('.mobile-menu-button');
    const backdrop = document.querySelector('.sidebar-backdrop');
    const searchInput = document.querySelector('#docs-search');
    const searchResults = document.querySelector('#search-results');
    const headingsContainer = document.querySelector('#page-headings');
    const topicList = window.DupIssueDocumentationTopics || {};

    function closeSidebar() {
        document.body.classList.remove('sidebar-open');
        if (menuButton) menuButton.setAttribute('aria-expanded', 'false');
    }

    if (menuButton) {
        menuButton.addEventListener('click', function () {
            const open = document.body.classList.toggle('sidebar-open');
            menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    document.querySelectorAll('.group-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            const expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        });
    });

    if (searchInput && searchResults) {
        searchInput.addEventListener('input', function () {
            const query = searchInput.value.trim().toLowerCase();
            if (!query) {
                searchResults.hidden = true;
                searchResults.innerHTML = '';
                return;
            }

            const matches = Object.values(topicList).filter(function (topic) {
                return (topic.title + ' ' + topic.category + ' ' + (topic.search || '')).toLowerCase().includes(query);
            });

            if (!matches.length) {
                searchResults.innerHTML = '<p>Tidak ada hasil ditemukan.</p>';
            } else {
                searchResults.innerHTML = matches.map(function (topic) {
                    return '<a href="' + topic.url + '"><strong>' + topic.title + '</strong><small>' + topic.category + '</small></a>';
                }).join('');
            }
            searchResults.hidden = false;
        });

        document.addEventListener('click', function (event) {
            if (!searchResults.contains(event.target) && event.target !== searchInput) {
                searchResults.hidden = true;
            }
        });
    }

    if (headingsContainer) {
        const headings = document.querySelectorAll('.article h2[id]');
        headings.forEach(function (heading) {
            const link = document.createElement('a');
            link.href = '#' + heading.id;
            link.textContent = heading.textContent;
            headingsContainer.appendChild(link);
        });
    }

    if (sidebar) {
        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 720) closeSidebar();
            });
        });
    }
})();
