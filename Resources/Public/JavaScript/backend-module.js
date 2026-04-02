(function () {
    const root = document.querySelector('[data-seo-module]');
    if (!root) {
        return;
    }

    const moduleType = root.dataset.seoModule || '';
    const manualStartKey = moduleType === 'show'
        ? 'aistea-seo-manual-start-' + (root.dataset.reportUid || '')
        : 'aistea-seo-manual-start-index';

    function confirmMessageForButton(button) {
        return button.getAttribute('data-confirm') || button.getAttribute('data-msg') || '';
    }

    function bindConfirmButtons() {
        document.querySelectorAll('.js-confirm, .seo-confirm').forEach(function (button) {
            if (button.classList.contains('js-start-now') || button.dataset.confirmBound === '1') {
                return;
            }

            button.dataset.confirmBound = '1';
            button.addEventListener('click', function (event) {
                const message = confirmMessageForButton(button);
                if (message !== '' && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    function persistManualStart(payload) {
        try {
            sessionStorage.setItem(manualStartKey, JSON.stringify(payload));
        } catch (error) {
        }
    }

    function readManualStart() {
        try {
            return JSON.parse(sessionStorage.getItem(manualStartKey) || 'null');
        } catch (error) {
            sessionStorage.removeItem(manualStartKey);
            return null;
        }
    }

    function clearManualStart() {
        try {
            sessionStorage.removeItem(manualStartKey);
        } catch (error) {
        }
    }

    function escapeAttributeValue(value) {
        return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }

    function setManualStartUi(button) {
        const label = button.getAttribute('data-submit-label') || 'Starting...';
        button.disabled = true;
        button.innerHTML = moduleType === 'show'
            ? '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + label
            : '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';

        document.body.style.cursor = 'progress';

        document.querySelectorAll('.js-confirm, .seo-confirm, .js-start-now').forEach(function (otherButton) {
            if (otherButton !== button) {
                otherButton.disabled = true;
            }
        });

        const feedback = document.getElementById('seo-start-now-feedback');
        if (feedback) {
            feedback.classList.remove('d-none');
            const message = feedback.querySelector('span:last-child');
            if (message) {
                message.textContent = moduleType === 'show'
                    ? 'Manual analysis start was triggered. Redirecting to the overview to monitor status...'
                    : 'Manual analysis start was triggered. Refreshing module status...';
            }
        }
    }

    function submitManualStart(button) {
        const form = button.form;
        if (!form) {
            return;
        }

        setManualStartUi(button);
        persistManualStart({
            reportUid: button.dataset.reportUid || root.dataset.reportUid || '',
            startedAt: Date.now(),
            moduleType: moduleType
        });

        if (window.fetch) {
            window.fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            }).catch(function () {
            });
        } else {
            form.submit();
            return;
        }

        window.setTimeout(function () {
            if (moduleType === 'show' && root.dataset.indexUrl) {
                window.location.href = root.dataset.indexUrl;
                return;
            }

            window.location.reload();
        }, 1200);
    }

    function bindManualStartButtons() {
        document.querySelectorAll('.js-start-now').forEach(function (button) {
            if (button.dataset.startBound === '1') {
                return;
            }

            button.dataset.startBound = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();

                const message = confirmMessageForButton(button);
                if (message !== '' && !window.confirm(message)) {
                    return;
                }

                submitManualStart(button);
            });
        });
    }

    function handleManualStartState() {
        const payload = readManualStart();
        if (!payload) {
            return;
        }

        if (moduleType === 'show') {
            const feedback = document.getElementById('seo-start-now-feedback');
            const reportStatus = Number(root.dataset.reportStatus || '0');

            if (feedback && (reportStatus === 4 || reportStatus === 1)) {
                feedback.classList.remove('d-none');
                const message = feedback.querySelector('span:last-child');
                if (message) {
                    message.textContent = reportStatus === 1
                        ? 'Analysis is running now. The module will refresh automatically until it finishes.'
                        : 'Manual analysis start was triggered. Refreshing module status...';
                }

                window.setTimeout(function () {
                    window.location.reload();
                }, reportStatus === 1 ? 3000 : 1500);
                return;
            }

            clearManualStart();
            return;
        }

        const reportUid = payload.reportUid || '';
        const reportRow = reportUid !== ''
            ? document.querySelector('tr[data-report-uid="' + escapeAttributeValue(reportUid) + '"]')
            : null;

        if (!reportRow) {
            clearManualStart();
            return;
        }

        const reportStatus = Number(reportRow.dataset.reportStatus || '0');
        if (reportStatus === 4 || reportStatus === 1) {
            window.setTimeout(function () {
                window.location.reload();
            }, reportStatus === 1 ? 3000 : 1500);
            return;
        }

        clearManualStart();
    }

    function bindShowPageInteractions() {
        const table = document.getElementById('seo-pages-table');
        if (!table) {
            return;
        }

        const storageKey = 'aistea-seo-report-filters-' + (root.dataset.reportUid || '');
        const searchInput = document.getElementById('seo-filter-search');
        const typeSelect = document.getElementById('seo-filter-type');
        const severitySelect = document.getElementById('seo-filter-severity');
        const issueTypeInput = document.getElementById('seo-filter-issue-type');
        const resetButton = document.getElementById('seo-filter-reset');
        const issueSummaryRows = Array.from(document.querySelectorAll('.seo-issue-summary-row'));
        const rows = Array.from(table.querySelectorAll('tbody tr.seo-page-row'));
        const tbody = table.querySelector('tbody');
        const sortButtons = Array.from(table.querySelectorAll('.seo-sort'));

        if (!searchInput || !typeSelect || !severitySelect || !issueTypeInput || !resetButton || !tbody) {
            return;
        }

        function matchesSeverity(row, severity) {
            if (!severity) {
                return true;
            }
            return Number(row.dataset[severity + 'Count'] || '0') > 0;
        }

        function matchesIssueType(row, issueType) {
            if (!issueType) {
                return true;
            }

            try {
                const issues = JSON.parse(row.dataset.issuesJson || '[]');
                return issues.some(function (issue) {
                    return String(issue.type || '').toLowerCase().includes(issueType);
                });
            } catch (error) {
                return false;
            }
        }

        function saveFilters() {
            const payload = {
                search: searchInput.value || '',
                type: typeSelect.value || '',
                severity: severitySelect.value || '',
                issueType: issueTypeInput.value || ''
            };
            localStorage.setItem(storageKey, JSON.stringify(payload));
        }

        function loadFilters() {
            try {
                const payload = JSON.parse(localStorage.getItem(storageKey) || '{}');
                searchInput.value = payload.search || '';
                typeSelect.value = payload.type || '';
                severitySelect.value = payload.severity || '';
                issueTypeInput.value = payload.issueType || '';
            } catch (error) {
                localStorage.removeItem(storageKey);
            }
        }

        function applyFilters() {
            const search = (searchInput.value || '').trim().toLowerCase();
            const type = typeSelect.value;
            const severity = severitySelect.value;
            const issueType = (issueTypeInput.value || '').trim().toLowerCase();

            rows.forEach(function (row) {
                const text = (row.dataset.search || '').toLowerCase();
                const visible = (search === '' || text.includes(search))
                    && (type === '' || row.dataset.pageType === type)
                    && matchesSeverity(row, severity)
                    && matchesIssueType(row, issueType);

                row.style.display = visible ? '' : 'none';

                const detailRow = document.getElementById(row.dataset.detailId || '');
                if (detailRow && !visible) {
                    detailRow.classList.remove('show');
                    detailRow.style.display = 'none';
                } else if (detailRow) {
                    detailRow.style.display = '';
                }
            });

            saveFilters();
        }

        loadFilters();
        applyFilters();

        searchInput.addEventListener('input', applyFilters);
        typeSelect.addEventListener('change', applyFilters);
        severitySelect.addEventListener('change', applyFilters);
        issueTypeInput.addEventListener('input', applyFilters);
        resetButton.addEventListener('click', function () {
            searchInput.value = '';
            typeSelect.value = '';
            severitySelect.value = '';
            issueTypeInput.value = '';
            applyFilters();
        });

        issueSummaryRows.forEach(function (row) {
            row.addEventListener('click', function () {
                issueTypeInput.value = row.dataset.issueType || '';
                applyFilters();
                table.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        });

        let currentKey = '';
        let currentDirection = 'asc';

        sortButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const key = button.dataset.sortKey || '';
                if (!key) {
                    return;
                }

                currentDirection = currentKey === key && currentDirection === 'asc' ? 'desc' : 'asc';
                currentKey = key;

                const sortableRows = Array.from(tbody.querySelectorAll('tr.seo-page-row'));
                sortableRows.sort(function (a, b) {
                    const dataKey = 'sort' + key.charAt(0).toUpperCase() + key.slice(1);
                    const aValue = a.dataset[dataKey] || '';
                    const bValue = b.dataset[dataKey] || '';
                    const aNumber = Number(aValue);
                    const bNumber = Number(bValue);
                    const bothNumeric = !Number.isNaN(aNumber) && !Number.isNaN(bNumber) && aValue !== '' && bValue !== '';

                    let comparison = 0;
                    if (bothNumeric) {
                        comparison = aNumber - bNumber;
                    } else {
                        comparison = aValue.localeCompare(bValue, undefined, {sensitivity: 'base'});
                    }

                    return currentDirection === 'asc' ? comparison : -comparison;
                });

                sortableRows.forEach(function (row) {
                    tbody.appendChild(row);
                    const detailId = row.dataset.detailId || '';
                    const detailRow = detailId ? document.getElementById(detailId) : null;
                    if (detailRow) {
                        tbody.appendChild(detailRow);
                    }
                });

                sortButtons.forEach(function (item) {
                    item.classList.remove('text-primary');
                });
                button.classList.add('text-primary');
            });
        });
    }

    bindConfirmButtons();
    bindManualStartButtons();
    handleManualStartState();

    if (moduleType === 'show') {
        bindShowPageInteractions();
    }
})();
