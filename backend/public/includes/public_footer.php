    <!-- Dark mode toggle (floating) -->
    <?php
    require_once dirname(__DIR__, 2) . '/includes/public_notice.php';
    bdta_render_public_notice();
    ?>
    <button id="darkModeToggle" class="btn btn-outline-secondary btn-sm position-fixed no-print public-theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">
        <i class="fas fa-moon" id="darkModeIcon"></i>
    </button>
    <script src="/assets/js/theme-toggle.js"></script>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tawk_to.php';
    bdta_render_tawk_to_widget();
    ?>
</body>
</html>
