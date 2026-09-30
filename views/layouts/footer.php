        </main>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3 app-toast-container">
    <?php
    $toastMessages = $toastMessages ?? [];
    if (!empty($_SESSION['flash_success'])) {
        $toastMessages[] = ['type' => 'success', 'message' => (string) $_SESSION['flash_success']];
        unset($_SESSION['flash_success']);
    }
    if (!empty($_SESSION['flash_error'])) {
        $toastMessages[] = ['type' => 'danger', 'message' => (string) $_SESSION['flash_error']];
        unset($_SESSION['flash_error']);
    }
    ?>
    <?php foreach ($toastMessages as $index => $toast): ?>
        <div class="toast app-toast border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="3600">
            <div class="toast-header text-bg-<?= htmlspecialchars($toast['type'] ?? 'primary') ?>">
                <i class="fa-solid fa-circle-info me-2"></i>
                <strong class="me-auto">ລະບົບລາພັກ</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="ປິດ"></button>
            </div>
            <div class="toast-body"><?= htmlspecialchars((string) ($toast['message'] ?? 'ພ້ອມໃຊ້ງານ')) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<?php if (!empty($useDataTables)): ?>
    <script src="https://cdn.datatables.net/2.3.8/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/3.0.8/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/3.0.8/js/responsive.bootstrap5.min.js"></script>
<?php endif; ?>
<script>
    const sidebar = document.getElementById('appSidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const sidebarScrollContext = new URLSearchParams(window.location.search).get('context')
        || sidebar?.dataset.sidebarRole
        || 'default';
    const sidebarScrollKey = `leave-management-sidebar-scroll:${sidebarScrollContext}`;

    if (sidebar) {
        try {
            const sidebarNav = sidebar.querySelector('.sidebar-nav');
            const restoreSidebarScroll = () => {
                const savedSidebarScroll = sessionStorage.getItem(sidebarScrollKey);
                if (savedSidebarScroll !== null) {
                    sidebarNav.scrollTop = Number(savedSidebarScroll) || 0;
                }
            };

            requestAnimationFrame(restoreSidebarScroll);
            window.addEventListener('load', restoreSidebarScroll, { once: true });
            sidebarNav.addEventListener('scroll', (event) => {
                sessionStorage.setItem(sidebarScrollKey, String(event.currentTarget.scrollTop));
            }, { passive: true });
        } catch (_) {
            // Keeping the Sidebar position is an enhancement; navigation still works if storage is unavailable.
        }
    }

    function closeSidebar() {
        document.body.classList.remove('sidebar-open');
    }

    sidebarToggle?.addEventListener('click', () => {
        document.body.classList.toggle('sidebar-open');
    });

    sidebarBackdrop?.addEventListener('click', closeSidebar);

    const activeRoleContext = new URLSearchParams(window.location.search).get('context');
    const supportedRoleContexts = new Set(['admin', 'hr', 'manager', 'employee']);

    if (supportedRoleContexts.has(activeRoleContext)) {
        const keepRoleContext = (value) => {
            const url = new URL(value || window.location.href, window.location.href);
            if (url.origin !== window.location.origin) {
                return null;
            }
            url.searchParams.set('context', activeRoleContext);
            return url;
        };

        document.querySelectorAll('a[href]').forEach((link) => {
            const href = link.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:')) {
                return;
            }
            const url = keepRoleContext(href);
            if (url) {
                link.href = url.toString();
            }
        });

        document.querySelectorAll('form').forEach((form) => {
            const url = keepRoleContext(form.getAttribute('action'));
            if (url) {
                form.action = url.toString();
            }
        });
    }

    document.querySelectorAll('.sidebar-link').forEach((link) => {
        link.addEventListener('click', () => {
            try {
                const sidebarNav = sidebar?.querySelector('.sidebar-nav');
                if (sidebarNav) {
                    sessionStorage.setItem(sidebarScrollKey, String(sidebarNav.scrollTop));
                }
            } catch (_) {
                // Ignore browser storage restrictions.
            }
            if (window.innerWidth < 992) {
                closeSidebar();
            }
        });
    });

    const attachmentPreviewLinks = document.querySelectorAll('.js-attachment-preview');
    if (attachmentPreviewLinks.length && window.bootstrap?.Modal) {
        const modalElement = document.createElement('div');
        modalElement.className = 'modal fade attachment-preview-modal';
        modalElement.tabIndex = -1;
        modalElement.setAttribute('aria-hidden', 'true');
        modalElement.innerHTML = `
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title"><i class="fa-solid fa-paperclip me-2 text-primary"></i>ເບິ່ງໄຟລ໌ແນບ</h5>
                            <div class="small text-secondary" data-preview-request></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                    </div>
                    <div class="modal-body p-0">
                        <iframe class="attachment-preview-frame" title="ໄຟລ໌ແນບ"></iframe>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">ປິດ</button>
                        <a class="btn btn-primary" data-preview-download href="#"><i class="fa-solid fa-download me-2"></i>ບັນທຶກໄຟລ໌</a>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(modalElement);

        const previewModal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const frame = modalElement.querySelector('.attachment-preview-frame');
        const requestLabel = modalElement.querySelector('[data-preview-request]');
        const downloadLink = modalElement.querySelector('[data-preview-download]');

        attachmentPreviewLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                const fileUrl = new URL(link.href, window.location.href);
                const rawUrl = new URL(fileUrl);
                rawUrl.searchParams.set('raw', '1');
                const downloadUrl = new URL(fileUrl);
                downloadUrl.searchParams.delete('raw');
                downloadUrl.searchParams.set('download', '1');

                frame.src = rawUrl.toString();
                requestLabel.textContent = link.dataset.attachmentTitle || '';
                downloadLink.href = downloadUrl.toString();
                previewModal.show();
            });
        });

        modalElement.addEventListener('hidden.bs.modal', () => {
            frame.src = 'about:blank';
        });
    }

    const printPreviewLinks = document.querySelectorAll('.js-print-preview');
    if (printPreviewLinks.length && window.bootstrap?.Modal) {
        const modalElement = document.createElement('div');
        modalElement.className = 'modal fade print-preview-modal';
        modalElement.tabIndex = -1;
        modalElement.setAttribute('aria-hidden', 'true');
        modalElement.innerHTML = `
            <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-lg-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title"><i class="fa-solid fa-print me-2 text-primary"></i>ຕົວຢ່າງກ່ອນພິມ</h5>
                            <div class="small text-secondary" data-print-request></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ປິດ"></button>
                    </div>
                    <div class="modal-body p-0"><iframe class="print-preview-frame" title="ຕົວຢ່າງໃບລາ"></iframe></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">ປິດ</button>
                        <button type="button" class="btn btn-primary" data-print-document><i class="fa-solid fa-print me-2"></i>ພິມໃບລາ</button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(modalElement);

        const printModal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const printFrame = modalElement.querySelector('.print-preview-frame');
        const printLabel = modalElement.querySelector('[data-print-request]');
        const printButton = modalElement.querySelector('[data-print-document]');

        printPreviewLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                const printUrl = new URL(link.href, window.location.href);
                printUrl.searchParams.set('embed', '1');
                printFrame.src = printUrl.toString();
                printLabel.textContent = link.dataset.printTitle || '';
                printModal.show();
            });
        });

        printButton.addEventListener('click', () => {
            printFrame.contentWindow?.focus();
            printFrame.contentWindow?.print();
        });

        modalElement.addEventListener('hidden.bs.modal', () => {
            printFrame.src = 'about:blank';
        });
    }

    if (window.bootstrap?.Toast) {
        document.querySelectorAll('.toast').forEach((toastElement) => {
            bootstrap.Toast.getOrCreateInstance(toastElement).show();
        });
    }

    function initDataTables() {
        if (typeof window.DataTable === 'undefined') {
            return;
        }

        document.querySelectorAll('table[data-datatable="true"]').forEach((table) => {
            if (table.dataset.datatableInitialized === 'true') {
                return;
            }

            new DataTable(table, {
                pageLength: Number(table.dataset.pageSize || 10),
                lengthMenu: [5, 10, 25, 50],
                paging: table.dataset.pagination !== 'off',
                searching: table.dataset.search !== 'off',
                info: table.dataset.info !== 'off',
                order: [],
                autoWidth: false,
                language: {
                    search: 'ຄົ້ນຫາ:',
                    searchPlaceholder: table.dataset.searchPlaceholder || 'ຄົ້ນຫາຂໍ້ມູນໃນຕາຕະລາງ',
                    lengthMenu: 'ສະແດງ _MENU_ ລາຍການ',
                    info: 'ສະແດງ _START_ ເຖິງ _END_ ຈາກ _TOTAL_ ລາຍການ',
                    infoEmpty: 'ສະແດງ 0 ເຖິງ 0 ຈາກ 0 ລາຍການ',
                    infoFiltered: '(ກອງຈາກທັງໝົດ _MAX_ ລາຍການ)',
                    zeroRecords: table.dataset.zeroRecords || 'ບໍ່ພົບຂໍ້ມູນທີ່ຄົ້ນຫາ',
                    emptyTable: table.dataset.emptyMessage || 'ຍັງບໍ່ມີຂໍ້ມູນ',
                    paginate: {
                        first: 'ໜ້າແລກ',
                        previous: 'ກ່ອນໜ້າ',
                        next: 'ຖັດໄປ',
                        last: 'ໜ້າສຸດທ້າຍ'
                    }
                }
            });

            table.dataset.datatableInitialized = 'true';
        });
    }

    initDataTables();

    document.querySelectorAll('table.table').forEach((table, index) => {
        if (table.dataset.datatable === 'true') {
            return;
        }

        if (table.dataset.pagination === 'off') {
            return;
        }

        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody?.querySelectorAll('tr') || []);
        const dataRows = rows.filter((row) => row.querySelectorAll('td').length > 1);
        const pageSize = Number(table.dataset.pageSize || 8);

        if (dataRows.length <= pageSize) {
            return;
        }

        let currentPage = 1;
        const totalPages = Math.ceil(dataRows.length / pageSize);
        const pagination = document.createElement('div');
        pagination.className = 'table-pagination';

        function renderPage() {
            dataRows.forEach((row, rowIndex) => {
                const start = (currentPage - 1) * pageSize;
                const end = start + pageSize;
                row.classList.toggle('d-none', rowIndex < start || rowIndex >= end);
            });

            pagination.innerHTML = `
                <span>ໜ້າ ${currentPage} ຈາກ ${totalPages}</span>
                <div class="btn-group">
                    <button class="btn btn-sm btn-outline-primary" type="button" data-page="prev" ${currentPage === 1 ? 'disabled' : ''}>
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-primary" type="button" data-page="next" ${currentPage === totalPages ? 'disabled' : ''}>
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            `;
        }

        pagination.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-page]');
            if (!button) {
                return;
            }
            currentPage += button.dataset.page === 'next' ? 1 : -1;
            currentPage = Math.max(1, Math.min(totalPages, currentPage));
            renderPage();
        });

        table.closest('.table-responsive')?.after(pagination);
        renderPage();
    });

    if (window.Chart) {
        const appFontFamily = getComputedStyle(document.documentElement)
            .getPropertyValue('--font-family-base')
            .trim() || '"Noto Sans Lao", "Phetsarath OT", sans-serif';
        Chart.defaults.font.family = appFontFamily;
    }

    if (window.adminDashboardCharts && window.Chart) {
        const trendCanvas = document.getElementById('adminMonthlyTrendChart');
        const trend = window.adminDashboardCharts.monthlyTrend;

        if (trendCanvas && trend.labels.length) {
            const chartContext = trendCanvas.getContext('2d');
            const fill = chartContext.createLinearGradient(0, 0, 0, 260);
            fill.addColorStop(0, 'rgba(21, 112, 239, 0.28)');
            fill.addColorStop(1, 'rgba(21, 112, 239, 0.01)');

            new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: trend.labels,
                    datasets: [{
                        label: 'ຄຳຂໍລາພັກ',
                        data: trend.values,
                        borderColor: '#1677ee',
                        backgroundColor: fill,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.38,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#1677ee',
                        pointBorderWidth: 3
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0, color: '#718096' }, grid: { color: '#e9f0f8' }, border: { display: false } },
                        x: { ticks: { color: '#718096', maxRotation: 0 }, grid: { display: false }, border: { display: false } }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: { displayColors: false, backgroundColor: '#15233d', padding: 12, titleFont: { weight: '700' }, bodyFont: { weight: '700' } }
                    }
                }
            });
        }
    }
</script>
</body>
</html>
