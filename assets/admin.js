document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) return;

    const copyButton = event.target.closest('[data-copy], [data-copy-target]');
    if (copyButton) {
        const targetSelector = copyButton.getAttribute('data-copy-target');
        let text = copyButton.getAttribute('data-copy') || '';

        if (targetSelector) {
            const target = document.querySelector(targetSelector);
            if (target && 'value' in target) {
                text = target.value;
            }
        }

        try {
            await navigator.clipboard.writeText(text);
        } catch (error) {
            const helper = document.createElement('textarea');
            helper.value = text;
            helper.style.position = 'fixed';
            helper.style.opacity = '0';
            document.body.appendChild(helper);
            helper.select();
            document.execCommand('copy');
            helper.remove();
        }

        const original = copyButton.textContent;
        copyButton.textContent = 'Copiado!';
        setTimeout(() => {
            copyButton.textContent = original;
        }, 1500);
    }

    const selectable = event.target.closest('input[data-select]');
    if (selectable) {
        selectable.select();
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const submitter = event.submitter;
    if (submitter && submitter.getAttribute('formaction') && !submitter.hasAttribute('data-confirm')) {
        return;
    }

    if (form.matches('form[data-confirm]')) {
        const message = form.getAttribute('data-confirm') || 'Confirmar?';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    }
});

(() => {
    const nameInput = document.querySelector('[data-slug-source]');
    const slugInput = document.querySelector('[data-slug-target]');

    if (!nameInput || !slugInput) return;

    if (slugInput.value.trim() !== '') {
        slugInput.dataset.touched = '1';
    }

    function toCamelSlug(str) {
        const clean = str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        const altMatch = clean.match(/^(.*?)(?:[\s\-_]*(?:alt|op)[\s\-_]*(\d+))$/i);
        if (altMatch) {
            return toCamelSlug(altMatch[1]) + 'Alt' + altMatch[2];
        }
        const parts = clean.split(/(?<=[a-z0-9])(?=[A-Z])|[^a-zA-Z0-9]+/).filter(Boolean);
        if (!parts.length) return '';
        return parts.map((p, idx) => idx === 0 ? p.toLowerCase() : (p.charAt(0).toUpperCase() + p.slice(1).toLowerCase())).join('').slice(0, 64);
    }

    nameInput.addEventListener('input', () => {
        if (slugInput.dataset.touched === '1') return;
        slugInput.value = toCamelSlug(nameInput.value);
    });

    slugInput.addEventListener('input', () => {
        slugInput.dataset.touched = '1';
    });
})();

(() => {
    const selectAll = document.querySelector('#select-all');
    const checkboxes = document.querySelectorAll('.channel-checkbox');
    const bulkBar = document.querySelector('#bulk-actions');
    const countBadge = document.querySelector('#bulk-count');
    const bulkSubmit = document.querySelector('#bulk-delete-btn');

    if (!selectAll && checkboxes.length === 0) return;

    function getVisibleCheckboxes() {
        return Array.from(checkboxes).filter(cb => {
            const tr = cb.closest('tr');
            return !tr || tr.style.display !== 'none';
        });
    }

    function updateState() {
        const visible = getVisibleCheckboxes();
        const checked = visible.filter(cb => cb.checked);
        const count = checked.length;
        if (countBadge) countBadge.textContent = String(count);
        if (bulkSubmit) bulkSubmit.disabled = count === 0;
        if (bulkBar) {
            bulkBar.style.display = count > 0 ? 'flex' : 'none';
            bulkBar.classList.toggle('hidden', count === 0);
        }
        if (selectAll) {
            selectAll.checked = visible.length > 0 && count === visible.length;
            selectAll.indeterminate = count > 0 && count < visible.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            const visible = getVisibleCheckboxes();
            visible.forEach(cb => { cb.checked = selectAll.checked; });
            updateState();
        });
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateState);
    });

    document.addEventListener('channel-filter-updated', updateState);

    updateState();
})();

// ==========================================================================
// BUSCA LIVE (FILTRAGEM EM TEMPO REAL)
// ==========================================================================
(() => {
    const searchInput = document.querySelector('#channel-search-input');
    const searchForm = document.querySelector('#channel-search-form');
    const table = document.querySelector('.table-wrap table');
    if (!searchInput || !table) return;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const categorySelect = document.querySelector('#channel-category-select');
    const clearBtn = document.querySelector('#channel-search-clear');
    const countBadge = document.querySelector('#channel-search-count');
    const resetLink = document.querySelector('a[title="Limpar filtros"]');
    const emptyRow = document.querySelector('#live-empty-row');
    const emptyTerm = document.querySelector('#live-empty-term');
    const emptyClear = document.querySelector('#live-empty-clear');

    const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.id !== 'live-empty-row');
    if (rows.length === 0) return;

    const normalize = (str) =>
        (str || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();

    // Cache dos dados indexados para máxima velocidade (0ms de latência)
    const index = rows.map(row => {
        const nameEl = row.querySelector('.name');
        const slugEl = row.querySelector('.sub.mono');
        const cat = row.getAttribute('data-category') || '';

        const nameText = nameEl ? nameEl.textContent : '';
        const slugText = slugEl ? slugEl.textContent.replace('?v=', '') : '';

        return {
            row,
            name: normalize(nameText),
            slug: normalize(slugText),
            category: normalize(cat),
            searchIndex: normalize(nameText + ' ' + slugText + ' ' + cat)
        };
    });

    function applyFilter() {
        const query = normalize(searchInput.value);
        const selectedCat = normalize(categorySelect ? categorySelect.value : '');

        let visibleCount = 0;

        index.forEach(item => {
            let matchQuery = true;
            if (query !== '') {
                matchQuery = item.searchIndex.includes(query) ||
                             item.name.includes(query) ||
                             item.slug.includes(query);
            }

            let matchCategory = true;
            if (selectedCat !== '') {
                matchCategory = item.category === selectedCat;
            }

            const visible = matchQuery && matchCategory;
            if (visible) {
                item.row.style.display = '';
                visibleCount++;
            } else {
                item.row.style.display = 'none';
                const cb = item.row.querySelector('.channel-checkbox');
                if (cb && cb.checked) {
                    cb.checked = false;
                }
            }
        });

        // Toggle do botão 'x' no input
        if (clearBtn) {
            const hasText = searchInput.value.length > 0;
            clearBtn.classList.toggle('hidden', !hasText);
            clearBtn.style.display = hasText ? 'inline-block' : 'none';
        }

        // Estado de "Nenhum canal encontrado"
        if (emptyRow) {
            const showEmpty = visibleCount === 0 && (query !== '' || selectedCat !== '');
            emptyRow.classList.toggle('hidden', !showEmpty);
            emptyRow.style.display = showEmpty ? '' : 'none';
            if (showEmpty && emptyTerm) {
                emptyTerm.textContent = query !== '' ? searchInput.value.trim() : (categorySelect ? categorySelect.value : '');
            }
        }

        // Atualização da contagem de canais
        if (countBadge) {
            if (query !== '' || selectedCat !== '') {
                countBadge.textContent = `${visibleCount} de ${rows.length}`;
            } else {
                countBadge.textContent = `${rows.length} canais`;
            }
            countBadge.classList.remove('hidden');
            countBadge.style.display = 'inline-block';
        }

        // Exibe ou oculta link de limpar filtros
        if (resetLink) {
            const hasFilter = query !== '' || selectedCat !== '';
            resetLink.classList.toggle('hidden', !hasFilter);
            resetLink.style.display = hasFilter ? 'inline-flex' : 'none';
        }

        // Sincroniza estado das caixas de seleção
        document.dispatchEvent(new CustomEvent('channel-filter-updated'));

        // Sincroniza parâmetros na URL sem recarregar a tela
        const url = new URL(window.location.href);
        if (searchInput.value.trim() !== '') {
            url.searchParams.set('q', searchInput.value.trim());
        } else {
            url.searchParams.delete('q');
        }
        if (categorySelect && categorySelect.value !== '') {
            url.searchParams.set('category', categorySelect.value);
        } else {
            url.searchParams.delete('category');
        }
        window.history.replaceState(null, '', url.pathname + (url.search ? url.search : ''));
    }

    searchInput.addEventListener('input', applyFilter);

    if (categorySelect) {
        categorySelect.addEventListener('change', applyFilter);
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            searchInput.focus();
            applyFilter();
        });
    }

    if (emptyClear) {
        emptyClear.addEventListener('click', () => {
            searchInput.value = '';
            if (categorySelect) categorySelect.value = '';
            searchInput.focus();
            applyFilter();
        });
    }

    if (resetLink) {
        resetLink.addEventListener('click', (e) => {
            e.preventDefault();
            searchInput.value = '';
            if (categorySelect) categorySelect.value = '';
            applyFilter();
        });
    }

    if (searchForm) {
        searchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            applyFilter();
        });
    }

    // Clique nas badges de categoria da tabela aplica filtro instantâneo
    document.addEventListener('click', (e) => {
        const catBadge = e.target.closest('a[href*="category="]');
        if (catBadge && categorySelect) {
            const url = new URL(catBadge.href, window.location.origin);
            const cat = url.searchParams.get('category');
            if (cat !== null) {
                e.preventDefault();
                categorySelect.value = cat;
                applyFilter();
            }
        }
    });

    // Filtra de imediato caso haja termo vindo da URL
    applyFilter();
})();
