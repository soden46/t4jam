@extends('layouts.app', ['title' => $title])

@section('page', 'ad-setups')

@section('content')
<section class="page-hero compact">
    <div>
        <span class="eyebrow">Meta Ads Workspace</span>
        <h1>Setup Iklan</h1>
        <p>Struktur campaign, ad set, creative, dan ad disiapkan dari satu workflow yang tersimpan di database.</p>
    </div>
    <div class="hero-stats">
        <div>
            <strong>{{ $setups->count() }}</strong>
            <span>Total Setup</span>
        </div>
        <div>
            <strong>{{ $accounts->count() }}</strong>
            <span>Ad Account</span>
        </div>
    </div>
</section>

@error('meta')<div class="alert danger">{{ $message }}</div>@enderror
@unless ($metaWritesEnabled)
    <div class="alert warning">Meta write mode belum aktif. Publish setup dan update budget hanya tersimpan di aplikasi.</div>
@endunless

<form method="POST" action="{{ route('ad-setups.store') }}" class="setup-form">
    @csrf
    <section class="panel form-section">
        <div class="section-head">
            <span class="section-number">01</span>
            <div>
                <h2>Workspace</h2>
                <p>Identitas setup dan akun iklan yang akan dipakai.</p>
            </div>
        </div>
        <div class="grid-2">
            <label>Nama Setup
                <input name="name" value="{{ old('name') }}" placeholder="Setup SQBI Braille - Landing Page">
            </label>
            <label>Ad Account
                <select name="ad_account_id" required>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->external_id }})</option>
                    @endforeach
                </select>
            </label>
        </div>
    </section>

    <section class="panel form-section">
        <div class="section-head">
            <span class="section-number">02</span>
            <div>
                <h2>Campaign</h2>
                <p>Objective dan status awal campaign Meta Ads.</p>
            </div>
        </div>
        <div class="grid-3">
            <label>Campaign Name
                <input name="campaign_name" value="{{ old('campaign_name') }}" required>
            </label>
            <label>Objective
                <select name="campaign_objective">
                    <option value="OUTCOME_SALES">Sales</option>
                    <option value="OUTCOME_LEADS">Leads</option>
                    <option value="OUTCOME_TRAFFIC">Traffic</option>
                    <option value="OUTCOME_ENGAGEMENT">Engagement</option>
                </select>
            </label>
            <label>Status Awal
                <select name="campaign_status">
                    <option value="PAUSED">PAUSED</option>
                    <option value="ACTIVE">ACTIVE</option>
                </select>
            </label>
        </div>
    </section>

    <section class="panel form-section">
        <div class="section-head">
            <span class="section-number">03</span>
            <div>
                <h2>Ad Set</h2>
                <p>Budget, penjadwalan, dan targeting utama.</p>
            </div>
        </div>
        <div class="grid-3">
            <label>Ad Set Name
                <input name="adset_name" value="{{ old('adset_name') }}" required>
            </label>
            <label>Daily Budget
                <input type="number" name="daily_budget" value="{{ old('daily_budget', 100000) }}" min="1000" required>
            </label>
            <label>Optimization Goal
                <select name="optimization_goal">
                    <option value="OFFSITE_CONVERSIONS">Offsite Conversions</option>
                    <option value="LINK_CLICKS">Link Clicks</option>
                    <option value="LEAD_GENERATION">Lead Generation</option>
                    <option value="REACH">Reach</option>
                </select>
            </label>
            <label>Billing Event
                <select name="billing_event">
                    <option value="IMPRESSIONS">Impressions</option>
                    <option value="LINK_CLICKS">Link Clicks</option>
                </select>
            </label>
            <label>Bid Strategy
                <select name="bid_strategy">
                    <option value="LOWEST_COST_WITHOUT_CAP">Lowest Cost Without Cap</option>
                    <option value="LOWEST_COST_WITH_BID_CAP">Lowest Cost With Bid Cap</option>
                    <option value="COST_CAP">Cost Cap</option>
                </select>
            </label>
            <label>Countries
                <input name="countries" value="{{ old('countries', 'ID') }}" placeholder="ID, MY, SG" required>
            </label>
            <label>Age Min
                <input type="number" name="age_min" value="{{ old('age_min', 18) }}" min="13" max="65" required>
            </label>
            <label>Age Max
                <input type="number" name="age_max" value="{{ old('age_max', 55) }}" min="13" max="65" required>
            </label>
            <label>Start Time
                <input type="datetime-local" name="start_time" value="{{ old('start_time') }}">
            </label>
            <label>End Time
                <input type="datetime-local" name="end_time" value="{{ old('end_time') }}">
            </label>
            <label class="span-2">Interest Targeting
                <textarea name="interests" placeholder="6003348453981|Sepatu&#10;6003384587151|Sneakers">{{ old('interests') }}</textarea>
                <small>Format: interest_id|nama, satu per baris. Bisa ambil dari menu Interest.</small>
            </label>
        </div>
    </section>

    <section class="panel form-section">
        <div class="section-head">
            <span class="section-number">04</span>
            <div>
                <h2>Creative & Ad</h2>
                <p>Copywriting, destination URL, dan CTA iklan.</p>
            </div>
        </div>
        <div class="grid-2">
            <label>Page ID
                <input name="page_id" value="{{ old('page_id') }}" placeholder="Facebook Page ID" required>
            </label>
            <label>Instagram Actor ID
                <input name="instagram_actor_id" value="{{ old('instagram_actor_id') }}" placeholder="Optional">
            </label>
            <label>Ad Name
                <input name="ad_name" value="{{ old('ad_name') }}" required>
            </label>
            <label>Creative Name
                <input name="creative_name" value="{{ old('creative_name') }}" required>
            </label>
            <label class="span-2">Primary Text
                <textarea name="message" required>{{ old('message') }}</textarea>
            </label>
            <label>Headline
                <input name="headline" value="{{ old('headline') }}" required>
            </label>
            <label>Description
                <input name="description" value="{{ old('description') }}">
            </label>
            <label>Landing Page URL
                <input type="url" name="link_url" value="{{ old('link_url') }}" placeholder="https://..." required>
            </label>
            <label>Call To Action
                <select name="call_to_action">
                    <option value="LEARN_MORE">Learn More</option>
                    <option value="SHOP_NOW">Shop Now</option>
                    <option value="SIGN_UP">Sign Up</option>
                    <option value="CONTACT_US">Contact Us</option>
                    <option value="WHATSAPP_MESSAGE">WhatsApp Message</option>
                </select>
            </label>
        </div>
    </section>

    <section class="panel submit-panel">
        <div>
            <h2>Simpan Setup</h2>
            <p>Draft bisa direvisi dulu; publish akan lanjut ke flow Meta sesuai konfigurasi write mode.</p>
        </div>
        <div class="form-actions">
            <button class="btn light" type="submit" name="publish" value="0">Save Draft</button>
            <button class="btn primary" type="submit" name="publish" value="1">Publish / Prepare Meta</button>
        </div>
    </section>
</form>

<section class="panel setup-history">
    <div class="panel-head">
        <div>
            <h2>Draft & Publish History</h2>
            <p>Semua setup tersimpan untuk revisi dan audit advertiser.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Setup</th>
                    <th>Ad Account</th>
                    <th>Status</th>
                    <th>Meta IDs</th>
                    <th>Last Error</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($setups as $setup)
                    @php
                        $badgeClass = match ($setup->status) {
                            'published' => 'active',
                            'publishing' => 'ready',
                            'ready' => 'ready',
                            'failed' => 'failed',
                            default => 'draft',
                        };
                    @endphp
                    <tr>
                        <td data-label="Setup"><strong>{{ $setup->name }}</strong><br><small>{{ $setup->campaign_name }}</small></td>
                        <td data-label="Ad Account">{{ $setup->adAccount->name }}</td>
                        <td data-label="Status"><span class="badge {{ $badgeClass }}">{{ $setup->status }}</span></td>
                        <td data-label="Meta IDs">
                            <small>Campaign: {{ $setup->meta_campaign_id ?: '-' }}</small><br>
                            <small>Ad Set: {{ $setup->meta_adset_id ?: '-' }}</small><br>
                            <small>Ad: {{ $setup->meta_ad_id ?: '-' }}</small>
                        </td>
                        <td data-label="Last Error">{{ $setup->last_error ?: '-' }}</td>
                        <td data-label="Action">
                            @if ($setup->status === 'publishing')
                                <span class="muted">Queued</span>
                            @elseif ($setup->status !== 'published')
                                <form method="POST" action="{{ route('ad-setups.publish', $setup) }}">
                                    @csrf
                                    <button class="btn light-primary" type="submit">Publish</button>
                                </form>
                            @else
                                <span class="muted">Done</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="center muted">Belum ada setup iklan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
