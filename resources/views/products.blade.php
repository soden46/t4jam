@extends('layouts.app', ['title' => $title])

@section('page', 'products')

@section('content')
<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Riset Produk Toped</h2>
            <p>Cari produk berdasarkan keyword atau category.</p>
        </div>
        <button type="button" class="btn primary" id="btn_cari_backlink">Cari Produk</button>
    </div>
    <form id="product_form" class="grid-2">
        <label>Cari produk berdasarkan keyword
            <input type="text" placeholder="Masukan Keyword" id="keyword">
        </label>
        <label>Cari produk berdasarkan category
            <select id="category" name="category">
                <option value="">Pilih Kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->external_id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
        <label>Min Harga
            <input id="min_price" type="number" placeholder="Min Harga">
        </label>
        <label>Max Harga
            <input id="max_price" type="number" placeholder="Max Harga">
        </label>
        <label>Minimum Terjual
            <input id="min_sold" type="number" placeholder="Minimum Terjual">
        </label>
        <label>Terakhir Di tambahkan
            <select name="last_added" id="last_added">
                <option value="">all date</option>
                <option value="7">7 hari Terakhir</option>
                <option value="14">14 hari Terakhir</option>
                <option value="30">30 hari Terakhir</option>
                <option value="90">90 hari Terakhir</option>
            </select>
        </label>
    </form>
</section>

<section class="panel">
    <div class="table-toolbar">
        <input id="search_domain" type="search" placeholder="Cari berdasarkan kata kunci">
        <select id="category_filter">
            <option value="">Pilih Kategori</option>
            @foreach ($categories as $category)
                <option value="{{ $category->external_id }}">{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="table-wrap">
        <table class="data-table" id="product_table">
            <thead>
                <tr>
                    <th>Nama Produk</th>
                    <th class="num">Harga</th>
                    <th class="num">Terjual</th>
                    <th class="num">Total Review</th>
                    <th class="num">Rating</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</section>

<div class="modal" id="product-modal" hidden>
    <div class="modal-panel">
        <button class="modal-close" data-close-modal type="button" aria-label="Close">x</button>
        <h2>Detail Produk</h2>
        <div id="product-detail"></div>
        <div class="form-actions"><button type="button" class="btn light" data-close-modal>Close</button></div>
    </div>
</div>
@endsection
