@extends('layouts.app', ['title' => $title])

@section('page', 'interest')

@section('content')
<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Interest Explore Tools</h2>
            <p>Masukan list keyword disini</p>
        </div>
        <button id="cari_interest" type="button" class="btn primary">Cari Interest</button>
    </div>
    <label class="form-label">Masukan list keyword disini</label>
    <input type="text" class="large-input" placeholder="Masukan Keyword" id="keyword_interest">
</section>

<section class="panel">
    <div class="table-toolbar">
        <select id="interest_topic_filter">
            <option value="">Show All</option>
            <option value="Shopping and fashion">Shopping and fashion</option>
            <option value="Business and industry">Business and industry</option>
        </select>
        <input id="search_domain" type="search" placeholder="find by keyword">
        <button type="button" class="btn light" id="copy_interest">Copy All</button>
    </div>
    <div class="table-wrap">
        <table class="data-table" id="interest_table">
            <thead>
                <tr>
                    <th><input type="checkbox" data-check-all></th>
                    <th>name</th>
                    <th>Audiance</th>
                    <th>Topic</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</section>
@endsection
