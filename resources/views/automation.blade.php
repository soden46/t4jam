@extends('layouts.app', ['title' => $title])

@section('page', 'automation')

@section('content')
<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Automation Budget Monitoring</h2>
            <p>Daftar strategy automation yang aktif dan histori BOT budget.</p>
        </div>
        <button class="btn primary" id="new_automation" type="button">Create Automation Budget</button>
    </div>
    <div class="table-toolbar">
        <input id="search_domain" type="search" placeholder="Search">
        <select name="add_account_filter" id="add_account_filter">
            <option value="all">Show All</option>
            @foreach ($accounts as $account)
                <option value="{{ $account->external_id }}">{{ $account->name }}</option>
            @endforeach
        </select>
        <select name="level_filter" id="level_filter">
            <option value="all">Show All</option>
            <option value="campaign">Campaign</option>
            <option value="adset">Adset</option>
        </select>
        <select name="event_tracking_filter" id="event_tracking_filter">
            <option value="all">Show All</option>
            <option value="lp_to_wa">Lp To Wa</option>
            <option value="lp_to_form">Lp To Form</option>
            <option value="lwa">LWA</option>
        </select>
    </div>
    <div class="stat-row">
        <div><span id="total_ad_spend">Rp. 0,-</span><small>Total Spend</small></div>
        <div><span id="total_ad_result">0</span><small>Total Hasil</small></div>
        <div><span id="avg_ad_cpr">Rp. 0,-</span><small>Average CPR</small></div>
    </div>
    <div class="table-wrap">
        <table class="data-table" id="automation_table">
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Account</th>
                    <th>Status</th>
                    <th class="num">Budget</th>
                    <th class="num">Spend</th>
                    <th class="num">Hasil</th>
                    <th class="num">CPR</th>
                    <th>Log</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</section>

<div class="modal" id="history-modal" hidden>
    <div class="modal-panel">
        <button class="modal-close" type="button" data-close-modal aria-label="Close">x</button>
        <h2>History Automation Strategy</h2>
        <div id="item-timeline" class="timeline"></div>
        <div class="form-actions"><button class="btn light" data-close-modal type="button">Close</button></div>
    </div>
</div>

@include('components.automation-modal')
@endsection
