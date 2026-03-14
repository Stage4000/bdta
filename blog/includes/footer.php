    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Dark mode toggle -->
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
    <?php
    require_once dirname(__DIR__, 2) . '/backend/includes/tawk_to.php';
    bdta_render_tawk_to_widget();
    ?>
</body>
</html>
