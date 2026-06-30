(function () {
    const cfg = window.barcodeIoConfig || {};
    const apiBase = cfg.apiBase || 'api/barcode.php';
    const canAdd = !!cfg.canAdd;

    const els = {
        status: document.getElementById('scanStatus'),
        barcodeInput: document.getElementById('barcodeInput'),
        btnResolveBarcode: document.getElementById('btnResolveBarcode'),
        productSearchInput: document.getElementById('productSearchInput'),
        productSearchResults: document.getElementById('productSearchResults'),
        productCode: document.getElementById('productCode'),
        productName: document.getElementById('productName'),
        productRef: document.getElementById('productRef'),
        stockOverall: document.getElementById('stockOverall'),
        stockSlot: document.getElementById('stockSlot'),
        warehouseSelect: document.getElementById('warehouseSelect'),
        locationSelect: document.getElementById('locationSelect'),
        shelfSelect: document.getElementById('shelfSelect'),
        qtyInput: document.getElementById('qtyInput'),
        btnDirectionIn: document.getElementById('btnDirectionIn'),
        btnDirectionOut: document.getElementById('btnDirectionOut'),
        btnPostMove: document.getElementById('btnPostMove'),
        btnQuickIn: document.getElementById('btnQuickIn'),
        btnQuickOut: document.getElementById('btnQuickOut'),
        recentBody: document.getElementById('recentBody')
    };

    const state = {
        selectedProduct: null,
        direction: 'IN',
        searchTimer: null
    };

    setDirection('IN');
    if (!canAdd) {
        if (els.btnPostMove) {
            els.btnPostMove.disabled = true;
        }
        if (els.btnQuickIn) {
            els.btnQuickIn.disabled = true;
        }
        if (els.btnQuickOut) {
            els.btnQuickOut.disabled = true;
        }
    }

    boot();

    function boot() {
        bindEvents();
        loadOptions();
        loadRecent();
        setStatus('Ready for hardware barcode scanner');
        focusBarcodeInput();
    }

    function bindEvents() {
        els.btnResolveBarcode?.addEventListener('click', function () {
            resolveBarcode(els.barcodeInput?.value || '');
        });

        els.barcodeInput?.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === 'Tab') {
                ev.preventDefault();
                resolveBarcode(els.barcodeInput?.value || '');
                return;
            }

            if (ev.key === 'Escape') {
                els.barcodeInput.value = '';
            }
        });

        els.productSearchInput?.addEventListener('input', function () {
            if (state.searchTimer) {
                clearTimeout(state.searchTimer);
            }
            state.searchTimer = setTimeout(runProductSearch, 200);
        });

        els.warehouseSelect?.addEventListener('change', function () {
            loadOptions(els.warehouseSelect?.value || '', '');
        });

        els.locationSelect?.addEventListener('change', function () {
            loadOptions(els.warehouseSelect?.value || '', els.locationSelect?.value || '');
        });

        els.shelfSelect?.addEventListener('change', function () {
            refreshStock();
        });

        els.btnDirectionIn?.addEventListener('click', function () { setDirection('IN'); });
        els.btnDirectionOut?.addEventListener('click', function () { setDirection('OUT'); });

        els.btnPostMove?.addEventListener('click', function () {
            submitMove(state.direction);
        });
        els.btnQuickIn?.addEventListener('click', function () {
            submitMove('IN');
        });
        els.btnQuickOut?.addEventListener('click', function () {
            submitMove('OUT');
        });
    }

    function focusBarcodeInput() {
        if (!els.barcodeInput) {
            return;
        }
        setTimeout(function () {
            els.barcodeInput.focus();
            els.barcodeInput.select();
        }, 20);
    }

    function setDirection(direction) {
        state.direction = direction === 'OUT' ? 'OUT' : 'IN';
        if (els.btnDirectionIn) {
            els.btnDirectionIn.classList.toggle('active', state.direction === 'IN');
        }
        if (els.btnDirectionOut) {
            els.btnDirectionOut.classList.toggle('active', state.direction === 'OUT');
        }
    }

    async function loadOptions(warehouseCode, locationCode) {
        try {
            const query = {
                warehouse_code: warehouseCode || (els.warehouseSelect?.value || ''),
                location_code: locationCode || (els.locationSelect?.value || '')
            };
            const res = await apiGet('options', query);
            const result = res.result || {};

            bindSelect(els.warehouseSelect, result.warehouses || [], 'warehouse_code', 'warehouse_name', result.selected?.warehouse_code || '');
            bindSelect(els.locationSelect, result.locations || [], 'location_code', 'location_name', result.selected?.location_code || '');
            bindSelect(els.shelfSelect, result.shelves || [], 'shelf_code', 'shelf_name', result.selected?.shelf_code || '');
            refreshStock();
            focusBarcodeInput();
        } catch (error) {
            toast(error.message || 'load options failed', true);
        }
    }

    function bindSelect(select, rows, keyField, textField, selectedValue) {
        if (!select) {
            return;
        }

        const items = Array.isArray(rows) ? rows : [];
        const html = items.map(function (row) {
            const key = esc(row[keyField]);
            const text = esc(row[textField] || row[keyField]);
            return '<option value="' + key + '">' + text + '</option>';
        }).join('');
        select.innerHTML = html;

        if (selectedValue) {
            select.value = selectedValue;
        }
    }

    async function runProductSearch() {
        const keyword = (els.productSearchInput?.value || '').trim();
        if (keyword === '') {
            if (els.productSearchResults) {
                els.productSearchResults.innerHTML = '';
            }
            return;
        }

        try {
            const res = await apiGet('search_product', { q: keyword, limit: 30 });
            const rows = Array.isArray(res.rows) ? res.rows : [];
            if (!els.productSearchResults) {
                return;
            }

            els.productSearchResults.innerHTML = rows.map(function (row) {
                const title = esc(row.product_code || '-') + ' | ' + esc(row.product_name || row.product_shotname || '');
                const sub = esc(row.reference_code || row.product_auto_code || '');
                return ''
                    + '<div class="search-item" data-product-id="' + esc(row.id) + '">'
                    + '  <div class="fw-semibold">' + title + '</div>'
                    + '  <small class="text-muted">' + sub + '</small>'
                    + '</div>';
            }).join('');

            els.productSearchResults.querySelectorAll('.search-item').forEach(function (item) {
                item.addEventListener('click', async function () {
                    const productId = Number(item.getAttribute('data-product-id') || 0);
                    if (productId > 0) {
                        await selectProductById(productId);
                    }
                });
            });
        } catch (error) {
            toast(error.message || 'search failed', true);
        }
    }

    async function selectProductById(productId) {
        const res = await apiGet('resolve_product', {
            product_id: productId,
            warehouse_code: els.warehouseSelect?.value || '',
            location_code: els.locationSelect?.value || '',
            shelf_code: els.shelfSelect?.value || ''
        });
        applyProductResult(res.result || null);
    }

    async function resolveBarcode(code) {
        const barcode = String(code || '').trim();
        if (barcode === '') {
            toast('Please scan barcode first', true);
            focusBarcodeInput();
            return;
        }

        try {
            const res = await apiGet('resolve_product', {
                code: barcode,
                warehouse_code: els.warehouseSelect?.value || '',
                location_code: els.locationSelect?.value || '',
                shelf_code: els.shelfSelect?.value || ''
            });
            applyProductResult(res.result || null);
            setStatus('Product found: ' + (res.result?.product?.product_code || barcode));
            beep();
            focusBarcodeInput();
        } catch (error) {
            setStatus('Barcode not found: ' + barcode, true);
            toast(error.message || 'barcode not found', true);
            focusBarcodeInput();
        }
    }

    function applyProductResult(result) {
        if (!result || !result.product) {
            return;
        }

        state.selectedProduct = result.product;
        if (els.productCode) {
            els.productCode.textContent = result.product.product_code || '-';
        }
        if (els.productName) {
            els.productName.textContent = result.product.product_name || result.product.product_shotname || '-';
        }
        if (els.productRef) {
            els.productRef.textContent = result.product.reference_code || result.product.product_auto_code || '-';
        }

        if (els.productSearchResults) {
            els.productSearchResults.innerHTML = '';
        }

        const stock = result.stock || {};
        setStockLabels(stock.overall_qty, stock.slot_qty);
    }

    async function refreshStock() {
        if (!state.selectedProduct || !state.selectedProduct.id) {
            setStockLabels(0, 0);
            return;
        }

        try {
            const res = await apiGet('stock', {
                product_id: state.selectedProduct.id,
                warehouse_code: els.warehouseSelect?.value || '',
                location_code: els.locationSelect?.value || '',
                shelf_code: els.shelfSelect?.value || ''
            });
            setStockLabels(res.result?.overall_qty || 0, res.result?.slot_qty || 0);
        } catch (error) {
            toast(error.message || 'stock refresh failed', true);
        }
    }

    function setStockLabels(overall, slot) {
        if (els.stockOverall) {
            els.stockOverall.textContent = Number(overall || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
        }
        if (els.stockSlot) {
            els.stockSlot.textContent = Number(slot || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
        }
    }

    async function submitMove(direction) {
        if (!canAdd) {
            toast('No permission to post movement', true);
            return;
        }
        if (!state.selectedProduct || !state.selectedProduct.id) {
            toast('Please scan or select product first', true);
            focusBarcodeInput();
            return;
        }

        const qty = Number(els.qtyInput?.value || 0);
        if (!qty || qty <= 0) {
            toast('Quantity must be greater than 0', true);
            focusBarcodeInput();
            return;
        }

        const payload = {
            product_id: state.selectedProduct.id,
            barcode: els.barcodeInput?.value || '',
            direction: direction,
            qty: qty,
            warehouse_code: els.warehouseSelect?.value || '',
            location_code: els.locationSelect?.value || '',
            shelf_code: els.shelfSelect?.value || ''
        };

        try {
            const res = await apiPost('move', payload);
            const result = res.result || {};
            setStockLabels(result.stock?.overall_qty || 0, result.stock?.slot_qty || 0);
            prependRecentRow(result);
            toast('Posted ' + direction + ': ' + (result.movement?.bill_id || ''), false);
            setStatus('Posted: ' + (result.movement?.bill_id || ''));
            beep();
            if (els.barcodeInput) {
                els.barcodeInput.value = '';
            }
            focusBarcodeInput();
        } catch (error) {
            toast(error.message || 'post movement failed', true);
            setStatus(error.message || 'post movement failed', true);
            focusBarcodeInput();
        }
    }

    function prependRecentRow(result) {
        if (!els.recentBody) {
            return;
        }

        const m = result.movement || {};
        const p = result.product || {};
        const row = document.createElement('tr');
        row.innerHTML = ''
            + '<td>' + esc(m.trans_date || '') + '</td>'
            + '<td><span class="badge ' + ((m.direction === 'OUT') ? 'text-bg-danger' : 'text-bg-success') + '">' + esc(m.direction || '') + '</span></td>'
            + '<td>' + esc(p.product_code || '') + '</td>'
            + '<td>' + esc(p.product_name || '') + '</td>'
            + '<td class="text-end">' + Number(m.qty || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 }) + '</td>'
            + '<td>' + esc((m.warehouse_code || '') + '/' + (m.location_code || '') + '/' + (m.shelf_code || '')) + '</td>'
            + '<td>' + esc(m.bill_id || '') + '</td>';
        els.recentBody.prepend(row);

        while (els.recentBody.children.length > 80) {
            els.recentBody.removeChild(els.recentBody.lastElementChild);
        }
    }

    async function loadRecent() {
        try {
            const res = await apiGet('recent', { limit: 50 });
            const rows = Array.isArray(res.rows) ? res.rows : [];
            if (!els.recentBody) {
                return;
            }

            els.recentBody.innerHTML = rows.map(function (row) {
                const direction = String(row.trans_item || 0) < 0 ? 'OUT' : 'IN';
                const qty = Math.abs(Number(row.trans_item || 0));
                return ''
                    + '<tr>'
                    + '<td>' + esc(row.trans_date || '') + '</td>'
                    + '<td><span class="badge ' + (direction === 'OUT' ? 'text-bg-danger' : 'text-bg-success') + '">' + direction + '</span></td>'
                    + '<td>' + esc(row.product_code || '') + '</td>'
                    + '<td>' + esc(row.product_name || '') + '</td>'
                    + '<td class="text-end">' + qty.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 }) + '</td>'
                    + '<td>' + esc((row.warehouse_code || '') + '/' + (row.location_code || '') + '/' + (row.shelf_code || '')) + '</td>'
                    + '<td>' + esc(row.bill_id || '') + '</td>'
                    + '</tr>';
            }).join('');
        } catch (error) {
            toast(error.message || 'load recent failed', true);
        }
    }

    async function apiGet(action, params) {
        const query = new URLSearchParams();
        query.set('action', action);
        Object.keys(params || {}).forEach(function (key) {
            const value = params[key];
            if (value == null || String(value).trim() === '') {
                return;
            }
            query.set(key, String(value));
        });

        const res = await fetch(apiBase + '?' + query.toString(), {
            method: 'GET',
            credentials: 'same-origin'
        });
        return parseApiResponse(res);
    }

    async function apiPost(action, payload) {
        const query = new URLSearchParams();
        query.set('action', action);
        const res = await fetch(apiBase + '?' + query.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {})
        });
        return parseApiResponse(res);
    }

    async function parseApiResponse(res) {
        let json = null;
        try {
            json = await res.json();
        } catch (_e) {
            throw new Error('invalid response');
        }

        if (!res.ok || !json || json.ok !== true) {
            throw new Error((json && json.error) ? String(json.error) : 'request failed');
        }

        return json;
    }

    function setStatus(text, isError) {
        if (!els.status) {
            return;
        }

        els.status.className = 'small bio-status ' + (isError ? 'text-danger' : 'text-muted');
        els.status.textContent = text || '';
    }

    function toast(message, isError) {
        const klass = isError ? 'danger' : 'success';
        const html = ''
            + '<div class="toast align-items-center text-bg-' + klass + ' border-0" role="alert" aria-live="assertive" aria-atomic="true">'
            + '  <div class="d-flex">'
            + '    <div class="toast-body">' + esc(message) + '</div>'
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
        const toast = new bootstrap.Toast(toastEl, { delay: 2800 });
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', function () {
            toastEl.remove();
        });
    }

    function beep() {
        try {
            const context = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.value = 0.05;
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start();
            oscillator.stop(context.currentTime + 0.08);
        } catch (_e) {
            // no-op
        }
    }

    function esc(value) {
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
})();
