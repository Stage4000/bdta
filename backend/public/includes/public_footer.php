    <!-- Dark mode toggle (floating) -->
    <button id="darkModeToggle" class="btn btn-outline-secondary btn-sm position-fixed top-0 end-0 m-3 no-print" style="z-index:1100;" title="Toggle dark mode" aria-label="Toggle dark mode">
        <i class="fas fa-moon" id="darkModeIcon"></i>
    </button>
    <script>
    (function () {
        'use strict';
        function updateIcon() {
            var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            var icon = document.getElementById('darkModeIcon');
            if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
        updateIcon();
        var btn = document.getElementById('darkModeToggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                localStorage.setItem('bdta-theme', next);
                updateIcon();
            });
        }
    }());
    </script>
</body>
</html>
