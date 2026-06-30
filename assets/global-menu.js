(function () {
    if (document.getElementById('sidebar')) {
        return;
    }

    const endpoint = 'api/menu.php';
    const body = document.body;
    if (!body) {
        return;
    }

    function createLine(className, text) {
        const el = document.createElement('span');
        el.className = className;
        el.textContent = text || '';
        return el;
    }

    function createMenuItem(item) {
        const a = document.createElement('a');
        a.className = 'gmenu-item';
        a.href = item.href || '#';
        if (item.target) {
            a.target = item.target;
            a.rel = 'noopener';
        }
        a.dataset.moduleKey = item.key || '';
        a.appendChild(createLine('gmenu-line-th', item.title_th || ''));
        a.appendChild(createLine('gmenu-line-en', item.title_en || ''));
        return a;
    }

    function isActive(item) {
        if (!item || typeof item.href !== 'string') {
            return false;
        }

        const currentPath = window.location.pathname.toLowerCase();
        const currentModule = new URLSearchParams(window.location.search).get('module');
        if (item.key && currentModule && item.key === currentModule) {
            return true;
        }

        try {
            const u = new URL(item.href, window.location.href);
            const targetPath = u.pathname.toLowerCase();
            if (targetPath !== currentPath) {
                return false;
            }
            if (!u.search) {
                return true;
            }

            const currentQ = new URLSearchParams(window.location.search);
            const targetQ = new URLSearchParams(u.search);
            for (const [k, v] of targetQ.entries()) {
                if (currentQ.get(k) !== v) {
                    return false;
                }
            }
            return true;
        } catch (err) {
            return false;
        }
    }

    function renderMenu(data) {
        const root = document.createElement('div');
        root.className = 'gmenu-root';
        root.innerHTML = ''
            + '<button type="button" class="gmenu-toggle" id="gmenuToggle" aria-label="Toggle menu">☰</button>'
            + '<aside class="gmenu-sidebar" id="gmenuSidebar">'
            + '  <div class="gmenu-header">'
            + '    <h1 class="gmenu-brand">' + (data.app_name || 'SynergyERP') + '</h1>'
            + '    <div class="gmenu-sub">Thai / English Menu</div>'
            + '  </div>'
            + '  <nav class="gmenu-nav" id="gmenuNav"></nav>'
            + '</aside>'
            + '<div class="gmenu-backdrop" id="gmenuBackdrop"></div>';

        body.prepend(root);
        body.classList.add('gmenu-enabled');

        const nav = document.getElementById('gmenuNav');
        if (!nav) {
            return;
        }

        const sectionQuick = document.createElement('div');
        sectionQuick.className = 'gmenu-section-title';
        sectionQuick.textContent = 'Quick Links';
        nav.appendChild(sectionQuick);

        (data.quick_links || []).forEach(function (item) {
            const a = createMenuItem(item);
            if (isActive(item)) {
                a.classList.add('active');
            }
            nav.appendChild(a);
        });

        const sectionModules = document.createElement('div');
        sectionModules.className = 'gmenu-section-title';
        sectionModules.textContent = 'Modules';
        nav.appendChild(sectionModules);

        (data.groups || []).forEach(function (group) {
            const wrap = document.createElement('div');
            wrap.className = 'gmenu-group';

            const title = document.createElement('div');
            title.className = 'gmenu-group-title';
            title.appendChild(createLine('gmenu-line-th', group.title_th || ''));
            title.appendChild(createLine('gmenu-line-en', group.title_en || ''));
            wrap.appendChild(title);

            (group.items || []).forEach(function (item) {
                const a = createMenuItem(item);
                if (isActive(item)) {
                    a.classList.add('active');
                }
                wrap.appendChild(a);
            });

            nav.appendChild(wrap);
        });

        const toggle = document.getElementById('gmenuToggle');
        const sidebar = document.getElementById('gmenuSidebar');
        const backdrop = document.getElementById('gmenuBackdrop');

        function closeMenu() {
            if (sidebar) {
                sidebar.classList.remove('show');
            }
            if (backdrop) {
                backdrop.classList.remove('show');
            }
        }

        function toggleMenu() {
            if (!sidebar || !backdrop) {
                return;
            }
            const show = !sidebar.classList.contains('show');
            sidebar.classList.toggle('show', show);
            backdrop.classList.toggle('show', show);
        }

        if (toggle) {
            toggle.addEventListener('click', toggleMenu);
        }
        if (backdrop) {
            backdrop.addEventListener('click', closeMenu);
        }

        window.addEventListener('resize', function () {
            if (window.innerWidth > 992) {
                closeMenu();
            }
        });
    }

    fetch(endpoint, { credentials: 'same-origin' })
        .then(function (res) { return res.ok ? res.json() : Promise.reject(new Error('menu fetch failed')); })
        .then(function (json) {
            if (!json || json.ok !== true) {
                return;
            }
            renderMenu(json);
        })
        .catch(function () {
            // silent fallback for pages where menu API is unavailable.
        });
})();
