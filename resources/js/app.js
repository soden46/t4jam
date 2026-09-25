import './bootstrap';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const page = () => document.body.dataset.page;
const rupiah = (value) => `Rp. ${Number(value || 0).toLocaleString('id-ID')},-`;
const number = (value) => Number(value || 0).toLocaleString('id-ID');
const qs = (selector, root = document) => root.querySelector(selector);
const qsa = (selector, root = document) => [...root.querySelectorAll(selector)];
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
}[char]));
const safeUrl = (value) => {
    try {
        const url = new URL(value, location.origin);
        return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
    } catch { return ''; }
};
let automationAccounts = [];
let automationRequest = null;
let automationPage = 1;
let automationPerPage = 10;
let automationSearchTimer = null;
let adSetupRequest = null;
let dashboardRequest = null;

async function request(url, options = {}) {
    const response = await fetch(url, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
            ...(options.headers || {}),
        },
        ...options,
    });

    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new Error(body.text || body.message || 'Request gagal.');
    }

    return response.json();
}

function formBody(formOrObject) {
    const data = formOrObject instanceof HTMLFormElement ? new FormData(formOrObject) : new FormData();
    if (!(formOrObject instanceof HTMLFormElement)) {
        Object.entries(formOrObject).forEach(([key, value]) => data.append(key, value ?? ''));
    }

    qsa('input[type="checkbox"]', formOrObject instanceof HTMLFormElement ? formOrObject : document).forEach((input) => {
        if (input.name) {
            data.set(input.name, input.checked ? (input.value || '1') : '');
        }
    });

    return data;
}

function toast(message, type = 'success') {
    let el = qs('.toast-lite');
    if (!el) {
        el = document.createElement('div');
        document.body.appendChild(el);
    }
    el.removeAttribute('style');
    el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    el.setAttribute('aria-live', type === 'danger' ? 'assertive' : 'polite');
    el.className = `toast-lite alert ${type}`;
    el.textContent = message;
    clearTimeout(el._timer);
    el._timer = setTimeout(() => el.remove(), 2600);
}

function openModal(selector) {
    const modal = typeof selector === 'string' ? qs(selector) : selector;
    if (modal) modal.hidden = false;
}

function closeModals() {
    qsa('.modal').forEach((modal) => modal.hidden = true);
}

function bindCommon() {
    qsa('[data-close-modal]').forEach((btn) => btn.addEventListener('click', closeModals));
    qsa('[data-open-modal]').forEach((btn) => btn.addEventListener('click', () => openModal(btn.dataset.openModal)));
    qs('[data-theme-toggle]')?.addEventListener('click', () => {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        localStorage.setItem('data-theme', next);
    });
    document.documentElement.dataset.theme = localStorage.getItem('data-theme') || 'light';
}

function bindSearch(inputSelector, tableSelector) {
    qs(inputSelector)?.addEventListener('input', (event) => {
        const keyword = event.target.value.toLowerCase();
        qsa(`${tableSelector} tbody tr`).forEach((row) => {
            row.hidden = !row.textContent.toLowerCase().includes(keyword);
        });
    });
}

function bindCheckAll(tableSelector) {
    qs(`${tableSelector} [data-check-all]`)?.addEventListener('change', (event) => {
        qsa(`${tableSelector} tbody input[type="checkbox"]`).forEach((input) => input.checked = event.target.checked);
    });
}

async function initDashboard() {
    let accounts = await request('/api/get-ad-account/');
    const accountSelect = qs('#ad_account');
    const accountPicker = qs('#account_picker');
    const accountPickerButton = qs('#account_picker_button');
    const accountPickerLabel = qs('#account_picker_label');
    const accountPickerMenu = qs('#account_picker_menu');
    const accountPickerSearch = qs('#account_picker_search');
    const accountPickerList = qs('#account_picker_list');
    const accountPickerEmpty = qs('#account_picker_empty');
    const reloadBtn = qs('#reload_ad_account');
    const reloadStatus = qs('#reload_status');
    const setReloadStatus = (message = '') => {
        if (reloadStatus) reloadStatus.textContent = message;
    };
    const setStat = (selector, value) => {
        const el = qs(selector);
        if (el) el.textContent = number(value);
    };
    const renderAccountSelect = (selectedAccount = null) => {
        accountSelect.innerHTML = accounts.adaccount.map((account) => `<option value="${escapeHtml(account.id)}">${escapeHtml(account.name)}</option>`).join('');
        accountSelect.value = selectedAccount || accounts.selected || accounts.adaccount[0]?.id || '';
        if (!accountSelect.value && accounts.adaccount[0]) accountSelect.value = accounts.adaccount[0].id;
        setStat('#ad_account_count', accounts.ad_account_count ?? accounts.adaccount.length);
        renderAccountPicker();
    };

    const levelMode = () => qs('#level_mode')?.value || 'campaign';

    const campaignsForSelectedAccount = () => {
        const account = (accounts.adaccount || []).find((item) => item.id === accountSelect.value);

        return levelMode() === 'adset'
            ? account?.adsets?.data || []
            : account?.campaigns?.data || [];
    };

    const renderCampaignPicker = (selectedCampaigns = []) => {
        const selected = new Set(selectedCampaigns);
        qs('#kt_tagify_users').innerHTML = campaignsForSelectedAccount().map((campaign) => (
            `<option value="${escapeHtml(campaign.id)}" ${selected.has(campaign.id) ? 'selected' : ''}>${escapeHtml(campaign.name)}</option>`
        )).join('');
    };

    const selectedCampaigns = () => qsa('#kt_tagify_users option:checked').map((option) => option.value);

    const loadInsights = async () => {
        const insights = await request(`/api/get-ad-insight/?ad_account=${encodeURIComponent(accountSelect.value)}&level=${encodeURIComponent(levelMode())}&conversion=${encodeURIComponent(qs('#conversion')?.value || 'purchase')}`);
        renderMetrics(insights.highlight || []);
        renderCampaignTable(insights.summery || []);
        setStat('#campaign_count', (insights.summery || []).length);
    };

    const refreshFromLocal = async () => {
        if (dashboardRequest || document.hidden || reloadBtn?.disabled || (accountPickerMenu && !accountPickerMenu.hidden) || qs('.modal:not([hidden])')) return dashboardRequest;

        const selectedBeforeRefresh = accountSelect.value;
        const selectedBeforeCampaigns = selectedCampaigns();
        dashboardRequest = (async () => {
            const freshAccounts = await request('/api/get-ad-account/');
            if (document.hidden) return;

            accounts = freshAccounts;
            renderAccountSelect(selectedBeforeRefresh);
            renderCampaignPicker(selectedBeforeCampaigns);
            await loadInsights();
        })();

        try { await dashboardRequest; }
        finally { dashboardRequest = null; }
    };

    const startDashboardPolling = () => {
        const poll = async () => {
            try { await refreshFromLocal(); }
            catch { /* The next local poll retries quietly. */ }
            finally { setTimeout(poll, 5000); }
        };

        setTimeout(poll, 5000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshFromLocal().catch(() => {});
        });
    };

    const selectedAccount = () => (accounts.adaccount || []).find((item) => item.id === accountSelect.value);

    function renderAccountPicker() {
        if (!accountPicker) return;

        const keyword = (accountPickerSearch?.value || '').trim().toLowerCase();
        const rows = (accounts.adaccount || []).filter((account) => {
            const haystack = `${account.name || ''} ${account.id || ''} ${account.account_id || ''}`.toLowerCase();

            return !keyword || haystack.includes(keyword);
        });
        const account = selectedAccount();

        if (accountPickerLabel) accountPickerLabel.textContent = account?.name || 'Pilih ad account';
        if (accountPickerEmpty) accountPickerEmpty.hidden = rows.length > 0;
        if (!accountPickerList) return;

        accountPickerList.innerHTML = rows.map((item) => `
            <button class="account-combobox__option" type="button" role="option" data-account-id="${escapeHtml(item.id)}" aria-selected="${item.id === accountSelect.value ? 'true' : 'false'}">
                <span>${escapeHtml(item.name || item.id)}</span>
                <small>${escapeHtml(item.account_id || item.id)}</small>
            </button>
        `).join('');
    }

    const closeAccountPicker = () => {
        if (!accountPickerMenu || accountPickerMenu.hidden) return;
        accountPickerMenu.hidden = true;
        accountPickerButton?.setAttribute('aria-expanded', 'false');
    };

    const openAccountPicker = () => {
        if (!accountPickerMenu) return;
        accountPickerMenu.hidden = false;
        accountPickerButton?.setAttribute('aria-expanded', 'true');
        renderAccountPicker();
        requestAnimationFrame(() => accountPickerSearch?.focus());
    };

    const changeSelectedAccount = async () => {
        await request('/api/changed-ad-account/', { method: 'POST', body: formBody({ ad_account: accountSelect.value }) });
        renderCampaignPicker();
        await loadInsights();
        renderAccountPicker();
    };

    accountSelect.addEventListener('change', changeSelectedAccount);
    accountPickerButton?.addEventListener('click', () => {
        if (accountPickerMenu?.hidden) {
            openAccountPicker();
        } else {
            closeAccountPicker();
        }
    });
    accountPickerSearch?.addEventListener('input', renderAccountPicker);
    accountPickerList?.addEventListener('click', (event) => {
        const option = event.target.closest('[data-account-id]');
        if (!option) return;

        accountSelect.value = option.dataset.accountId;
        closeAccountPicker();
        accountSelect.dispatchEvent(new Event('change', { bubbles: true }));
    });
    document.addEventListener('click', (event) => {
        if (accountPicker && !accountPicker.contains(event.target)) closeAccountPicker();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeAccountPicker();
    });

    renderAccountSelect();

    reloadBtn?.addEventListener('click', async () => {
        reloadBtn.disabled = true;
        reloadBtn.textContent = 'Reloading...';
        setReloadStatus('Menyinkronkan akun iklan Meta...');

        try {
            const selectedBeforeReload = accountSelect.value;
            const response = await request('/api/reload-ad-account/', {method: 'POST',body: formBody({ad_account: accountSelect.value,}),});
            accounts = { ...accounts, adaccount: response.adaccount || accounts.adaccount };
            renderAccountSelect(selectedBeforeReload);
            renderCampaignPicker();
            await loadInsights();
            setStat('#ad_account_count', response.ad_account_count ?? accounts.adaccount.length);
            setReloadStatus(response.text || 'Dashboard direfresh.');
            toast(response.text || 'Dashboard direfresh');
        } catch (error) {
            setReloadStatus(error.message);
            toast(error.message, 'danger');
        } finally {
            reloadBtn.disabled = false;
            reloadBtn.textContent = 'Reload';
        }
    });
    qs('#update_campaign')?.addEventListener('click', async () => {
        await request('/api/changed-selected-campaign/', { method: 'POST', body: formBody({ campaigns: selectedCampaigns().join(',') }) });
        await loadInsights();
        toast('Campaign berhasil diperbarui');
    });
    qs('#clean_campaign')?.addEventListener('click', async () => {
        qsa('#kt_tagify_users option').forEach((option) => option.selected = false);
        await request('/api/changed-selected-campaign/', { method: 'POST', body: formBody({ campaigns: '' }) });
        await loadInsights();
        toast('Filter campaign direset');
    });

    qsa('#funnel_lp, #conversion').forEach((el) => el.addEventListener('change', async () => {
        await request('/api/changed-settings/', {
            method: 'POST',
            body: formBody({
                funnel_lp: qs('#funnel_lp').value,
                conversion: qs('#conversion').value,
                level_mode: qs('#level_mode').value,
            }),
        });
        await loadInsights();
    }));
    qs('#level_mode')?.addEventListener('change', async () => {
        await request('/api/changed-settings/', {
            method: 'POST',
            body: formBody({
                funnel_lp: qs('#funnel_lp').value,
                conversion: qs('#conversion').value,
                level_mode: levelMode(),
            }),
        });
        qsa('#kt_tagify_users option').forEach((option) => option.selected = false);
        await request('/api/changed-selected-campaign/', { method: 'POST', body: formBody({ campaigns: '' }) });
        renderCampaignPicker();
        await loadInsights();
    });

    renderCampaignPicker(accounts.selected_campaigns || []);
    await loadInsights();
    bindSearch('#search_domain', '#campaign_table');
    bindCheckAll('#campaign_table');
    bindAutomationForm('create', loadInsights);
    qs('#get_metrik_btn')?.addEventListener('click', () => {
        const checked = qs('#campaign_table tbody input[type="checkbox"]:checked');
        if (!checked) {
            toast('Pilih campaign dulu dari Campaign Overview');
            return;
        }
        qs('#modal_ad_account').value = accountSelect.value;
        qs('#modal_campaign_id').value = checked.value;
        if (checked.dataset.adAccount) qs('#modal_ad_account').value = checked.dataset.adAccount;
        qs('#modal_level').value = checked.dataset.level || levelMode();
        qs('#automation_id').value = '';
        qs('#automation-modal-title').textContent = 'Create Automation Budget';
        qs('#automation-submit-label').textContent = 'Create';
        openModal('#automation-modal');
    });
    startDashboardPolling();
}

function renderMetrics(metrics) {
    qs('#metric').innerHTML = metrics.map((metric) => {
        const cls = metric.min_value && Number(metric.value) < Number(metric.min_value) ? 'warn' : 'good';
        return `<div class="metric-card ${cls}"><span>${escapeHtml(metric.name)}</span><strong>${escapeHtml(metric.value_text)}</strong></div>`;
    }).join('');
}

function renderCampaignTable(rows) {
    qs('#campaign_table tbody').innerHTML = rows.map((row) => `
        <tr>
            <td><input type="checkbox" value="${escapeHtml(row.campaign_id)}" data-level="${escapeHtml(row.level || 'campaign')}" data-ad-account="${escapeHtml(row.ad_id || '')}"></td>
            <td><span class="campaign-name">${escapeHtml(row.campaign_name)}</span></td>
            <td class="num">${rupiah(row.budget)}</td>
            <td class="num">${rupiah(row.spend)}</td>
            <td class="num">${number(row.reach)}</td>
            <td class="num">${number(row.hasil)}</td>
            <td class="num">${rupiah(row.cpr)}</td>
            <td class="num">${number(row.link_click)}</td>
            <td class="num">${number(row.landing_page_view)}</td>
            <td class="num">${escapeHtml(row.klik_landas)}%</td>
            <td class="num">${escapeHtml(row.uang_jangkauan)}</td>
            <td class="num">${escapeHtml(row.uang_klik)}</td>
            <td class="num">${escapeHtml(row.landas_hasil)}</td>
            <td class="num">${rupiah(row.cpr_10)}</td>
        </tr>
    `).join('');
}

async function initAutomation() {
    bindAutomationForm('update', () => loadAutomationTasks({ localOnly: true }));
    qsa('#add_account_filter, #level_filter, #event_tracking_filter').forEach((el) => {
        el.addEventListener('change', () => {
            automationPage = 1;
            loadAutomationTasks({ localOnly: true });
        });
    });
    qs('#automation_per_page')?.addEventListener('change', (event) => {
        automationPerPage = [10, 25, 50].includes(Number(event.target.value)) ? Number(event.target.value) : 10;
        automationPage = 1;
        loadAutomationTasks({ localOnly: true });
    });
    qs('#search_domain')?.addEventListener('input', () => {
        clearTimeout(automationSearchTimer);
        automationSearchTimer = setTimeout(() => {
            automationPage = 1;
            loadAutomationTasks({ localOnly: true });
        }, 300);
    });
    qs('#new_automation')?.addEventListener('click', () => {
        resetAutomationForm();
        showAutomationTargetFields();
        qs('#automation-modal-title').textContent = 'Create Automation Budget';
        qs('#automation-submit-label').textContent = 'Create';
        openModal('#automation-modal');
    });
    await loadAutomationTasks({ localOnly: false });
    loadAutomationTargetAccounts().catch(() => {});
    startAutomationPolling();
}

function startAutomationPolling() {
    const poll = async () => {
        try {
            if (!document.hidden && !qs('.modal:not([hidden])') && !qs('#automation_table button:disabled')) {
                await loadAutomationTasks({ background: true });
            }
        } catch { /* A later poll retries without repeated toasts. */ }
        finally { setTimeout(poll, 5000); }
    };
    setTimeout(poll, 5000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && !qs('.modal:not([hidden])')) loadAutomationTasks({ background: true }).catch(() => {});
    });
}

async function loadAutomationTasks(options = {}) {
    const { background = false } = options || {};
    if (automationRequest) {
        if (background === true) return automationRequest;
        await automationRequest.catch(() => {});
        return loadAutomationTasks({ background, localOnly });
    }
    automationRequest = (async () => {
        const acc = qs('#add_account_filter')?.value || 'all';
        const level = qs('#level_filter')?.value || 'all';
        const funnel = qs('#event_tracking_filter')?.value || 'all';
        const search = qs('#search_domain')?.value || '';
        qs('#automation_table')?.classList.add('is-loading');
        const response = await request(`/get-automation-task/?acc=${encodeURIComponent(acc)}&level=${encodeURIComponent(level)}&funnel=${encodeURIComponent(funnel)}&page=${automationPage}&per_page=${automationPerPage}&search=${encodeURIComponent(search)}`);
        if (background === true && (document.hidden || qs('.modal:not([hidden])'))) return;
        renderAutomationTable(response.data || [], response.summary || null);
        renderAutomationPagination(response.pagination || null);
    })();
    try { await automationRequest; }
    finally {
        qs('#automation_table')?.classList.remove('is-loading');
        automationRequest = null;
    }

}

function renderAutomationTable(rows, summary = null) {
    const spend = summary ? Number(summary.total_spend || 0) : rows.reduce((total, row) => total + Number(row.current_spend || 0), 0);
    const result = summary ? Number(summary.total_result || 0) : rows.reduce((total, row) => total + Number(row.current_hasil || 0), 0);
    const averageCpr = summary ? Number(summary.average_cpr || 0) : (result ? spend / result : spend);
    qs('#total_ad_spend').textContent = rupiah(spend);
    qs('#total_ad_result').textContent = number(result);
    qs('#avg_ad_cpr').textContent = rupiah(averageCpr);

    if (!rows.length) {
        qs('#automation_table tbody').innerHTML = '<tr><td colspan="11" class="table-empty">Tidak ada data automation.</td></tr>';

        return;
    }

    qs('#automation_table tbody').innerHTML = rows.map((row) => {
        const currentCpr = Number(row.current_cpr || 0);
        const cprCap = Number(row.cpr_cap || 0);
        const metricsStale = Boolean(row.metrics_stale);
        const isOverLimit = cprCap > 0 && currentCpr >= cprCap;
        const staleMetricTitle = metricsStale
            ? ' title="Metrik Meta belum diperbarui; menampilkan data terakhir."'
            : '';
        const metaStatus = row.meta_effective_status || row.meta_status || '-';
        const metaInactive = ['PAUSED', 'DELETED', 'ARCHIVED'].includes(String(metaStatus).toUpperCase());
        const automationActive = !metaInactive && (row.automation_status ? row.automation_status === 'active' : row.status === 'true');
        const metaStatusClass = metaStatus === 'ACTIVE' ? 'active' : (metaStatus === 'PAUSED' ? 'pause' : 'draft');

        return `
            <tr>
                <td>
                    <span class="campaign-name">${escapeHtml(row.campaign_name)}</span>
                    <span class="campaign-sub">${escapeHtml(row.event_flow)} / ${escapeHtml(row.conversion)}</span>
                </td>
                <td><span class="account-name">${escapeHtml(row.ad_account || '-')}</span></td>
                <td><span class="badge ${automationActive ? 'active' : 'pause'}">${automationActive ? 'Active' : 'Paused'}</span></td>
                <td><span class="badge ${metaStatusClass}">${escapeHtml(metaStatus)}</span></td>
                <td class="num"><strong>${rupiah(row.current_budget)}</strong></td>
                <td class="num"><strong${staleMetricTitle}>${rupiah(row.current_spend)}</strong></td>
                <td class="num center"><strong>${number(row.current_hasil)}</strong></td>
                <td class="num ${isOverLimit ? 'text-danger' : ''}"><strong${staleMetricTitle}>${rupiah(row.current_cpr)}</strong></td>
                <td class="num"><strong>${rupiah(row.cpr_cap)}</strong></td>
                <td class="log-cell">
                    <span class="log-text">${escapeHtml(row.log || '-')}</span>
                    <span class="log-time">${escapeHtml(row.metrics_synced_at || row.last_update || '-')}</span>
                </td>
                <td class="actions-cell">
                    <button class="action-btn action-btn-log" data-history="${escapeHtml(row.id)}" type="button">Log</button>
                    <button class="action-btn action-btn-update" data-edit="${escapeHtml(row.id)}" type="button">Update</button>
                    <button class="action-btn action-btn-budget" data-budget-down="${escapeHtml(row.id)}" type="button">Turun Budget</button>
                    <button class="action-btn action-btn-pause ${automationActive ? 'active' : ''}" data-toggle-task="${escapeHtml(row.id)}" data-status="${automationActive ? 'false' : 'true'}" type="button">${automationActive ? 'Pause' : 'Aktifkan'}</button>
                    <button class="action-btn action-btn-delete" data-delete-task="${escapeHtml(row.id)}" data-campaign="${escapeHtml(row.campaign_name)}" type="button">Hapus</button>
                </td>
            </tr>
        `;
    }).join('');

    qsa('[data-toggle-task]').forEach((button) => button.addEventListener('click', async () => {
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Saving...';

        try {
            const response = await request('/update-status-automation-tasks/', { method: 'POST', body: formBody({ automation_id: button.dataset.toggleTask, status: button.dataset.status }) });
            toast(response.text || 'Status automation berhasil diperbarui');
            await loadAutomationTasks();
        } catch (error) {
            toast(error.message, 'danger');
            if (page() === 'automation') await loadAutomationTasks();
            button.disabled = false;
            button.textContent = originalText;
        }
    }));
    qsa('[data-edit]').forEach((button) => button.addEventListener('click', () => editTask(button.dataset.edit)));
    qsa('[data-history]').forEach((button) => button.addEventListener('click', () => historyTask(button.dataset.history)));
    qsa('[data-budget-down]').forEach((button) => button.addEventListener('click', async () => {
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Saving...';

        try {
            const response = await request('/turun-budget-manual/', { method: 'POST', body: formBody({ automation_id: button.dataset.budgetDown }) });
            toast(response.text || 'Budget berhasil diturunkan manual');
            await loadAutomationTasks();
        } catch (error) {
            toast(error.message, 'danger');
            if (page() === 'automation') await loadAutomationTasks();
            button.disabled = false;
            button.textContent = originalText;
        }
    }));
    qsa('[data-delete-task]').forEach((button) => button.addEventListener('click', async () => {
        if (!confirm(`Hapus automation budget "${button.dataset.campaign}" dari tools?`)) return;

        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Deleting...';

        try {
            const response = await request('/delete-automation-tasks/', { method: 'POST', body: formBody({ automation_id: button.dataset.deleteTask }) });
            toast(response.text || 'Automation budget berhasil dihapus');
            await loadAutomationTasks();
        } catch (error) {
            toast(error.message, 'danger');
            if (page() === 'automation') await loadAutomationTasks();
            button.disabled = false;
            button.textContent = originalText;
        }
    }));
}

function renderAutomationPagination(pagination) {
    const container = qs('#automation_pagination');
    if (!container || !pagination) return;

    automationPage = Number(pagination.current_page || 1);
    automationPerPage = Number(pagination.per_page || automationPerPage);
    const total = Number(pagination.total || 0);
    const lastPage = Math.max(1, Number(pagination.last_page || 1));
    const currentPage = Math.min(Math.max(1, automationPage), lastPage);
    const pages = [];
    for (let pageNumber = Math.max(1, currentPage - 2); pageNumber <= Math.min(lastPage, currentPage + 2); pageNumber++) {
        pages.push(pageNumber);
    }

    container.hidden = false;
    container.innerHTML = `
        <span class="pagination-summary">Showing ${number(pagination.from || 0)}-${number(pagination.to || 0)} of ${number(total)}</span>
        <div class="pagination-controls">
            <button class="btn light" data-automation-page="${currentPage - 1}" ${currentPage <= 1 ? 'disabled' : ''} type="button">Previous</button>
            ${pages.map((pageNumber) => `<button class="page-btn ${pageNumber === currentPage ? 'active' : ''}" data-automation-page="${pageNumber}" type="button">${pageNumber}</button>`).join('')}
            <button class="btn light" data-automation-page="${currentPage + 1}" ${currentPage >= lastPage ? 'disabled' : ''} type="button">Next</button>
        </div>
    `;
    qsa('[data-automation-page]', container).forEach((button) => button.addEventListener('click', () => {
        const pageNumber = Number(button.dataset.automationPage);
        if (!pageNumber || pageNumber === automationPage) return;
        automationPage = pageNumber;
        loadAutomationTasks({ localOnly: true });
    }));
}

async function editTask(id) {
    const response = await request(`/get-specific-task/?automation_id=${encodeURIComponent(id)}`);
    const task = response.data;
    resetAutomationForm();
    hideAutomationTargetFields();
    Object.entries({
        automation_id: task.id,
        budget_funnel_lp: task.event_flow,
        mode_automation: task.mode,
        hold_spend: task.system_flow,
        budget_conversion: task.conversion,
        starting_budget: task.starting_budget,
        maximum_budget: task.maximum_budget,
        cpr_cap: task.cpr_cap,
        period: task.period,
        pause_cpr_cap: task.pause_cpr_cap,
        on_time: task.on_time,
        off_time: task.off_time,
    }).forEach(([id, value]) => { const el = qs(`#${id}`); if (el) el.value = value ?? ''; });
    qs('#cpr_pause').checked = !!task.cpr_pause;
    qs('#counter_cpr').checked = !!task.counter_cpr;
    qs('#use_on_off').checked = !!task.use_on_off;
    qs('#automation_activation').checked = task.status === 'true';
    qs('#automation-modal-title').textContent = 'Update Automation Budget';
    qs('#automation-submit-label').textContent = 'Update';
    openModal('#automation-modal');
}

async function historyTask(id) {
    const response = await request(`/get-history-log/?task_id=${encodeURIComponent(id)}`);
    qs('#item-timeline').innerHTML = (response.data || []).map((item) => `
        <div class="timeline-item"><strong>${escapeHtml(item.time)}</strong><ul>${item.text.map((text) => `<li>${escapeHtml(text)}</li>`).join('')}</ul></div>
    `).join('') || '<p class="muted">Belum ada history.</p>';
    openModal('#history-modal');
}

function resetAutomationForm() {
    qs('#automation-form')?.reset();
    qs('#automation_id').value = '';
    qs('#modal_level').value = 'campaign';
    hideAutomationTargetFields();
}

async function loadAutomationTargetAccounts() {
    const response = await request('/api/get-ad-account/');
    automationAccounts = response.adaccount || [];
    renderAutomationAccountOptions(response.selected || '');
}

function renderAutomationAccountOptions(selected = '') {
    const accountSelect = qs('#modal_target_ad_account');
    if (!accountSelect) return;

    const filterAccount = qs('#add_account_filter')?.value;
    const selectedAccount = filterAccount && filterAccount !== 'all'
        ? filterAccount
        : selected || automationAccounts[0]?.id || '';

    accountSelect.innerHTML = [
        '<option value="">Pilih Ad Account</option>',
        ...automationAccounts.map((account) => `<option value="${escapeHtml(account.id)}">${escapeHtml(account.name)}</option>`),
    ].join('');
    accountSelect.value = selectedAccount;
    qs('#modal_target_level').value = ['campaign', 'adset'].includes(qs('#level_filter')?.value)
        ? qs('#level_filter').value
        : 'campaign';

    syncAutomationTargetFields();
}

function showAutomationTargetFields() {
    const fields = qs('#automation-target-fields');
    if (fields) fields.hidden = false;

    renderAutomationAccountOptions(qs('#modal_target_ad_account')?.value || '');
}

function hideAutomationTargetFields() {
    const fields = qs('#automation-target-fields');
    if (fields) fields.hidden = true;
}

function syncAutomationTargetFields() {
    const accountId = qs('#modal_target_ad_account')?.value || '';
    const level = qs('#modal_target_level')?.value === 'adset' ? 'adset' : 'campaign';
    const account = automationAccounts.find((item) => item.id === accountId);
    const rows = level === 'adset'
        ? account?.adsets?.data || []
        : account?.campaigns?.data || [];
    const targetSelect = qs('#modal_target_campaign');
    const selectedTarget = targetSelect?.value || '';

    if (targetSelect) {
        targetSelect.innerHTML = [
            `<option value="">Pilih ${level === 'adset' ? 'Adset' : 'Campaign'}</option>`,
            ...rows.map((row) => `<option value="${escapeHtml(row.id)}">${escapeHtml(row.name)}</option>`),
        ].join('');
        targetSelect.value = rows.some((row) => row.id === selectedTarget)
            ? selectedTarget
            : rows[0]?.id || '';
    }

    qs('#modal_ad_account').value = accountId;
    qs('#modal_level').value = level;
    qs('#modal_campaign_id').value = targetSelect?.value || '';
}

function bindAutomationTargetFields() {
    qsa('#modal_target_ad_account, #modal_target_level, #modal_target_campaign').forEach((select) => {
        if (select.dataset.bound) return;
        select.dataset.bound = '1';
        select.addEventListener('change', syncAutomationTargetFields);
    });
}

function bindAutomationForm(defaultMode, refreshAfterSuccess = null) {
    const form = qs('#automation-form');
    if (!form || form.dataset.bound) return;
    form.dataset.bound = '1';
    bindAutomationTargetFields();
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const isUpdate = qs('#automation_id').value;
        const submitLabel = qs('#automation-submit-label');
        const submit = submitLabel?.closest('button');
        const originalText = submitLabel?.textContent || '';
        if (submit) {
            submit.disabled = true;
            submitLabel.textContent = 'Saving...';
        }

        try {
            const response = await request(isUpdate ? '/update-automation-tasks/' : '/create-automation-tasks/', { method: 'POST', body: formBody(form) });
            closeModals();
            toast(response.text || (isUpdate ? 'Automation strategy berhasil diupdate' : 'Automation budget berhasil dibuat'));
            if (refreshAfterSuccess) await refreshAfterSuccess();
        } catch (error) {
            toast(error.message, 'danger');
            if (page() === 'automation') await loadAutomationTasks();
        } finally {
            if (submit) {
                submit.disabled = false;
                submitLabel.textContent = originalText || (isUpdate ? 'Update' : 'Create');
            }
        }
    });
    if (defaultMode === 'create') qs('#activation-row')?.setAttribute('hidden', 'hidden');
}

async function initInterest() {
    const load = async () => {
        const keyword = qs('#keyword_interest').value;
        const response = await request(`/api/get-interest/?keyword=${encodeURIComponent(keyword)}`);
        renderInterest(response.interest || []);
    };
    qs('#cari_interest')?.addEventListener('click', load);
    qs('#keyword_interest')?.addEventListener('keydown', (event) => { if (event.key === 'Enter') load(); });
    qs('#copy_interest')?.addEventListener('click', async () => {
        const text = qsa('#interest_table tbody tr:not([hidden]) td:nth-child(2)').map((td) => td.textContent.trim()).join('\n');
        await navigator.clipboard.writeText(text);
        toast('Interest berhasil dicopy');
    });
    qs('#interest_topic_filter')?.addEventListener('change', () => {
        const topic = qs('#interest_topic_filter').value;
        qsa('#interest_table tbody tr').forEach((row) => row.hidden = topic && row.dataset.topic !== topic);
    });
    bindSearch('#search_domain', '#interest_table');
    bindCheckAll('#interest_table');
    await load();
}

function renderInterest(rows) {
    qs('#interest_table tbody').innerHTML = rows.map((row) => `
        <tr data-topic="${escapeHtml(row.topic || '')}">
            <td><input type="checkbox"></td>
            <td>${escapeHtml(row.name)}</td>
            <td>${number(row.audience_size_lower_bound)} - ${number(row.audience_size_upper_bound)}</td>
            <td>${escapeHtml(row.topic || '-')}</td>
            <td><button class="btn light" data-copy="${escapeHtml(row.name)}" type="button">Copy</button></td>
        </tr>
    `).join('');
    qsa('[data-copy]').forEach((button) => button.addEventListener('click', async () => {
        await navigator.clipboard.writeText(button.dataset.copy);
        toast('Interest berhasil dicopy');
    }));
}

async function initProducts() {
    const load = async () => {
        const params = new URLSearchParams({
            keyword: qs('#keyword').value,
            min_price: qs('#min_price').value,
            max_price: qs('#max_price').value,
            min_sold: qs('#min_sold').value,
            last_added: qs('#last_added').value,
        });
        const category = qs('#category').value || qs('#category_filter').value;
        const url = category
            ? `/api/get-category-product/?cat_id=${encodeURIComponent(category)}&${params}`
            : `/api/get-produk/?${params}`;
        const response = await request(url);
        renderProducts(response.produk || []);
    };
    qs('#btn_cari_backlink')?.addEventListener('click', load);
    qs('#category_filter')?.addEventListener('change', load);
    bindSearch('#search_domain', '#product_table');
    await load();
}

function renderProducts(rows) {
    qs('#product_table tbody').innerHTML = rows.map((row) => `
        <tr data-product='${escapeHtml(JSON.stringify(row))}'>
            <td><button class="link-button" data-product-detail type="button">${escapeHtml(row.name)}</button><br><small>${escapeHtml(row.category || '-')}</small></td>
            <td class="num">${rupiah(row.price)}</td>
            <td class="num">${number(row.sold)}</td>
            <td class="num">${number(row.total_review)}</td>
            <td class="num">${escapeHtml(row.rating)}</td>
        </tr>
    `).join('');
    qsa('[data-product-detail]').forEach((button) => button.addEventListener('click', () => {
        const product = JSON.parse(button.closest('tr').dataset.product);
        qs('#product-detail').innerHTML = `
            <div class="product-detail">
                <img src="${escapeHtml(safeUrl(product.image))}" alt="${escapeHtml(product.name)}">
                <h3>${escapeHtml(product.name)}</h3>
                <p>${escapeHtml(product.category || '-')}</p>
                <div class="stat-row"><div><span>${rupiah(product.price)}</span><small>Harga</small></div><div><span>${number(product.sold)}</span><small>Terjual</small></div><div><span>${escapeHtml(product.rating)}</span><small>Rating</small></div></div>
                <a class="btn primary" href="${escapeHtml(safeUrl(product.detail_url))}" target="_blank" rel="noreferrer">Buka Detail</a>
            </div>
        `;
        openModal('#product-modal');
    }));
}

function renderAdSetupRows(rows) {
    const tbody = qs('#ad_setup_table_body');
    if (!tbody) return;

    if (qs('#ad_setup_total')) qs('#ad_setup_total').textContent = number(rows.length);

    tbody.innerHTML = rows.length ? rows.map((row) => {
        const badgeClass = {
            published: 'active',
            publishing: 'ready',
            ready: 'ready',
            failed: 'failed',
            draft: 'draft',
        }[row.status] || 'draft';
        const action = row.status === 'publishing'
            ? '<span class="muted">Queued</span>'
            : row.status === 'published'
                ? '<span class="muted">Done</span>'
                : `
                    <form method="POST" action="${escapeHtml(row.publish_url)}" data-ad-setup-publish>
                        <input type="hidden" name="_token" value="${escapeHtml(csrf())}">
                        <button class="btn light-primary" type="submit">Publish</button>
                    </form>
                `;

        return `
            <tr>
                <td data-label="Setup"><strong>${escapeHtml(row.name)}</strong><br><small>${escapeHtml(row.campaign_name)}</small></td>
                <td data-label="Ad Account">${escapeHtml(row.ad_account)}</td>
                <td data-label="Status"><span class="badge ${badgeClass}">${escapeHtml(row.status)}</span></td>
                <td data-label="Meta IDs">
                    <small>Campaign: ${escapeHtml(row.meta_campaign_id || '-')}</small><br>
                    <small>Ad Set: ${escapeHtml(row.meta_adset_id || '-')}</small><br>
                    <small>Ad: ${escapeHtml(row.meta_ad_id || '-')}</small>
                </td>
                <td data-label="Last Error">${escapeHtml(row.last_error || '-')}</td>
                <td data-label="Action">${action}</td>
            </tr>
        `;
    }).join('') : '<tr><td colspan="6" class="center muted">Belum ada setup iklan.</td></tr>';
}

async function loadAdSetups(background = false) {
    const tbody = qs('#ad_setup_table_body');
    if (!tbody) return;

    if (adSetupRequest) {
        if (background === true) return adSetupRequest;
        await adSetupRequest.catch(() => {});
        return loadAdSetups(background);
    }

    adSetupRequest = (async () => {
        const response = await request(tbody.dataset.statusUrl || '/setup-iklan/status/');
        if (background === true && document.hidden) return;
        renderAdSetupRows(response.setups || []);
    })();

    try { await adSetupRequest; }
    finally { adSetupRequest = null; }
}

function startAdSetupPolling() {
    const poll = async () => {
        try {
            if (!document.hidden) {
                await loadAdSetups(true);
            }
        } catch { /* A later poll retries without distracting the user. */ }
        finally {
            const hasPublishing = !!qs('#ad_setup_table_body .badge.ready');
            setTimeout(poll, hasPublishing ? 5000 : 15000);
        }
    };

    setTimeout(poll, 5000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) loadAdSetups(true).catch(() => {});
    });
}

function bindAdSetupForms() {
    const form = qs('#ad-setup-form');
    if (form && !form.dataset.bound) {
        form.dataset.bound = '1';
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submit = event.submitter;
            const originalText = submit?.textContent || '';

            if (submit) {
                submit.disabled = true;
                submit.textContent = submit.value === '1' ? 'Queuing...' : 'Saving...';
            }

            try {
                const body = formBody(form);
                if (submit?.name) body.set(submit.name, submit.value || '');
                const response = await request(form.action, { method: 'POST', body });
                toast(response.text || 'Setup iklan berhasil disimpan.');
                form.reset();
                await loadAdSetups();
            } catch (error) {
                toast(error.message, 'danger');
                await loadAdSetups(true).catch(() => {});
            } finally {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = originalText;
                }
            }
        });
    }

    document.addEventListener('submit', async (event) => {
        const publishForm = event.target.closest?.('[data-ad-setup-publish]');
        if (!publishForm) return;

        event.preventDefault();
        const submit = qs('button[type="submit"]', publishForm);
        const originalText = submit?.textContent || '';
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Queuing...';
        }

        try {
            const response = await request(publishForm.action, { method: 'POST', body: formBody(publishForm) });
            toast(response.text || 'Setup iklan diproses di background.');
            await loadAdSetups();
        } catch (error) {
            toast(error.message, 'danger');
            await loadAdSetups(true).catch(() => {});
            if (submit) {
                submit.disabled = false;
                submit.textContent = originalText;
            }
        }
    });
}

async function initAdSetups() {
    bindAdSetupForms();
    await loadAdSetups();
    startAdSetupPolling();
}

function initProfile() {
    qs('#sync-meta-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;

        try {
            const response = await request(form.action, { method: 'POST', body: formBody(form) });
            toast(response.text || 'Sync Meta Ads masuk antrean queue.');
        } catch (error) {
            toast(error.message, 'danger');
        }
    });

    qs('#profile-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        await request('/update-profile/', { method: 'PATCH', body: formBody(event.target) });
        toast('Profile berhasil diupdate');
        setTimeout(() => location.reload(), 700);
    });
    qs('#password-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        if (form.new_password.value !== form.confirm_password.value) {
            toast('Confirm password belum sama');
            return;
        }
        await request('/api/v1/auth/users/set_password/', { method: 'POST', body: formBody(form) });
        form.reset();
        closeModals();
        toast('Password berhasil diupdate');
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    bindCommon();
    if (page() === 'dashboard') await initDashboard();
    if (page() === 'automation') await initAutomation();
    if (page() === 'interest') await initInterest();
    if (page() === 'products') await initProducts();
    if (page() === 'ad-setups') await initAdSetups();
    if (page() === 'profile') initProfile();
});
