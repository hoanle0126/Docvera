/**
 * Docvera — Progressive Document Workbench Client Script
 * Zero external framework dependencies.
 */
(function () {
    'use strict';

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // -------------------------------------------------------------------------
    // 1. Homepage Instant Tool Filtering & "/" Shortcut
    // -------------------------------------------------------------------------
    const searchInput = document.getElementById('tool-search-input');
    const availableCheckbox = document.getElementById('filter-available-only');
    const categoryButtons = Array.from(document.querySelectorAll('[data-category-filter]'));
    const toolItems = Array.from(document.querySelectorAll('[data-tool-item]'));
    const categorySections = Array.from(document.querySelectorAll('[data-category-section]'));
    const emptyFilterState = document.getElementById('empty-filter-state');
    const resetFiltersBtn = document.getElementById('reset-tool-filters');

    let activeCategory = 'all';
    const preselectedCatBtn = document.querySelector('[data-category-filter].is-selected');
    if (preselectedCatBtn) {
        activeCategory = preselectedCatBtn.getAttribute('data-category-filter') || 'all';
    }

    function applyHomepageFilters() {
        if (toolItems.length === 0) {
            return;
        }
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const onlyAvail = availableCheckbox ? availableCheckbox.checked : false;
        let visibleTotal = 0;

        toolItems.forEach((item) => {
            const itemCat = item.getAttribute('data-tool-category') || '';
            const isAvail = item.getAttribute('data-tool-available') === '1';
            const keywords = item.getAttribute('data-tool-search') || '';

            const matchesCat = activeCategory === 'all' || itemCat === activeCategory;
            const matchesAvail = !onlyAvail || isAvail;
            const matchesQuery = query === '' || keywords.includes(query);

            const show = matchesCat && matchesAvail && matchesQuery;
            item.hidden = !show;
            if (show) {
                visibleTotal++;
            }
        });

        categorySections.forEach((section) => {
            const visibleInCat = section.querySelectorAll('[data-tool-item]:not([hidden])').length;
            section.hidden = visibleInCat === 0;
        });

        if (emptyFilterState) {
            emptyFilterState.hidden = visibleTotal > 0;
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyHomepageFilters);
        document.addEventListener('keydown', (e) => {
            if (e.key === '/' && document.activeElement !== searchInput && !['INPUT', 'TEXTAREA', 'SELECT'].includes((document.activeElement || {}).tagName)) {
                e.preventDefault();
                searchInput.focus();
            }
        });
    }

    if (availableCheckbox) {
        availableCheckbox.addEventListener('change', applyHomepageFilters);
    }

    categoryButtons.forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            activeCategory = btn.getAttribute('data-category-filter') || 'all';
            categoryButtons.forEach((b) => b.classList.toggle('is-selected', b === btn));
            applyHomepageFilters();
        });
    });

    if (resetFiltersBtn) {
        resetFiltersBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (searchInput) searchInput.value = '';
            if (availableCheckbox) availableCheckbox.checked = false;
            activeCategory = 'all';
            categoryButtons.forEach((b) => b.classList.toggle('is-selected', b.getAttribute('data-category-filter') === 'all'));
            applyHomepageFilters();
        });
    }

    // -------------------------------------------------------------------------
    // 2. Homepage Smart File Inspector & Router
    // -------------------------------------------------------------------------
    const routerForm = document.getElementById('smart-router-form');
    const routerInput = document.getElementById('router-file-input');
    const routerLiveResult = document.getElementById('router-live-result');

    function formatKb(bytes) {
        return (bytes / 1024).toFixed(1) + ' KB';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str ?? '');
        return div.innerHTML;
    }

    async function handleRouterFileInspect(file) {
        if (!file || !routerForm || !routerLiveResult) {
            return;
        }

        const formData = new FormData();
        formData.append('_csrf', csrfToken);
        formData.append('file', file);

        routerLiveResult.hidden = false;
        routerLiveResult.innerHTML = '<p class="router-prompt">Inspecting file signature and page structure…</p>';

        try {
            const response = await fetch('/router/inspect', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            });
            const data = await response.json();

            if (!response.ok || !data.ok) {
                routerLiveResult.innerHTML = `<div class="alert-banner is-error" role="alert">${escapeHtml(data.error || 'File could not be inspected.')}</div>`;
                return;
            }

            routerForm.hidden = true;
            const insp = data.inspection || {};
            const specs = [escapeHtml(insp.extension || 'FILE'), formatKb(data.size || 0)];
            if (insp.page_count) {
                specs.push(`${insp.page_count} ${insp.page_count === 1 ? 'page' : 'pages'}`);
            }
            if (insp.width_pts && insp.height_pts) {
                specs.push(`${Math.round(insp.width_pts)}×${Math.round(insp.height_pts)} pt`);
            }
            if (insp.image_width && insp.image_height) {
                specs.push(`${insp.image_width}×${insp.image_height} px`);
            }

            const toolsHtml = (data.compatible_tools || []).map((t) => {
                if (t.available) {
                    return `<a href="${escapeHtml(t.url)}" class="compatible-action-item">
                        <span class="compatible-action-name">${escapeHtml(t.name)}</span>
                        <span class="compatible-action-go">Open with file →</span>
                    </a>`;
                }
                return `<div class="compatible-action-item is-disabled" title="${escapeHtml(t.unavailable_reason || '')}">
                    <span class="compatible-action-name">${escapeHtml(t.name)}</span>
                    <span class="compatible-action-unavail">Unavailable</span>
                </div>`;
            }).join('');

            routerLiveResult.innerHTML = `
                <div class="staged-file-summary">
                    <div>
                        <p class="staged-filename">${escapeHtml(data.safe_name)}</p>
                        <p class="staged-specs">${specs.join(' · ')}</p>
                    </div>
                    <button type="button" class="btn-text" id="clear-router-staged-btn">Clear file</button>
                </div>
                <p class="router-prompt">Choose a compatible operation for this file:</p>
                <div class="compatible-actions-list">${toolsHtml}</div>
            `;

            const clearBtn = document.getElementById('clear-router-staged-btn');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    routerLiveResult.hidden = true;
                    routerLiveResult.innerHTML = '';
                    routerForm.hidden = false;
                    routerInput.value = '';
                });
            }
        } catch (err) {
            routerLiveResult.innerHTML = '<div class="alert-banner is-error" role="alert">Unable to inspect the selected file.</div>';
        }
    }

    if (routerForm && routerInput) {
        routerInput.addEventListener('change', () => {
            if (routerInput.files && routerInput.files.length > 0) {
                handleRouterFileInspect(routerInput.files[0]);
            }
        });

        ['dragenter', 'dragover'].forEach((evtName) => {
            routerForm.addEventListener(evtName, (e) => {
                e.preventDefault();
                routerForm.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach((evtName) => {
            routerForm.addEventListener(evtName, (e) => {
                e.preventDefault();
                routerForm.classList.remove('is-dragover');
            });
        });

        routerForm.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                handleRouterFileInspect(e.dataTransfer.files[0]);
            }
        });
    }

    // -------------------------------------------------------------------------
    // 3. Tool Workbench File Dropzone, Page Inspector & Async Execution
    // -------------------------------------------------------------------------
    const toolForm = document.getElementById('tool-process-form');
    const toolDropzone = document.getElementById('tool-dropzone');
    const toolFileInput = document.getElementById('tool-file-input');
    const selectedFilesContainer = document.getElementById('selected-files-container');
    const pageInspectorPanel = document.getElementById('interactive-page-inspector');
    const pageCardsGrid = document.getElementById('page-cards-grid');
    const clientAlert = document.getElementById('client-validation-alert');
    const progressPanel = document.getElementById('processing-progress-panel');
    const submitBtn = document.getElementById('tool-submit-button');
    const stagedIdInput = document.getElementById('staged-file-id-input');

    function showClientError(msg) {
        if (!clientAlert) return;
        clientAlert.textContent = msg;
        clientAlert.hidden = !msg;
    }

    function renderInteractivePageCards(pageCount, toolId) {
        if (!pageInspectorPanel || !pageCardsGrid || pageCount <= 0) {
            return;
        }
        pageInspectorPanel.hidden = false;
        pageCardsGrid.innerHTML = '';

        const targetFieldName = toolId === 'delete-pages'
            ? 'pages_to_delete'
            : (toolId === 'reorder-pages' ? 'page_order' : 'pages');
        const targetInput = toolForm ? toolForm.querySelector(`[name="${targetFieldName}"]`) : null;

        const selectedSet = new Set();
        const orderedList = [];

        for (let p = 1; p <= Math.min(pageCount, 60); p++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'page-card-btn';
            btn.setAttribute('data-page-num', String(p));
            btn.innerHTML = `<span>Page ${p}</span>`;

            if (toolId === 'reorder-pages') {
                orderedList.push(p);
                btn.classList.add('is-selected');
            }

            btn.addEventListener('click', () => {
                if (toolId === 'reorder-pages') {
                    const idx = orderedList.indexOf(p);
                    if (idx !== -1) {
                        orderedList.splice(idx, 1);
                        btn.classList.remove('is-selected');
                    } else {
                        orderedList.push(p);
                        btn.classList.add('is-selected');
                    }
                    if (targetInput) {
                        targetInput.value = orderedList.join(', ');
                    }
                } else {
                    if (selectedSet.has(p)) {
                        selectedSet.delete(p);
                        btn.classList.remove('is-selected');
                    } else {
                        selectedSet.add(p);
                        btn.classList.add('is-selected');
                    }
                    if (targetInput) {
                        const sorted = Array.from(selectedSet).sort((a, b) => a - b);
                        targetInput.value = sorted.length > 0 ? sorted.join(', ') : (toolId === 'delete-pages' ? '' : 'all');
                    }
                }
            });

            pageCardsGrid.appendChild(btn);
        }

        if (toolId === 'reorder-pages' && targetInput && targetInput.value.trim() === '') {
            targetInput.value = orderedList.join(', ');
        }
    }

    async function inspectSelectedToolFile(file, toolId, supportsPagePreview) {
        if (!file || (!supportsPagePreview && toolId !== 'pdf-metadata')) {
            return;
        }
        const formData = new FormData();
        formData.append('_csrf', csrfToken);
        formData.append('file', file);

        try {
            const res = await fetch('/router/inspect', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            });
            const data = await res.json();
            if (!res.ok || !data.ok || !data.inspection) {
                return;
            }
            const insp = data.inspection;
            if (supportsPagePreview && insp.page_count > 0) {
                renderInteractivePageCards(insp.page_count, toolId);
            }
            if (toolId === 'pdf-metadata') {
                ['title', 'author', 'subject', 'keywords', 'creator'].forEach((field) => {
                    const input = toolForm.querySelector(`[name="${field}"]`);
                    if (input && insp[field] && !input.value) {
                        input.value = insp[field];
                    }
                });
            }
        } catch (_) {
            // Non-blocking preview enhancement
        }
    }

    if (toolForm && toolFileInput && toolDropzone) {
        const toolId = toolForm.getAttribute('data-tool-id') || '';
        const supportsPreview = toolForm.getAttribute('data-supports-page-preview') === '1';

        // If a staged file is already attached with known page count, render page cards immediately
        if (stagedIdInput && supportsPreview) {
            const stagedPages = parseInt(stagedIdInput.getAttribute('data-page-count') || '0', 10);
            if (stagedPages > 0) {
                renderInteractivePageCards(stagedPages, toolId);
            }
        }

        function updateSelectedFilesUI(fileList) {
            showClientError('');
            if (!selectedFilesContainer) return;

            const files = Array.from(fileList || []);
            if (files.length === 0) {
                selectedFilesContainer.hidden = true;
                selectedFilesContainer.innerHTML = '';
                return;
            }

            selectedFilesContainer.hidden = false;
            selectedFilesContainer.innerHTML = files.map((f, index) => `
                <div class="selected-file-row">
                    <span class="selected-file-name">${index + 1}. ${escapeHtml(f.name)}</span>
                    <span class="selected-file-meta">${formatKb(f.size)}</span>
                </div>
            `).join('');

            if (files.length === 1) {
                inspectSelectedToolFile(files[0], toolId, supportsPreview);
            }
        }

        toolFileInput.addEventListener('change', () => {
            updateSelectedFilesUI(toolFileInput.files);
        });

        ['dragenter', 'dragover'].forEach((evt) => {
            toolDropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                toolDropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach((evt) => {
            toolDropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                toolDropzone.classList.remove('is-dragover');
            });
        });

        toolDropzone.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                toolFileInput.files = e.dataTransfer.files;
                updateSelectedFilesUI(toolFileInput.files);
            }
        });

        toolForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            showClientError('');

            const minFiles = parseInt(toolForm.getAttribute('data-min-files') || '1', 10);
            const maxFiles = parseInt(toolForm.getAttribute('data-max-files') || '20', 10);
            const hasStaged = Boolean(stagedIdInput && stagedIdInput.value);
            const uploadedCount = toolFileInput.files ? toolFileInput.files.length : 0;
            const totalFiles = uploadedCount + (hasStaged ? 1 : 0);

            const directTextInput = document.getElementById('direct-text-content');
            const hasDirectText = Boolean(directTextInput && directTextInput.value.trim() !== '');

            if (totalFiles < minFiles && !(toolId === 'text-to-pdf' && hasDirectText)) {
                showClientError(
                    minFiles === 1
                        ? 'Please select a file before starting this operation.'
                        : `This tool requires at least ${minFiles} files (you selected ${totalFiles}).`
                );
                return;
            }

            if (totalFiles > maxFiles) {
                showClientError(`This tool accepts at most ${maxFiles} files per operation.`);
                return;
            }

            if (progressPanel) progressPanel.hidden = false;
            if (submitBtn) submitBtn.disabled = true;

            try {
                const formData = new FormData(toolForm);
                const res = await fetch(toolForm.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData
                });
                const payload = await res.json();

                if (!res.ok || !payload.ok) {
                    showClientError(payload.error || 'Document processing failed. Please verify your file and options.');
                    if (progressPanel) progressPanel.hidden = true;
                    if (submitBtn) submitBtn.disabled = false;
                    return;
                }

                if (payload.redirect_url) {
                    window.location.href = payload.redirect_url;
                }
            } catch (err) {
                showClientError('Network or server error occurred while processing the document.');
                if (progressPanel) progressPanel.hidden = true;
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    // -------------------------------------------------------------------------
    // 4. Custom Swiss-Archival Select Component (Progressive Enhancement)
    // -------------------------------------------------------------------------
    const chevronSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>';
    const openWrappers = new Set();

    function closeAllCustomSelects(exceptWrapper = null) {
        openWrappers.forEach((wrapper) => {
            if (wrapper !== exceptWrapper) {
                wrapper.classList.remove('is-open');
                const trigger = wrapper.querySelector('.custom-select-trigger');
                const menu = wrapper.querySelector('.custom-select-menu');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
                if (menu) menu.hidden = true;
                openWrappers.delete(wrapper);
            }
        });
    }

    function enhanceSelectElement(selectEl) {
        if (!selectEl || selectEl.dataset.customSelectEnhanced === '1') {
            return;
        }
        selectEl.dataset.customSelectEnhanced = '1';

        const wrapper = document.createElement('div');
        wrapper.className = 'custom-select-wrapper' + (selectEl.classList.contains('field-select-sm') ? ' is-sm' : '');

        selectEl.parentNode.insertBefore(wrapper, selectEl);
        wrapper.appendChild(selectEl);
        selectEl.classList.add('is-enhanced-select');
        selectEl.setAttribute('tabindex', '-1');

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'custom-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');

        const labelSpan = document.createElement('span');
        labelSpan.className = 'custom-select-label';

        const chevronSpan = document.createElement('span');
        chevronSpan.className = 'custom-select-chevron';
        chevronSpan.innerHTML = chevronSvg;

        trigger.appendChild(labelSpan);
        trigger.appendChild(chevronSpan);

        const menu = document.createElement('div');
        menu.className = 'custom-select-menu';
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;

        const options = Array.from(selectEl.options);
        let focusedIndex = Math.max(0, selectEl.selectedIndex);

        function syncFromSelect() {
            const selectedOpt = selectEl.options[selectEl.selectedIndex] || options[0];
            labelSpan.textContent = selectedOpt ? selectedOpt.textContent : '';
            trigger.title = selectedOpt ? selectedOpt.textContent.trim() : '';

            const optEls = Array.from(menu.querySelectorAll('.custom-select-option'));
            optEls.forEach((el, idx) => {
                const isSel = idx === selectEl.selectedIndex;
                el.classList.toggle('is-selected', isSel);
                el.setAttribute('aria-selected', isSel ? 'true' : 'false');
            });
        }

        function setFocusedOption(idx) {
            const optEls = Array.from(menu.querySelectorAll('.custom-select-option'));
            if (optEls.length === 0) return;
            focusedIndex = (idx + optEls.length) % optEls.length;
            optEls.forEach((el, i) => {
                el.classList.toggle('is-focused', i === focusedIndex);
                if (i === focusedIndex) {
                    el.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function chooseOptionByIndex(idx) {
            if (idx < 0 || idx >= options.length) return;
            if (selectEl.selectedIndex !== idx) {
                selectEl.selectedIndex = idx;
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncFromSelect();
            closeAllCustomSelects();
            trigger.focus();
        }

        options.forEach((opt, idx) => {
            const item = document.createElement('div');
            item.className = 'custom-select-option';
            item.setAttribute('role', 'option');
            item.setAttribute('data-value', opt.value);

            const textSpan = document.createElement('span');
            textSpan.className = 'custom-select-option-text';
            textSpan.textContent = opt.textContent;

            const checkSpan = document.createElement('span');
            checkSpan.className = 'custom-select-check';
            checkSpan.setAttribute('aria-hidden', 'true');
            checkSpan.textContent = '✓';

            item.appendChild(textSpan);
            item.appendChild(checkSpan);

            item.addEventListener('mouseenter', () => {
                setFocusedOption(idx);
            });

            item.addEventListener('click', (e) => {
                e.stopPropagation();
                chooseOptionByIndex(idx);
            });

            menu.appendChild(item);
        });

        syncFromSelect();

        function positionMenu() {
            const rect = trigger.getBoundingClientRect();
            const minMenuWidth = selectEl.classList.contains('field-select-sm') ? 190 : 340;
            const menuWidth = Math.min(Math.max(rect.width, minMenuWidth), window.innerWidth - 24);
            menu.style.width = menuWidth + 'px';

            let left = rect.left;
            if (left + menuWidth > window.innerWidth - 12) {
                left = Math.max(12, window.innerWidth - menuWidth - 12);
            }
            menu.style.left = left + 'px';

            const spaceBelow = window.innerHeight - rect.bottom;
            const estimatedHeight = Math.min(280, options.length * 44 + 12);
            if (spaceBelow < estimatedHeight && rect.top > estimatedHeight) {
                menu.style.top = 'auto';
                menu.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
            } else {
                menu.style.bottom = 'auto';
                menu.style.top = (rect.bottom + 4) + 'px';
            }
        }

        function openMenu() {
            closeAllCustomSelects(wrapper);
            wrapper.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            menu.hidden = false;
            positionMenu();
            openWrappers.add(wrapper);
            setFocusedOption(Math.max(0, selectEl.selectedIndex));
        }

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (wrapper.classList.contains('is-open')) {
                closeAllCustomSelects();
            } else {
                openMenu();
            }
        });

        trigger.addEventListener('keydown', (e) => {
            const isOpen = wrapper.classList.contains('is-open');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) {
                    openMenu();
                } else {
                    setFocusedOption(focusedIndex + 1);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen) {
                    openMenu();
                } else {
                    setFocusedOption(focusedIndex - 1);
                }
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (!isOpen) {
                    openMenu();
                } else {
                    chooseOptionByIndex(focusedIndex);
                }
            } else if (e.key === 'Escape') {
                if (isOpen) {
                    e.preventDefault();
                    closeAllCustomSelects();
                }
            } else if (e.key === 'Tab') {
                if (isOpen) {
                    closeAllCustomSelects();
                }
            }
        });

        selectEl.addEventListener('change', syncFromSelect);

        if (selectEl.id) {
            const assocLabel = document.querySelector(`label[for="${CSS.escape(selectEl.id)}"]`);
            if (assocLabel) {
                assocLabel.addEventListener('click', (e) => {
                    e.preventDefault();
                    trigger.focus();
                });
            }
        }

        wrapper.appendChild(trigger);
        wrapper.appendChild(menu);
    }

    document.querySelectorAll('select.field-select').forEach(enhanceSelectElement);

    document.addEventListener('click', (e) => {
        if (openWrappers.size === 0) return;
        const target = e.target;
        let clickedInside = false;
        openWrappers.forEach((wrapper) => {
            if (wrapper.contains(target)) {
                clickedInside = true;
            }
        });
        if (!clickedInside) {
            closeAllCustomSelects();
        }
    });

    window.addEventListener('resize', () => {
        if (openWrappers.size > 0) closeAllCustomSelects();
    });
    window.addEventListener('scroll', (e) => {
        if (openWrappers.size === 0) return;
        let scrollingInsideMenu = false;
        openWrappers.forEach((wrapper) => {
            const menu = wrapper.querySelector('.custom-select-menu');
            if (menu && e.target === menu) {
                scrollingInsideMenu = true;
            }
        });
        if (!scrollingInsideMenu) {
            closeAllCustomSelects();
        }
    }, true);

    // -------------------------------------------------------------------------
    // 5. Installer Database Driver Conditional Field Highlight
    // -------------------------------------------------------------------------
    const instDbDriver = document.getElementById('inst-db-driver');
    if (instDbDriver) {
        const sqliteInput = document.getElementById('inst-sqlite-path');
        const dsnInput = document.getElementById('inst-db-dsn');
        const userInput = document.getElementById('inst-db-user');
        const passInput = document.getElementById('inst-db-pass');

        function updateInstallerDbFields() {
            const drv = instDbDriver.value;
            const isSqlite = drv === 'sqlite';
            const isMysql = drv === 'mysql';

            if (sqliteInput && sqliteInput.closest('.option-field')) {
                const field = sqliteInput.closest('.option-field');
                field.style.opacity = isSqlite ? '1' : '0.45';
                sqliteInput.readOnly = !isSqlite;
            }
            [dsnInput, userInput, passInput].forEach((inp) => {
                if (inp && inp.closest('.option-field')) {
                    const field = inp.closest('.option-field');
                    field.style.opacity = isMysql ? '1' : '0.45';
                    inp.readOnly = !isMysql;
                }
            });
        }

        instDbDriver.addEventListener('change', updateInstallerDbFields);
        updateInstallerDbFields();
    }

    // -------------------------------------------------------------------------
    // 6. Sticky Header State & Floating Scroll-to-Top IconButton
    // -------------------------------------------------------------------------
    const siteHeader = document.querySelector('.site-header');
    const scrollTopBtn = document.getElementById('scroll-top-btn');

    function updateScrollUI() {
        const y = window.scrollY;
        if (siteHeader) {
            siteHeader.classList.toggle('is-scrolled', y > 12);
        }
        if (scrollTopBtn) {
            scrollTopBtn.classList.toggle('is-visible', y > 260);
        }
    }

    const navToggle = siteHeader ? siteHeader.querySelector('.nav-toggle') : null;

    function setNavOpen(open) {
        siteHeader.classList.toggle('is-nav-open', open);
        navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (navToggle) {
        navToggle.addEventListener('click', () => {
            setNavOpen(navToggle.getAttribute('aria-expanded') !== 'true');
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && siteHeader.classList.contains('is-nav-open')) {
                setNavOpen(false);
                navToggle.focus();
            }
        });
        window.matchMedia('(min-width: 1025px)').addEventListener('change', (e) => {
            if (e.matches) setNavOpen(false);
        });
    }

    window.addEventListener('scroll', updateScrollUI, { passive: true });
    updateScrollUI();

    if (scrollTopBtn) {
        scrollTopBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // -------------------------------------------------------------------------
    // 7. Floating Diagnostic Tooltips ([data-tooltip])
    // -------------------------------------------------------------------------
    const tooltipTriggers = Array.from(document.querySelectorAll('[data-tooltip]'));
    if (tooltipTriggers.length > 0) {
        const floatingTooltip = document.createElement('div');
        floatingTooltip.className = 'docvera-floating-tooltip';
        floatingTooltip.setAttribute('role', 'tooltip');
        document.body.appendChild(floatingTooltip);

        let activeTrigger = null;

        function positionFloatingTooltip(trigger) {
            if (!trigger) return;
            const rect = trigger.getBoundingClientRect();
            const ttRect = floatingTooltip.getBoundingClientRect();
            const gap = 8;
            const margin = 12;

            let top = rect.top - ttRect.height - gap;
            if (top < margin) {
                top = rect.bottom + gap;
            }

            let left = rect.right - ttRect.width;
            if (left < margin) {
                left = rect.left;
            }
            if (left + ttRect.width > window.innerWidth - margin) {
                left = window.innerWidth - ttRect.width - margin;
            }

            floatingTooltip.style.top = `${Math.round(top)}px`;
            floatingTooltip.style.left = `${Math.round(Math.max(margin, left))}px`;
        }

        function showTooltip(trigger) {
            const text = trigger.getAttribute('data-tooltip');
            if (!text) return;
            if (activeTrigger && activeTrigger !== trigger) {
                activeTrigger.classList.remove('is-tooltip-open');
            }
            activeTrigger = trigger;
            trigger.classList.add('is-tooltip-open');
            floatingTooltip.textContent = text;
            floatingTooltip.classList.add('is-visible');
            positionFloatingTooltip(trigger);
        }

        function hideTooltip(trigger) {
            if (trigger && activeTrigger && trigger !== activeTrigger) {
                return;
            }
            if (activeTrigger) {
                activeTrigger.classList.remove('is-tooltip-open');
                activeTrigger = null;
            }
            floatingTooltip.classList.remove('is-visible');
        }

        tooltipTriggers.forEach((trigger) => {
            trigger.addEventListener('mouseenter', () => showTooltip(trigger));
            trigger.addEventListener('mouseleave', () => hideTooltip(trigger));
            trigger.addEventListener('focus', () => showTooltip(trigger));
            trigger.addEventListener('blur', () => hideTooltip(trigger));
            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                if (activeTrigger === trigger) {
                    hideTooltip(trigger);
                } else {
                    showTooltip(trigger);
                }
            });
        });

        window.addEventListener('scroll', () => {
            if (activeTrigger) {
                positionFloatingTooltip(activeTrigger);
            }
        }, { passive: true, capture: true });

        window.addEventListener('resize', () => {
            if (activeTrigger) {
                positionFloatingTooltip(activeTrigger);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && activeTrigger) {
                hideTooltip();
            }
        });
    }
})();

