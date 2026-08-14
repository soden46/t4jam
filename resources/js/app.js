import './bootstrap';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const page = () => document.body.dataset.page;
const rupiah = (value) => `Rp. ${Number(value || 0).toLocaleString('id-ID')},-`;
const number = (value) => Number(value || 0).toLocaleString('id-ID');
const qs = (selector, root = document) => root.querySelector(selector);
const qsa = (selector, root = document) => [...root.querySelectorAll(selector)];

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
        accountSelect.innerHTML = accounts.adaccount.map((account) => `<option value="${account.id}">${account.name}</option>`).join('');
        accountSelect.value = selectedAccount || accounts.selected || accounts.adaccount[0]?.id || '';
        setStat('#ad_account_count', accounts.ad_account_count ?? accounts.adaccount.length);
    };
    renderAccountSelect();

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
            `<option value="${campaign.id}" ${selected.has(campaign.id) ? 'selected' : ''}>${campaign.name}</option>`
        )).join('');
    };

    const selectedCampaigns = () => qsa('#kt_tagify_users option:checked').map((option) => option.value);

    const loadInsights = async () => {
        const insights = await request(`/api/get-ad-insight/?ad_account=${encodeURIComponent(accountSelect.value)}&level=${encodeURIComponent(levelMode())}`);
        renderMetrics(insights.highlight || []);
        renderCampaignTable(insights.summery || []);
        setStat('#campaign_count', (insights.summery || []).length);
    };

    accountSelect.addEventListener('change', async () => {
        await request('/api/changed-ad-account/', { method: 'POST', body: formBody({ ad_account: accountSelect.value }) });
        renderCampaignPicker();
        await loadInsights();
    });
    reloadBtn?.addEventListener('click', async () => {
        reloadBtn.disabled = true;
        reloadBtn.textContent = 'Reloading...';
        setReloadStatus('Menyinkronkan akun iklan Meta...');

        try {
            const selectedBeforeReload = accountSelect.value;
            const response = await request('/api/reload-ad-account/', { method: 'POST', body: formBody({}) });
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

    qsa('#funnel_lp, #conversion').forEach((el) => el.addEventListener('change', () => {
        request('/api/changed-settings/', {
            method: 'POST',
            body: formBody({
                funnel_lp: qs('#funnel_lp').value,
                conversion: qs('#conversion').value,
                level_mode: qs('#level_mode').value,
            }),
        });
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
    bindAutomationForm('create');
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
}

function renderMetrics(metrics) {
    qs('#metric').innerHTML = metrics.map((metric) => {
        const cls = metric.min_value && Number(metric.value) < Number(metric.min_value) ? 'warn' : 'good';
        return `<div class="metric-card ${cls}"><span>${metric.name}</span><strong>${metric.value_text}</strong></div>`;
    }).join('');
}

function renderCampaignTable(rows) {
    qs('#campaign_table tbody').innerHTML = rows.map((row) => `
        <tr>
            <td><input type="checkbox" value="${row.campaign_id}" data-level="${row.level || 'campaign'}" data-ad-account="${row.ad_id || ''}"></td>
            <td><span class="campaign-name">${row.campaign_name}</span></td>
            <td class="num">${rupiah(row.budget)}</td>
            <td class="num">${rupiah(row.spend)}</td>
            <td class="num">${number(row.reach)}</td>
            <td class="num">${number(row.hasil)}</td>
            <td class="num">${rupiah(row.cpr)}</td>
            <td class="num">${number(row.link_click)}</td>
            <td class="num">${number(row.landing_page_view)}</td>
            <td class="num">${row.klik_landas}%</td>
            <td class="num">${row.uang_jangkauan}</td>
            <td class="num">${row.uang_klik}</td>
            <td class="num">${row.landas_hasil}</td>
            <td class="num">${rupiah(row.cpr_10)}</td>
        </tr>
    `).join('');
}

async function initAutomation() {
    bindAutomationForm('update');
    bindSearch('#search_domain', '#automation_table');
    qsa('#add_account_filter, #level_filter, #event_tracking_filter').forEach((el) => el.addEventListener('change', loadAutomationTasks));
    qs('#new_automation')?.addEventListener('click', () => {
        resetAutomationForm();
        qs('#automation-modal-title').textContent = 'Create Automation Budget';
        qs('#automation-submit-label').textContent = 'Create';
        openModal('#automation-modal');
    });
    await loadAutomationTasks();
}

async function loadAutomationTasks() {
    const acc = qs('#add_account_filter')?.value || 'all';
    const level = qs('#level_filter')?.value || 'all';
    const funnel = qs('#event_tracking_filter')?.value || 'all';
    const response = await request(`/get-automation-task/?acc=${encodeURIComponent(acc)}&level=${encodeURIComponent(level)}&funnel=${encodeURIComponent(funnel)}`);
    renderAutomationTable(response.data || []);
}

function renderAutomationTable(rows) {
    const spend = rows.reduce((total, row) => total + Number(row.current_spend || 0), 0);
    const result = rows.reduce((total, row) => total + Number(row.current_hasil || 0), 0);
    qs('#total_ad_spend').textContent = rupiah(spend);
    qs('#total_ad_result').textContent = number(result);
    qs('#avg_ad_cpr').textContent = rupiah(result ? spend / result : spend);

    qs('#automation_table tbody').innerHTML = rows.map((row) => `
        <tr>
            <td><span class="campaign-name">${row.campaign_name}</span><br><small>${row.event_flow} / ${row.conversion}</small></td>
            <td>${row.ad_account}</td>
            <td><button class="badge ${row.status === 'true' ? 'active' : 'pause'}" data-toggle-task="${row.id}" data-status="${row.status === 'true' ? 'false' : 'true'}">${row.status === 'true' ? 'active' : 'pause'}</button></td>
            <td class="num">${rupiah(row.current_budget)}</td>
            <td class="num">${rupiah(row.current_spend)}</td>
            <td class="num">${number(row.current_hasil)}</td>
            <td class="num">${rupiah(row.current_cpr)}</td>
            <td>${row.log || '-'}</td>
            <td class="action-row">
                <button class="btn light" data-history="${row.id}" type="button">Log</button>
                <button class="btn light-primary" data-edit="${row.id}" type="button">Update</button>
                <button class="btn danger" data-budget-down="${row.id}" type="button">Turun</button>
            </td>
        </tr>
    `).join('');

    qsa('[data-toggle-task]').forEach((button) => button.addEventListener('click', async () => {
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'queue...';

        try {
            const response = await request('/update-status-automation-tasks/', { method: 'POST', body: formBody({ automation_id: button.dataset.toggleTask, status: button.dataset.status }) });
            toast(response.text || 'Status automation berhasil diperbarui');
            await loadAutomationTasks();
        } catch (error) {
            toast(error.message, 'danger');
            button.disabled = false;
            button.textContent = originalText;
        }
    }));
    qsa('[data-edit]').forEach((button) => button.addEventListener('click', () => editTask(button.dataset.edit)));
    qsa('[data-history]').forEach((button) => button.addEventListener('click', () => historyTask(button.dataset.history)));
    qsa('[data-budget-down]').forEach((button) => button.addEventListener('click', async () => {
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Queue...';

        try {
            const response = await request('/turun-budget-manual/', { method: 'POST', body: formBody({ automation_id: button.dataset.budgetDown }) });
            toast(response.text || 'Budget berhasil diturunkan manual');
            await loadAutomationTasks();
        } catch (error) {
            toast(error.message, 'danger');
            button.disabled = false;
            button.textContent = originalText;
        }
    }));
}

async function editTask(id) {
    const response = await request(`/get-specific-task/?automation_id=${encodeURIComponent(id)}`);
    const task = response.data;
    resetAutomationForm();
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
        <div class="timeline-item"><strong>${item.time}</strong><ul>${item.text.map((text) => `<li>${text}</li>`).join('')}</ul></div>
    `).join('') || '<p class="muted">Belum ada history.</p>';
    openModal('#history-modal');
}

function resetAutomationForm() {
    qs('#automation-form')?.reset();
    qs('#automation_id').value = '';
    qs('#modal_level').value = 'campaign';
}

function bindAutomationForm(defaultMode) {
    const form = qs('#automation-form');
    if (!form || form.dataset.bound) return;
    form.dataset.bound = '1';
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const isUpdate = qs('#automation_id').value;
        const submitLabel = qs('#automation-submit-label');
        const submit = submitLabel?.closest('button');
        const originalText = submitLabel?.textContent || '';
        if (submit) {
            submit.disabled = true;
            submitLabel.textContent = 'Queue...';
        }

        try {
            const response = await request(isUpdate ? '/update-automation-tasks/' : '/create-automation-tasks/', { method: 'POST', body: formBody(form) });
            closeModals();
            toast(response.text || (isUpdate ? 'Automation strategy berhasil diupdate' : 'Automation budget berhasil dibuat'));
            if (page() === 'automation') await loadAutomationTasks();
        } catch (error) {
            toast(error.message, 'danger');
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
        <tr data-topic="${row.topic || ''}">
            <td><input type="checkbox"></td>
            <td>${row.name}</td>
            <td>${number(row.audience_size_lower_bound)} - ${number(row.audience_size_upper_bound)}</td>
            <td>${row.topic || '-'}</td>
            <td><button class="btn light" data-copy="${row.name}" type="button">Copy</button></td>
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
        <tr data-product='${JSON.stringify(row).replaceAll("'", '&#39;')}'>
            <td><button class="link-button" data-product-detail type="button">${row.name}</button><br><small>${row.category || '-'}</small></td>
            <td class="num">${rupiah(row.price)}</td>
            <td class="num">${number(row.sold)}</td>
            <td class="num">${number(row.total_review)}</td>
            <td class="num">${row.rating}</td>
        </tr>
    `).join('');
    qsa('[data-product-detail]').forEach((button) => button.addEventListener('click', () => {
        const product = JSON.parse(button.closest('tr').dataset.product);
        qs('#product-detail').innerHTML = `
            <div class="product-detail">
                <img src="${product.image || ''}" alt="${product.name}">
                <h3>${product.name}</h3>
                <p>${product.category || '-'}</p>
                <div class="stat-row"><div><span>${rupiah(product.price)}</span><small>Harga</small></div><div><span>${number(product.sold)}</span><small>Terjual</small></div><div><span>${product.rating}</span><small>Rating</small></div></div>
                <a class="btn primary" href="${product.detail_url}" target="_blank" rel="noreferrer">Buka Detail</a>
            </div>
        `;
        openModal('#product-modal');
    }));
}

function initProfile() {
    const syncForm = qs('#sync-meta-form');
    const syncBtn = qs('#sync-meta-btn');

    syncForm?.addEventListener('submit', async () => {
        syncBtn.disabled = true;
        syncBtn.textContent = 'Syncing...';
        sessionStorage.setItem('meta-sync-pending', '1');
    });

    if (page() === 'profile' && sessionStorage.getItem('meta-sync-pending') === '1') {
        const stop = () => sessionStorage.removeItem('meta-sync-pending');
        const hasStatus = !!qs('.alert') || !!qs('.text-success') || !!qs('.text-danger');
        const hasSyncTime = !!qs('.muted')?.textContent?.includes('Terakhir sync');

        if (!hasStatus && !hasSyncTime) {
            setTimeout(() => {
                location.reload();
            }, 3000);
        } else {
            stop();
        }
    }

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
    if (page() === 'profile') initProfile();
});
