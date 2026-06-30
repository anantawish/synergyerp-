(function () {
    const cfg = window.stock2Config || {};

    const sidebar = document.getElementById('sidebar');
    const toggleSidebarBtn = document.getElementById('toggleSidebar');
    const tableFilter = document.getElementById('tableFilter');

    if (toggleSidebarBtn && sidebar) {
        toggleSidebarBtn.addEventListener('click', function () {
            sidebar.classList.toggle('show');
        });
    }

    if (tableFilter) {
        tableFilter.addEventListener('input', function () {
            const keyword = tableFilter.value.trim().toLowerCase();
            document.querySelectorAll('.table-item').forEach(function (link) {
                const name = (link.dataset.tableName || '').toLowerCase();
                link.style.display = (keyword === '' || name.indexOf(keyword) >= 0) ? '' : 'none';
            });
        });
    }

    if (cfg.page !== 'module' || !cfg.module || cfg.moduleError) {
        return;
    }

    const moduleCfg = cfg.module;
    const mode = moduleCfg.mode || 'single';
    if (mode === 'placeholder') {
        return;
    }

    const apiBase = cfg.apiBase || 'api/table.php';
    const processApiBase = cfg.processApiBase || 'api/process.php';
    const reportBase = cfg.reportBase || 'report.php';
    const moduleKey = typeof moduleCfg.key === 'string' ? moduleCfg.key : '';

    const mainTable = moduleCfg.main_table;
    const mainSchema = Array.isArray(cfg.mainColumns) ? cfg.mainColumns : [];
    const mainPk = cfg.mainPrimaryKey;

    const detailTable = moduleCfg.detail_table || '';
    const detailSchema = Array.isArray(cfg.detailColumns) ? cfg.detailColumns : [];
    const detailPk = cfg.detailPrimaryKey;

    const sourceColumn = moduleCfg.detail_source_column || mainPk;
    const targetColumn = moduleCfg.detail_target_column || '';

    const isReadOnly = moduleCfg.read_only === true
        || moduleCfg.read_only === 1
        || moduleCfg.read_only === '1';

    const modulePermissions = (cfg.modulePermissions && typeof cfg.modulePermissions === 'object')
        ? cfg.modulePermissions
        : {};
    const canView = modulePermissions.view !== false;
    const canAdd = !isReadOnly && modulePermissions.add !== false;
    const canEdit = !isReadOnly && modulePermissions.edit !== false;
    const canDelete = !isReadOnly && modulePermissions.delete !== false;
    const canReport = modulePermissions.report !== false;

    const isProcessEnabled = !(moduleCfg.process_enabled === false
        || moduleCfg.process_enabled === 0
        || moduleCfg.process_enabled === '0');

    const customReportUrl = typeof moduleCfg.custom_report_url === 'string'
        ? moduleCfg.custom_report_url.trim()
        : '';

    const customReportRequiresId = customReportUrl.indexOf('{id}') >= 0;

    const mainModalEl = document.getElementById('mainModal');
    const detailModalEl = document.getElementById('detailModal');
    const mainForm = document.getElementById('mainForm');
    const detailForm = document.getElementById('detailForm');

    const mainModal = mainModalEl ? new bootstrap.Modal(mainModalEl) : null;
    const detailModal = detailModalEl ? new bootstrap.Modal(detailModalEl) : null;

    const btnAddMain = document.getElementById('btnAddMain');
    const btnSaveMain = document.getElementById('btnSaveMain');
    const btnAddDetail = document.getElementById('btnAddDetail');
    const btnSaveDetail = document.getElementById('btnSaveDetail');
    const btnQuickProcess = document.getElementById('btnQuickProcess');
    const btnReportList = document.getElementById('btnReportList');
    const btnReportSelected = document.getElementById('btnReportSelected');

    let selectedMainRow = null;
    let mainFormMode = 'create';
    let detailFormMode = 'create';

    const mainGrid = createGrid({
        tableId: '#mainGrid',
        tableName: mainTable,
        schema: mainSchema,
        primaryKey: mainPk,
        title: moduleCfg.title + ' - Main',
        filterGetter: function () { return null; },
        readOnly: isReadOnly,
        canEdit: canEdit,
        canDelete: canDelete
    });

    let detailGrid = null;
    if (mode === 'master_detail' && detailTable) {
        detailGrid = createGrid({
            tableId: '#detailGrid',
            tableName: detailTable,
            schema: detailSchema,
            primaryKey: detailPk,
            title: moduleCfg.title + ' - Detail',
            filterGetter: function () {
                if (!selectedMainRow) {
                    return null;
                }

                const value = selectedMainRow[sourceColumn];
                if (value == null || value === '') {
                    return null;
                }

                return {
                    filterColumn: targetColumn,
                    filterValue: String(value)
                };
            },
            readOnly: isReadOnly,
            canEdit: canEdit,
            canDelete: canDelete
        });
    }

    applyModeUi();
    bindToolbar();
    bindMainEvents();
    bindDetailEvents();
    loadSummary();
    updateDetailContext();
    updateReportButtons();

    function applyModeUi() {
        if (!canAdd) {
            btnAddMain?.classList.add('d-none');
            btnAddDetail?.classList.add('d-none');
        }

        if (!(canAdd || canEdit)) {
            btnSaveMain?.classList.add('d-none');
            btnSaveDetail?.classList.add('d-none');
        }

        if (!(mode === 'master_detail' && isProcessEnabled && canAdd)) {
            btnQuickProcess?.classList.add('d-none');
        }

        if (!canReport) {
            btnReportList?.classList.add('d-none');
            btnReportSelected?.classList.add('d-none');
        }
    }

    function bindToolbar() {
        document.getElementById('btnRefreshMain')?.addEventListener('click', function () {
            mainGrid.ajax.reload(null, false);
        });

        document.getElementById('btnSummary')?.addEventListener('click', function () {
            loadSummary();
        });

        if (canAdd) {
            btnAddMain?.addEventListener('click', function () {
                mainFormMode = 'create';
                if (document.getElementById('mainModalLabel')) {
                    document.getElementById('mainModalLabel').textContent = 'Add Main Record';
                }
                buildForm(mainForm, mainSchema, {}, {
                    mode: mainFormMode,
                    primaryKey: mainPk
                });
                mainModal?.show();
            });
        }

        if (canAdd || canEdit) {
            btnSaveMain?.addEventListener('click', async function () {
                if (mainFormMode === 'create' && !canAdd) {
                    showToast('No add permission for this module.', true);
                    return;
                }
                if (mainFormMode === 'edit' && !canEdit) {
                    showToast('No edit permission for this module.', true);
                    return;
                }

                const payload = collectFormData(mainForm);
                const result = await saveRecord(mainTable, payload);
                if (!result.ok) {
                    return;
                }

                mainModal?.hide();
                mainGrid.ajax.reload(null, false);
                loadSummary();
            });
        }

        btnReportList?.addEventListener('click', function () {
            if (!canReport) {
                showToast('No report permission for this module.', true);
                return;
            }

            if (customReportUrl !== '') {
                window.open(resolveCustomReportUrl(null), '_blank');
                return;
            }

            const url = reportBase + '?module=' + encodeURIComponent(moduleCfg.key) + '&mode=list';
            window.open(url, '_blank');
        });

        btnReportSelected?.addEventListener('click', function () {
            if (!canReport) {
                showToast('No report permission for this module.', true);
                return;
            }

            if (customReportUrl !== '') {
                if (customReportRequiresId && !selectedMainRow) {
                    showToast('Please select a main record first.', true);
                    return;
                }
                window.open(resolveCustomReportUrl(selectedMainRow), '_blank');
                return;
            }

            if (!selectedMainRow) {
                showToast('Please select a main record first.', true);
                return;
            }

            const id = selectedMainRow[mainPk];
            if (id == null || String(id) === '') {
                showToast('Selected row has no primary key.', true);
                return;
            }

            const url = reportBase + '?module=' + encodeURIComponent(moduleCfg.key)
                + '&id=' + encodeURIComponent(String(id));
            window.open(url, '_blank');
        });

        if (mode === 'master_detail' && isProcessEnabled && canAdd) {
            btnQuickProcess?.addEventListener('click', async function () {
                const ok = window.confirm('Create one mock document set for this module?');
                if (!ok) {
                    return;
                }

                try {
                    const response = await fetch(processApiBase + '?action=create', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            module_key: moduleCfg.key,
                            use_mock: true
                        })
                    });

                    const json = await response.json();
                    if (!response.ok || json.ok === false) {
                        throw new Error(json.error || 'Mock process failed');
                    }

                    selectedMainRow = null;
                    mainGrid.ajax.reload();
                    detailGrid?.ajax.reload();
                    loadSummary();
                    updateDetailContext();
                    updateReportButtons();
                    showToast('Mock process created: ' + (json.result.source_value || json.result.main_id));
                } catch (error) {
                    showToast(error.message || 'Mock process failed', true);
                }
            });
        }

        document.getElementById('btnRefreshDetail')?.addEventListener('click', function () {
            detailGrid?.ajax.reload(null, false);
        });

        if (canAdd) {
            btnAddDetail?.addEventListener('click', function () {
                if (!detailGrid || !selectedMainRow) {
                    showToast('Please select a main record first.', true);
                    return;
                }

                detailFormMode = 'create';
                if (document.getElementById('detailModalLabel')) {
                    document.getElementById('detailModalLabel').textContent = 'Add Detail Record';
                }

                const preload = {};
                preload[targetColumn] = selectedMainRow[sourceColumn];

                buildForm(detailForm, detailSchema, preload, {
                    mode: detailFormMode,
                    primaryKey: detailPk,
                    readonlyColumns: [targetColumn]
                });
                detailModal?.show();
            });
        }

        if (canAdd || canEdit) {
            btnSaveDetail?.addEventListener('click', async function () {
                if (!detailTable) {
                    return;
                }

                if (detailFormMode === 'create' && !canAdd) {
                    showToast('No add permission for this module.', true);
                    return;
                }
                if (detailFormMode === 'edit' && !canEdit) {
                    showToast('No edit permission for this module.', true);
                    return;
                }

                const payload = collectFormData(detailForm);
                if (selectedMainRow && targetColumn) {
                    payload[targetColumn] = selectedMainRow[sourceColumn];
                }

                const result = await saveRecord(detailTable, payload);
                if (!result.ok) {
                    return;
                }

                detailModal?.hide();
                detailGrid?.ajax.reload(null, false);
                loadSummary();
            });
        }
    }

    function bindMainEvents() {
        $('#mainGrid').on('click', 'tbody tr', function (ev) {
            if ((canEdit || canDelete) && $(ev.target).closest('.btn-edit,.btn-delete').length > 0) {
                return;
            }

            $('#mainGrid tbody tr').removeClass('row-selected');
            $(this).addClass('row-selected');

            selectedMainRow = mainGrid.row(this).data() || null;
            updateDetailContext();
            updateReportButtons();

            if (detailGrid) {
                detailGrid.ajax.reload();
            }
        });

        if (!(canEdit || canDelete)) {
            return;
        }

        if (canEdit) {
            $('#mainGrid').on('click', '.btn-edit', async function () {
                const id = this.getAttribute('data-id') || '';
                const row = await fetchRow(mainTable, id);
                if (!row) {
                    return;
                }

                mainFormMode = 'edit';
                if (document.getElementById('mainModalLabel')) {
                    document.getElementById('mainModalLabel').textContent = 'Edit Main Record #' + id;
                }
                buildForm(mainForm, mainSchema, row, {
                    mode: mainFormMode,
                    primaryKey: mainPk
                });
                mainModal?.show();
            });
        }

        if (canDelete) {
            $('#mainGrid').on('click', '.btn-delete', async function () {
                const id = this.getAttribute('data-id') || '';
                if (!window.confirm('Delete main record ' + id + ' ?')) {
                    return;
                }

                const result = await deleteRecord(mainTable, id);
                if (!result.ok) {
                    return;
                }

                if (selectedMainRow && String(selectedMainRow[mainPk]) === String(id)) {
                    selectedMainRow = null;
                    updateDetailContext();
                    updateReportButtons();
                }

                mainGrid.ajax.reload(null, false);
                detailGrid?.ajax.reload();
                loadSummary();
            });
        }
    }

    function bindDetailEvents() {
        if (!detailGrid || !(canEdit || canDelete)) {
            return;
        }

        if (canEdit) {
            $('#detailGrid').on('click', '.btn-edit', async function () {
                const id = this.getAttribute('data-id') || '';
                const row = await fetchRow(detailTable, id);
                if (!row) {
                    return;
                }

                detailFormMode = 'edit';
                if (document.getElementById('detailModalLabel')) {
                    document.getElementById('detailModalLabel').textContent = 'Edit Detail Record #' + id;
                }
                buildForm(detailForm, detailSchema, row, {
                    mode: detailFormMode,
                    primaryKey: detailPk,
                    readonlyColumns: targetColumn ? [targetColumn] : []
                });
                detailModal?.show();
            });
        }

        if (canDelete) {
            $('#detailGrid').on('click', '.btn-delete', async function () {
                const id = this.getAttribute('data-id') || '';
                if (!window.confirm('Delete detail record ' + id + ' ?')) {
                    return;
                }

                const result = await deleteRecord(detailTable, id);
                if (!result.ok) {
                    return;
                }

                detailGrid.ajax.reload(null, false);
                loadSummary();
            });
        }
    }

    function updateDetailContext() {
        const el = document.getElementById('detailContext');
        if (!el) {
            return;
        }

        if (!selectedMainRow) {
            el.textContent = 'No selected main record';
            return;
        }

        const value = selectedMainRow[sourceColumn] || selectedMainRow[mainPk] || '-';
        el.textContent = 'Selected: ' + value;
    }

    function updateReportButtons() {
        if (!btnReportSelected) {
            return;
        }

        if (!canReport) {
            btnReportSelected.disabled = true;
            return;
        }

        if (customReportUrl !== '') {
            btnReportSelected.disabled = customReportRequiresId && !selectedMainRow;
            return;
        }

        btnReportSelected.disabled = !selectedMainRow;
    }

    function resolveCustomReportUrl(row) {
        let url = customReportUrl;
        const idValue = row && row[mainPk] != null ? String(row[mainPk]) : '';

        url = url.replace('{module}', encodeURIComponent(moduleCfg.key || ''));
        url = url.replace('{id}', encodeURIComponent(idValue));

        const joiner = url.indexOf('?') >= 0 ? '&' : '?';
        if (idValue !== '' && url.indexOf('id=') < 0 && url.indexOf('{id}') < 0) {
            url += joiner + 'id=' + encodeURIComponent(idValue);
        }

        return url;
    }

    function withModuleKey(url) {
        if (!moduleKey) {
            return url;
        }
        const sep = url.indexOf('?') >= 0 ? '&' : '?';
        return url + sep + 'module_key=' + encodeURIComponent(moduleKey);
    }

    function createGrid(options) {
        const columns = (options.schema || []).map(function (column) {
            return {
                data: column.column_name,
                title: column.column_name,
                defaultContent: '',
                render: function (value) {
                    if (value == null) {
                        return '';
                    }
                    const text = String(value);
                    return text.length > 120 ? escHtml(text.slice(0, 120)) + '...' : escHtml(text);
                }
            };
        });

        if (options.canEdit || options.canDelete) {
            columns.push({
                data: null,
                title: 'Actions',
                orderable: false,
                searchable: false,
                className: 'text-nowrap',
                render: function (_, __, row) {
                    const id = escHtml(row[options.primaryKey]);
                    let actionHtml = '';
                    if (options.canEdit) {
                        actionHtml += '<button class="btn btn-sm btn-outline-primary me-1 btn-edit" data-id="' + id + '">Edit</button>';
                    }
                    if (options.canDelete) {
                        actionHtml += '<button class="btn btn-sm btn-outline-danger btn-delete" data-id="' + id + '">Delete</button>';
                    }
                    return actionHtml;
                }
            });
        }

        return $(options.tableId).DataTable({
            processing: true,
            serverSide: true,
            responsive: false,
            scrollX: true,
            ajax: {
                url: withModuleKey(apiBase + '?action=list&table=' + encodeURIComponent(options.tableName)),
                type: 'POST',
                data: function (d) {
                    const filter = options.filterGetter ? options.filterGetter() : null;
                    if (filter && filter.filterColumn && filter.filterValue != null) {
                        d.filter_column = filter.filterColumn;
                        d.filter_value = filter.filterValue;
                    }
                },
                error: function (xhr) {
                    let message = 'Load grid failed';
                    if (xhr && xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            if (parsed.error) {
                                message = parsed.error;
                            }
                        } catch (_e) {
                        }
                    }
                    showToast(message, true);
                }
            },
            columns: columns,
            order: [[0, 'desc']],
            pageLength: 25,
            dom: "<'row mb-2'<'col-md-8'B><'col-md-4'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
            buttons: [
                { extend: 'copy', className: 'btn btn-sm btn-outline-secondary' },
                { extend: 'csv', className: 'btn btn-sm btn-outline-secondary' },
                { extend: 'excel', className: 'btn btn-sm btn-outline-secondary' },
                {
                    extend: 'pdfHtml5',
                    className: 'btn btn-sm btn-outline-secondary',
                    title: options.title,
                    orientation: 'landscape',
                    pageSize: 'A4'
                },
                { extend: 'print', className: 'btn btn-sm btn-outline-secondary' }
            ]
        });
    }

    function buildForm(formEl, schema, row, options) {
        if (!formEl) {
            return;
        }

        const mode = options.mode || 'create';
        const pk = options.primaryKey || '';
        const readonlyColumns = Array.isArray(options.readonlyColumns) ? options.readonlyColumns : [];

        let html = '';
        schema.forEach(function (column) {
            const name = column.column_name;
            const dataType = (column.data_type || '').toLowerCase();
            const columnType = (column.column_type || '').toLowerCase();
            const extra = (column.extra || '').toLowerCase();
            const nullable = (column.is_nullable || 'YES') === 'YES';
            const isPrimary = (column.column_key || '') === 'PRI';
            const isAutoIncrement = extra.indexOf('auto_increment') >= 0;
            const value = row[name] == null ? '' : String(row[name]);

            const readOnly = readonlyColumns.indexOf(name) >= 0
                || (mode === 'create' && isAutoIncrement)
                || (mode === 'edit' && isPrimary)
                || (name === pk && mode === 'edit');

            const requiredAttr = nullable ? '' : 'required';
            const readOnlyAttr = readOnly ? 'readonly' : '';

            html += '<div class="col-md-6">';
            html += '<label class="form-label small mb-1">' + escHtml(name)
                + (isPrimary ? ' <span class="badge text-bg-warning">PK</span>' : '')
                + '</label>';

            if (isLongText(dataType, columnType)) {
                html += '<textarea class="form-control form-control-sm" rows="3" name="' + escHtml(name) + '" ' + requiredAttr + ' ' + readOnlyAttr + '>'
                    + escHtml(value) + '</textarea>';
            } else {
                html += '<input class="form-control form-control-sm" type="' + escHtml(resolveInputType(dataType))
                    + '" name="' + escHtml(name)
                    + '" value="' + escHtml(value)
                    + '" ' + requiredAttr + ' ' + readOnlyAttr + ' />';
            }

            html += '<div class="form-text">' + escHtml(dataType + (nullable ? ' | nullable' : ' | not null')) + '</div>';
            html += '</div>';
        });

        formEl.innerHTML = html;
    }

    function collectFormData(formEl) {
        const payload = {};
        if (!formEl) {
            return payload;
        }

        formEl.querySelectorAll('[name]').forEach(function (input) {
            payload[input.name] = input.value;
        });

        return payload;
    }

    async function fetchRow(table, id) {
        try {
            const response = await fetch(withModuleKey(apiBase + '?action=row&table=' + encodeURIComponent(table) + '&id=' + encodeURIComponent(id)));
            const json = await response.json();
            if (!response.ok || json.ok === false) {
                throw new Error(json.error || 'Load row failed');
            }

            return json.row;
        } catch (error) {
            showToast(error.message || 'Load row failed', true);
            return null;
        }
    }

    async function saveRecord(table, payload) {
        try {
            const response = await fetch(withModuleKey(apiBase + '?action=save&table=' + encodeURIComponent(table)), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const json = await response.json();
            if (!response.ok || json.ok === false) {
                throw new Error(json.error || 'Save failed');
            }

            showToast('Saved successfully');
            return { ok: true, result: json.result };
        } catch (error) {
            showToast(error.message || 'Save failed', true);
            return { ok: false };
        }
    }

    async function deleteRecord(table, id) {
        try {
            const response = await fetch(withModuleKey(apiBase + '?action=delete&table=' + encodeURIComponent(table)), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            });
            const json = await response.json();
            if (!response.ok || json.ok === false) {
                throw new Error(json.error || 'Delete failed');
            }

            showToast('Deleted successfully');
            return { ok: true };
        } catch (error) {
            showToast(error.message || 'Delete failed', true);
            return { ok: false };
        }
    }

    async function loadSummary() {
        const summaryCardsEl = document.getElementById('summaryCards');
        if (!summaryCardsEl) {
            return;
        }

        try {
            const response = await fetch(withModuleKey(apiBase + '?action=summary&table=' + encodeURIComponent(mainTable)));
            const json = await response.json();
            if (!response.ok || json.ok === false) {
                throw new Error(json.error || 'Summary failed');
            }

            const summary = json.summary || {};
            const cards = [];
            cards.push(cardHtml('Main Rows', summary.totalRows || 0));
            cards.push(cardHtml('Main Table', mainTable));
            cards.push(cardHtml('Form ID', moduleCfg.form_id || '-'));
            if (isReadOnly) {
                cards.push(cardHtml('Mode', 'Read Only'));
            }

            if (summary.sum) {
                Object.keys(summary.sum).slice(0, 3).forEach(function (key) {
                    cards.push(cardHtml('Sum ' + key, summary.sum[key]));
                });
            }

            if (mode === 'master_detail' && detailGrid) {
                const info = detailGrid.page.info();
                cards.push(cardHtml('Detail Rows (filtered)', info ? info.recordsDisplay : 0));
            }

            summaryCardsEl.innerHTML = cards.join('');
        } catch (error) {
            summaryCardsEl.innerHTML = '<div class="col-12"><div class="alert alert-warning py-2 mb-0">'
                + escHtml(error.message || 'Summary failed') + '</div></div>';
        }
    }

    function cardHtml(label, value) {
        return ''
            + '<div class="col-6 col-md-4 col-xl-3">'
            + '  <div class="card h-100">'
            + '    <div class="card-body">'
            + '      <div class="text-muted small">' + escHtml(label) + '</div>'
            + '      <div class="fs-5 fw-semibold text-truncate">' + escHtml(formatValue(value)) + '</div>'
            + '    </div>'
            + '  </div>'
            + '</div>';
    }

    function formatValue(value) {
        if (value == null || value === '') {
            return '-';
        }
        if (!isNaN(Number(value))) {
            return Number(value).toLocaleString();
        }
        return String(value);
    }

    function resolveInputType(dataType) {
        switch (dataType) {
            case 'date':
                return 'date';
            case 'datetime':
            case 'timestamp':
                return 'datetime-local';
            case 'time':
                return 'time';
            case 'int':
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'bigint':
            case 'decimal':
            case 'float':
            case 'double':
                return 'number';
            default:
                return 'text';
        }
    }

    function isLongText(dataType, columnType) {
        return dataType === 'text'
            || dataType === 'mediumtext'
            || dataType === 'longtext'
            || (columnType || '').indexOf('text') >= 0;
    }

    function escHtml(value) {
        if (value == null) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast(message, isError) {
        const klass = isError ? 'danger' : 'success';
        const html = ''
            + '<div class="toast align-items-center text-bg-' + klass + ' border-0" role="alert" aria-live="assertive" aria-atomic="true">'
            + '  <div class="d-flex">'
            + '    <div class="toast-body">' + escHtml(message) + '</div>'
            + '    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>'
            + '  </div>'
            + '</div>';

        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            document.body.appendChild(container);
        }

        container.insertAdjacentHTML('beforeend', html);
        const toastEl = container.lastElementChild;
        const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', function () {
            toastEl.remove();
        });
    }
})();



