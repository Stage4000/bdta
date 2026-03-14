(function () {
    'use strict';

    function updateIcon() {
        var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        var icon = document.getElementById('darkModeIcon');

        if (icon) {
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    function init() {
        var btn = document.getElementById('darkModeToggle');

        updateIcon();

        if (!btn) {
            return;
        }

        btn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-bs-theme', next);
            localStorage.setItem('bdta-theme', next);
            updateIcon();
        });
    }

    init();
}());
