(function () {
    'use strict';

    function normalizeText(value) {
        return (value || '').replace(/\s+/g, ' ').trim();
    }

    function getActionColumnIndexes(table) {
        var headerCells = table.querySelectorAll('thead tr th, thead tr td');
        var indexes = [];

        Array.prototype.forEach.call(headerCells, function (cell, index) {
            if (normalizeText(cell.textContent).toLowerCase() === 'actions') {
                indexes.push(index);
            }
        });

        return indexes;
    }

    function hasActionButton(form) {
        return !!form.querySelector('button, input[type="submit"], input[type="button"]');
    }

    function isDirectActionNode(node) {
        if (!(node instanceof HTMLElement)) {
            return false;
        }

        if (node.matches('.table-action-dropdown, .client-action-dropdown')) {
            return false;
        }

        if (node.matches('form')) {
            return hasActionButton(node);
        }

        return node.matches('.table-action-buttons, .btn-group, a.btn, button.btn');
    }

    function collectActionNodes(cell) {
        return Array.prototype.filter.call(cell.children, isDirectActionNode);
    }

    function ensureDesktopWrapper(cell, actionNodes) {
        var wrapper;
        var firstNode = actionNodes[0];

        if (actionNodes.length === 1 && firstNode.matches('.table-action-buttons, .btn-group')) {
            wrapper = firstNode;
        } else {
            wrapper = document.createElement('div');
            wrapper.className = 'table-action-buttons';
            cell.insertBefore(wrapper, firstNode);

            actionNodes.forEach(function (node) {
                wrapper.appendChild(node);
            });
        }

        wrapper.classList.add('d-none', 'd-md-inline-flex', 'align-items-center');

        return wrapper;
    }

    function flattenActionSources(node, sources) {
        if (!(node instanceof HTMLElement)) {
            return;
        }

        if (node.matches('form')) {
            if (hasActionButton(node)) {
                sources.push(node);
            }
            return;
        }

        if (node.matches('a.btn, button.btn')) {
            sources.push(node);
            return;
        }

        Array.prototype.forEach.call(node.children, function (child) {
            flattenActionSources(child, sources);
        });
    }

    function getInteractiveElement(source) {
        if (source.matches('form')) {
            return source.querySelector('button, input[type="submit"], input[type="button"]');
        }

        return source;
    }

    function deriveMobileLabel(source) {
        var interactive = getInteractiveElement(source);
        var label = '';

        if (interactive) {
            label = interactive.getAttribute('data-mobile-label')
                || interactive.getAttribute('aria-label')
                || interactive.getAttribute('title')
                || normalizeText(interactive.textContent)
                || normalizeText(interactive.value);
        }

        if (!label) {
            label = source.getAttribute('data-mobile-label')
                || source.getAttribute('aria-label')
                || source.getAttribute('title')
                || 'Action';
        }

        return label;
    }

    function getColorClass(className) {
        if (!className) {
            return '';
        }

        if (className.indexOf('btn-outline-danger') !== -1 || className.indexOf('btn-danger') !== -1) {
            return 'text-danger';
        }

        if (className.indexOf('btn-outline-warning') !== -1 || className.indexOf('btn-warning') !== -1) {
            return 'text-warning';
        }

        if (className.indexOf('btn-outline-success') !== -1 || className.indexOf('btn-success') !== -1) {
            return 'text-success';
        }

        if (className.indexOf('btn-outline-info') !== -1 || className.indexOf('btn-info') !== -1) {
            return 'text-info';
        }

        if (className.indexOf('btn-outline-secondary') !== -1 || className.indexOf('btn-secondary') !== -1) {
            return 'text-secondary';
        }

        if (className.indexOf('btn-outline-primary') !== -1 || className.indexOf('btn-primary') !== -1) {
            return 'text-primary';
        }

        if (className.indexOf('btn-outline-dark') !== -1 || className.indexOf('btn-dark') !== -1) {
            return 'text-dark';
        }

        return '';
    }

    function buildIconHtml(source) {
        var interactive = getInteractiveElement(source);
        var icon = interactive ? interactive.querySelector('i[class]') : null;
        var colorClass = interactive ? getColorClass(interactive.className) : '';

        if (!icon) {
            return '';
        }

        var clone = icon.cloneNode(true);

        clone.classList.remove('me-1', 'me-2', 'ms-1', 'ms-2');
        clone.classList.add('me-2');

        if (colorClass !== '' && !clone.className.match(/\btext-[a-z-]+\b/)) {
            clone.classList.add(colorClass);
        }

        return clone.outerHTML;
    }

    function triggerSourceAction(source) {
        var interactive = getInteractiveElement(source);

        if (!interactive || interactive.disabled) {
            return;
        }

        if (source.matches('form')) {
            if (typeof source.requestSubmit === 'function' && interactive instanceof HTMLElement) {
                source.requestSubmit(interactive);
                return;
            }
        }

        interactive.click();
    }

    function createDropdownItem(source) {
        var interactive = getInteractiveElement(source);
        var item = document.createElement('button');
        var label = deriveMobileLabel(source);
        var iconHtml = buildIconHtml(source);
        var colorClass = interactive ? getColorClass(interactive.className) : '';
        var isDanger = colorClass === 'text-danger';

        item.type = 'button';
        item.className = 'dropdown-item';
        item.innerHTML = iconHtml + label;
        item.setAttribute('aria-label', label);

        if (isDanger) {
            item.classList.add('text-danger');
        }

        if (!interactive || interactive.disabled || interactive.getAttribute('aria-disabled') === 'true') {
            item.disabled = true;
            item.classList.add('disabled');
            return item;
        }

        item.addEventListener('click', function (event) {
            event.preventDefault();
            triggerSourceAction(source);
        });

        return item;
    }

    function buildMobileDropdown(sources) {
        var mobileWrapper = document.createElement('div');
        var dropdown = document.createElement('div');
        var button = document.createElement('button');
        var menu = document.createElement('ul');

        mobileWrapper.className = 'd-md-none table-action-dropdown';
        mobileWrapper.setAttribute('data-generated-table-actions', '1');
        dropdown.className = 'dropdown';

        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn';
        button.setAttribute('data-bs-toggle', 'dropdown');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', 'Actions');
        button.innerHTML = '<i class="fas fa-ellipsis-v"></i>';

        menu.className = 'dropdown-menu dropdown-menu-end';

        sources.forEach(function (source, index) {
            var itemRow = document.createElement('li');
            var item = createDropdownItem(source);
            var interactive = getInteractiveElement(source);
            var isDanger = interactive && getColorClass(interactive.className) === 'text-danger';

            if (isDanger && index > 0) {
                var dividerRow = document.createElement('li');
                var divider = document.createElement('hr');

                divider.className = 'dropdown-divider';
                dividerRow.appendChild(divider);
                menu.appendChild(dividerRow);
            }

            itemRow.appendChild(item);
            menu.appendChild(itemRow);
        });

        dropdown.appendChild(button);
        dropdown.appendChild(menu);
        mobileWrapper.appendChild(dropdown);

        return mobileWrapper;
    }

    function enhanceActionCell(cell) {
        if (!(cell instanceof HTMLElement) || cell.dataset.mobileActionsEnhanced === '1') {
            return;
        }

        if (cell.querySelector('.table-action-dropdown, .client-action-dropdown')) {
            cell.dataset.mobileActionsEnhanced = '1';
            return;
        }

        var actionNodes = collectActionNodes(cell);

        if (actionNodes.length === 0) {
            return;
        }

        var desktopWrapper = ensureDesktopWrapper(cell, actionNodes);
        var sources = [];

        flattenActionSources(desktopWrapper, sources);

        if (sources.length === 0) {
            return;
        }

        desktopWrapper.insertAdjacentElement('afterend', buildMobileDropdown(sources));
        cell.dataset.mobileActionsEnhanced = '1';
    }

    function enhanceWithin(root) {
        var tables = [];

        if (root instanceof HTMLTableElement) {
            tables = [root];
        } else if (root instanceof HTMLElement || root instanceof Document) {
            tables = Array.prototype.slice.call(root.querySelectorAll('table'));
        }

        tables.forEach(function (table) {
            var actionIndexes = getActionColumnIndexes(table);

            if (actionIndexes.length === 0) {
                return;
            }

            var bodyRows = table.querySelectorAll('tbody tr');

            Array.prototype.forEach.call(bodyRows, function (row) {
                actionIndexes.forEach(function (index) {
                    var cell = row.cells[index];

                    if (cell) {
                        enhanceActionCell(cell);
                    }
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        enhanceWithin(document);

        if (!window.MutationObserver) {
            return;
        }

        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.forEach.call(mutation.addedNodes, function (node) {
                    if (node instanceof HTMLElement) {
                        enhanceWithin(node);
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
}());
