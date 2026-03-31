    <!-- Dark mode toggle (floating) -->
    <button id="darkModeToggle" class="btn btn-outline-secondary btn-sm position-fixed top-0 end-0 m-3 no-print" style="z-index:1100;" title="Toggle dark mode" aria-label="Toggle dark mode">
        <i class="fas fa-moon" id="darkModeIcon"></i>
    </button>
    <script src="/assets/js/theme-toggle.js"></script>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/tawk_to.php';
    bdta_render_tawk_to_widget();
    ?>
</body>
</html>
