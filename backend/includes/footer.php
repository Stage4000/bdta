            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/shared-ui.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('select[data-searchable-select]').forEach(function (select) {
                if (select.dataset.searchableReady === '1') {
                    return;
                }

                select.dataset.searchableReady = '1';
                var searchWrapper = document.createElement('div');
                searchWrapper.className = 'mb-2';

                var searchInput = document.createElement('input');
                searchInput.type = 'search';
                searchInput.className = 'form-control';
                searchInput.placeholder = select.dataset.searchPlaceholder || 'Search...';
                searchInput.setAttribute('aria-label', select.dataset.searchPlaceholder || 'Search');

                var originalOptions = Array.from(select.options).map(function (option) {
                    return {
                        value: option.value,
                        text: option.text,
                        selected: option.selected,
                        disabled: option.disabled
                    };
                });

                function renderOptions() {
                    var searchTerm = searchInput.value.trim().toLowerCase();
                    var currentValue = select.value;
                    select.innerHTML = '';

                    var matches = originalOptions.filter(function (option) {
                        return option.value === '' ||
                            option.value === currentValue ||
                            option.text.toLowerCase().indexOf(searchTerm) !== -1;
                    });

                    if (matches.length === 0) {
                        var noMatchOption = document.createElement('option');
                        noMatchOption.value = '';
                        noMatchOption.textContent = 'No matches found';
                        noMatchOption.disabled = true;
                        select.appendChild(noMatchOption);
                        return;
                    }

                    matches.forEach(function (optionData) {
                        var option = document.createElement('option');
                        option.value = optionData.value;
                        option.textContent = optionData.text;
                        option.disabled = optionData.disabled;
                        option.selected = optionData.value === currentValue;
                        select.appendChild(option);
                    });
                }

                searchInput.addEventListener('input', renderOptions);
                if (select.form) {
                    select.form.addEventListener('reset', function () {
                        searchInput.value = '';
                        renderOptions();
                    });
                }

                select.parentNode.insertBefore(searchWrapper, select);
                searchWrapper.appendChild(searchInput);
            });
        });
    </script>
    <?php
    require_once __DIR__ . '/tawk_to.php';
    bdta_render_tawk_to_widget();
    ?>
</body>
</html>
