<div class="modal" id="automation-modal" hidden>
    <div class="modal-panel wide">
        <button class="modal-close" type="button" data-close-modal aria-label="Close">x</button>
        <form id="automation-form" class="stack-form">
            @csrf
            <input type="hidden" name="automation_id" id="automation_id">
            <input type="hidden" name="ad_account" id="modal_ad_account">
            <input type="hidden" name="campaign_id" id="modal_campaign_id">
            <input type="hidden" name="level" id="modal_level" value="campaign">
            <div class="center">
                <h1 id="automation-modal-title">Create Automation Budget</h1>
                <p class="muted">Setting ini akan dipakai BOT T4Jam untuk membaca metrik dan mengatur budget campaign.</p>
            </div>
            <div class="grid-2">
                <label>LP Funnel
                    <select name="budget_funnel_lp" id="budget_funnel_lp">
                        <option value="lp_to_wa">LP To WA</option>
                        <option value="lp_to_form">LP To Form</option>
                        <option value="lwa">LWA</option>
                    </select>
                </label>
                <label>Mode Automation
                    <select name="mode_automation" id="mode_automation">
                        <option value="default">Default T4Jam</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                </label>
                <label>Hold Budget Spend
                    <select name="hold_spend" id="hold_spend">
                        <option value="onhold">Default T4Jam</option>
                        <option value="bypass">No Hold X 3</option>
                        <option value="loss">Loss Doll</option>
                    </select>
                </label>
                <label>Conversion Event Tracking
                    <select name="budget_conversion" id="budget_conversion">
                        <option value="purchase">Event Conversion : Purchase</option>
                        <option value="add_to_cart">Event Conversion : ATC</option>
                        <option value="lead">Event Conversion : Lead (Prospek)</option>
                        <option value="add_payment_info">Event Conversion : Add Payment Info</option>
                        <option value="initiate_checkout">Event Conversion : Initiate Checkout</option>
                        <option value="contact_website">Event Conversion : Website Contact</option>
                        <option value="onsite_conversion.messaging_conversation_started_7d">Event Conversion : Chat Whatsapp</option>
                    </select>
                </label>
                <label>Starting Budget <span class="currency">IDR</span>
                    <input type="number" name="starting_budget" id="starting_budget" value="100000">
                    <small>Budget ini akan digunakan untuk reset ke budget awal.</small>
                </label>
                <label>Maximum Increasing Budget <span class="currency">IDR</span>
                    <input type="number" name="maximum_budget" id="maximum_budget" value="0">
                    <small>Biarkan 0 jika tidak ingin dibatasi.</small>
                </label>
                <label>CPR Cap <span class="currency">IDR</span>
                    <input type="number" name="cpr_cap" id="cpr_cap" value="7000">
                    <small>Batas maksimum CPR yang ditoleransi.</small>
                </label>
                <label>Running Period
                    <select name="period" id="period">
                        <option value="5">Setiap 5 Menit</option>
                        <option value="10" selected>Setiap 10 Menit (Recomended for Lp - Form)</option>
                        <option value="15">Setiap 15 Menit</option>
                        <option value="30">Setiap 30 Menit</option>
                        <option value="45">Setiap 45 Menit</option>
                        <option value="60">Setiap 60 Menit</option>
                    </select>
                </label>
            </div>
            <div class="switch-grid">
                <label class="switch-row"><input type="checkbox" name="cpr_pause" id="cpr_pause"> <span>Pause Campaign saat CPR Boncos ?</span></label>
                <label class="switch-row"><input type="checkbox" name="counter_cpr" id="counter_cpr"> <span>Aktifkan Lagi Iklan ?</span></label>
                <label class="switch-row" id="activation-row"><input type="checkbox" name="automation_activation" id="automation_activation" value="active" checked> <span>Automation Active</span></label>
                <label class="switch-row"><input type="checkbox" name="use_on_off" id="use_on_off"> <span>Terapkan On/Off BOT T4Jam?</span></label>
            </div>
            <div class="grid-3">
                <label>Pause CPR
                    <input type="number" name="pause_cpr_cap" id="pause_cpr_cap" value="70000">
                </label>
                <label>Jam "ON" BOT T4Jam
                    <input type="time" name="on_time" id="on_time" value="01:00">
                </label>
                <label>Jam "OFF" BOT T4Jam
                    <input type="time" name="off_time" id="off_time" value="21:00">
                </label>
            </div>
            <div class="form-actions">
                <button type="button" class="btn light" data-close-modal>Cancel</button>
                <button type="submit" class="btn primary"><span id="automation-submit-label">Create</span></button>
            </div>
        </form>
    </div>
</div>
