@extends('layouts.app', ['title' => $title])

@section('page', 'dashboard')

@section('content')
<section class="page-hero compact">
    <div>
        <span class="eyebrow">Report Dashboard</span>
        <h1>Meta Ads Overview</h1>
        <p>Pantau campaign, metrik iklan, dan automation budget dari satu workspace.</p>
    </div>
    <div class="hero-stats">
        <div>
            <strong id="ad_account_count">{{ $accounts->count() }}</strong>
            <span>Ad Account</span>
        </div>
        <div>
            <strong id="campaign_count">{{ collect($insights['summery'] ?? [])->count() }}</strong>
            <span>Campaign</span>
        </div>
    </div>
</section>

<section class="toolbar">
    <select id="ad_account" class="toolbar-select">
        @foreach ($accounts as $account)
            <option value="{{ $account->external_id }}" @selected(($selectedAccount ?? null) === $account->external_id)>{{ $account->name }}</option>
        @endforeach
    </select>
    <button class="btn light" type="button" id="reload_ad_account">Reload</button>
    <span class="toolbar-status muted" id="reload_status" role="status" aria-live="polite"></span>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Select Campaign</h2>
            <p>Update campaign yang ingin dipakai dalam report dashboard.</p>
        </div>
        <div class="action-row">
            <button class="btn light-primary" id="update_campaign" type="button">Update</button>
            <button class="btn light" id="clean_campaign" type="button">Reset Data</button>
        </div>
    </div>
    <select class="tag-input" id="kt_tagify_users" multiple size="4" aria-label="Select Campaign"></select>
</section>

<div class="dash-separator"><span>Data Campaign</span></div>

<section class="filter-strip">
    <select id="funnel_lp" name="funnel_lp">
        <option value="lp_to_wa">LP To WA</option>
        <option value="lp_to_form">LP To Form</option>
        <option value="lwa">LWA</option>
    </select>
    <select id="conversion" name="conversion">
        <option value="purchase">Event Conversion : Purchase</option>
        <option value="add_to_cart">Event Conversion : ATC</option>
        <option value="lead">Event Conversion : Lead (Prospek)</option>
        <option value="add_payment_info">Event Conversion : Add Payment Info</option>
        <option value="initiate_checkout">Event Conversion : Initiate Checkout</option>
        <option value="contact_website">Event Conversion : Website Contact</option>
        <option value="onsite_conversion.messaging_conversation_started_7d">Event Conversion : Chat Whatsapp</option>
    </select>
</section>

<section class="card panel">
    <div class="panel-head">
        <div>
            <h3>Ads Performance</h3>
            <p>Bagaimana performa iklanmu?</p>
        </div>
    </div>
    <div class="metric-grid" id="metric"></div>
</section>

<section class="card panel">
    <div class="panel-head">
        <div>
            <h3>Campaign Overview</h3>
            <p>Data Breakdown Per Campaign</p>
        </div>
        <select id="level_mode" name="level_mode">
            <option value="campaign">Campaign Level</option>
            <option value="adset">Adset Level</option>
        </select>
    </div>
    <div class="table-toolbar">
        <input id="search_domain" type="search" placeholder="Search">
        <button class="btn danger" id="get_metrik_btn" type="button">Apply Automation Budget</button>
    </div>
    <div class="table-wrap">
        <table class="data-table" id="campaign_table">
            <thead>
                <tr>
                    <th><input type="checkbox" data-check-all></th>
                    <th>Campaign</th>
                    <th class="num">Budget</th>
                    <th class="num">Spend</th>
                    <th class="num">Reach</th>
                    <th class="num">Hasil</th>
                    <th class="num">CPR</th>
                    <th class="num">Link Click</th>
                    <th class="num">LP View</th>
                    <th class="num">Klik Landas</th>
                    <th class="num">Uang Jangkauan</th>
                    <th class="num">Uang Klik</th>
                    <th class="num">Landas Hasil</th>
                    <th class="num">CPR 10%</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</section>

@include('components.automation-modal')
@endsection
