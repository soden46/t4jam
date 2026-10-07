# Automation Budget: reference parity audit

Observed on 2026-10-05 (Asia/Jakarta). Reference:
https://t4jam.santuiaja.com/automation-task/

Updated 2026-10-07: the user requested alignment with the supplied chat:
starting at 77,000, 2 leads -> 120,000; 2 more leads -> 248,832.
The first complete pair targets 120,000 when the processed-result marker is
zero and the current budget is lower. Otherwise, each pair earns four levels
of `max(100000, round(current_budget * 1.2))`, rounded at each level.
The resulting totals are 2 results -> 120,000; 4 -> 248,832; 6 -> 515,978.
The budget-history image shows intermediate amounts and processing-status
transitions without lead counts. Its intermediate budgets are calculated locally;
only the final budget is sent in one Meta write per evaluation. The four-level
continuation generalizes the chat's 120,000 -> 248,832 transition; later lead
totals are calculated local behavior, not separately observed reference events.
This is not proof of complete parity. No reference-account settings or Meta
budgets were changed during inspection.

## Verified reference behavior

Read-only inspection covered the dashboard/create form, automation/update form,
their delivered JavaScript, eight existing task configurations, and forty history
entries per task. Authentication credentials and session state are not included.
No budget/status/create/update/delete operation was submitted to the reference.

All eight observed tasks use campaign level, LP To Form, Default T4Jam mode,
Loss Doll, Initiate Checkout, a ten-minute running period, CPR Cap 35,000, and an
unlimited maximum budget (0). Findings from these tasks are not proof of behavior
for other funnels, modes, conversions, or ad-set level.

### Conversion-triggered increases

For one campaign, its rendered history on 2026-10-04 shows:

| Time (WIB) | Previous results | Current results | Spend | Reported CPR | Outcome |
| --- | ---: | ---: | ---: | ---: | --- |
| 18:20 | 1 | 2 | 35,689 | 17,844 | Budget increased to 120,000 |
| 18:30 | 2 | 2 | Not recorded in this event | 18,272 | No increase; results unchanged |
| 18:40 | 2 | 3 | 37,537 | 12,512 | Budget increased to 172,800 |

A second campaign reports results 1 -> 2, spend 36,657, CPR 18,328, and a
budget increase to 120,000 at 18:30 WIB that day.

Consequences established by these events:

- A universal 72-hour delay between automatic increases is incompatible with
  the observed 20-minute interval.
- A universal minimum of three conversions is incompatible with an increase at
  two results.
- The reference checks for additional results and does not increase again when
  the observed result count remains unchanged.
- The move from 120,000 to 172,800 is a 44% increase. It is arithmetically equal
  to two compounded 20% increases, but these events do not establish the general
  formula, the definition of a level, or how many levels each conversion earns.
- The budget immediately before the first increase is not present in the
  available history. The current starting budget of 75,000 is not evidence of
  that previous budget.
- Reported CPR in the two even-result examples is consistent with truncation
  rather than the previous Laravel rounding. It does not by itself establish
  whether the backend divides spend by results or casts a Meta CPR field.

History timestamps from `/get-history-log/` are converted from UTC to
Asia/Jakarta by the reference's `convertToWIB()` before display. The table above
uses the rendered timestamps.

### Settings and controls

- CPR Cap is separate from the numeric field shown when the CPR-pause toggle is
  enabled. The latter is submitted as `cpr_pause_cap` and read as `pause_cpr_cap`.
  Logs explicitly identify this pause option when a campaign is paused.
- The reactivate toggle describes recovery when CPR meets CPR Cap. The visible
  reference does not expose a separate Resume CPR field as the previous Laravel form did.
- Current task configurations use pause thresholds 34,555 or 34,444 independently
  from CPR Cap 35,000. A freshly opened create form defaults its pause field to
  70,000, while CPR Cap for LP To WA defaults to 7,000.
- Choosing LP To Form exposes the Default/Hybrid mode selector and changes the
  form's CPR Cap default to 36,000. Other funnels hide the mode selector and set
  the default to 7,000. These are client-side form defaults, not a verified
  definition of backend scaling rules.
- Hold options are Default T4Jam (`onhold`), No Hold (`bypass`; creation form
  labels it No Hold X 3), and Loss Doll (`loss`). The numeric spend rules for
  `onhold` and `bypass` are not exposed by these labels.
- The Hybrid row controls call increase/decrease by two levels. Those reference
  endpoints mutate budgets even though they use GET; they were not invoked.
- Starting Budget is described as the reset budget. Maximum Increasing Budget
  limits increases; zero means unlimited.
- Running Period options are 5, 10, 15, 30, 45, and 60 minutes. A new form selects
  5 minutes; the inspected existing tasks select 10 minutes.
- ON/OFF help describes when the bot runs. It does not alone establish whether
  the reference also pauses/activates Meta campaigns at those boundaries.

## Implemented Laravel flow

- Default mode evaluates fresh Insights at the configured running period. Each
  two additional results from the selected conversion earn one automatic increase.
  With a zero processed-result marker and a budget below 120,000, the first pair
  targets 120,000, subject to Maximum Budget. Otherwise, each pair increases four
  levels. Each level multiplies the current budget by 1.2, rounds to integer IDR,
  and applies a 100,000 minimum. Starting at 77,000, pair totals are
  120,000 -> 248,832 -> 515,978. A higher current budget is never lowered to the
  initial target; for example, 200,000 becomes 414,720 after one pair.
  The previous 72-hour, three-result, and 80%-of-cap gates are removed.
  CPR must be strictly below CPR Cap.
- Separate processed-result state is scoped to conversion and account-local day
  for `today` Insights (otherwise to the configured preset). Display/reconciliation
  refreshes cannot consume it. Lower/corrected counts do not reduce the marker.
  Out-of-order snapshots cannot increase budget. Changing conversion clears it;
  manually resetting budget does not permit reuse of consumed results.
- Multiple newly observed pairs are calculated together and sent in one Meta
  write. A successful increase consumes only complete pairs; result 5 consumes
  4, leaving one for result 6. Failed or unconfirmed Meta writes leave all pairs
  unconsumed, so they can retry. Existing markers, including odd counts, remain
  intact and require two new results; deployment does not replay past results.
  With `today` Insights, a new account-local day starts a new pair counter without
  resetting the current budget. All budget mutations use the existing task lock.
- New `pause_cpr_limit` stores the separate Pause CPR Cap. Pause uses `>=` and
  takes priority over scaling. Recovery uses CPR Cap, constrained below the
  pause threshold to avoid immediately pausing again. Budget never triggers pause.
- Legacy tasks keep `cpr_cap` as their pause threshold and `pause_cpr_cap` as
  their old recovery threshold until saved through the new form. The migration
  does not reinterpret old recovery values. It baselines existing metric counts
  to prevent deployment itself from reusing already observed conversions.
- The form defaults to five minutes, shows mode only for LP To Form, selects
  CPR Cap 36,000 for LP To Form / 7,000 otherwise, and shows the independent
  pause field when its toggle is enabled. Editing preserves saved cap values.
- Hybrid rows expose increase/decrease by two levels through authenticated POST
  requests, with ownership, pending-action, maximum-budget, starting-budget-floor,
  disabled-write, and confirmed-provider-write checks.
- Existing daily recovery, ON/OFF scheduling, Meta status eligibility, and local
  polling behavior are retained. CPR display and evaluation truncate integer
  spend/results consistently with the observed histories.

## Requested scaling and remaining inferred policies

`config/automation.php` and `AutomationScalingPolicy` isolate these numbers.
The 2026-10-07 user instruction takes priority over the earlier observed
one-result increase; the historical reference observations above are retained
as evidence of that earlier behavior.

| Policy | Local implementation | Evidence / limitation |
| --- | --- | --- |
| Automatic result batch | 2 new results | User explicitly requested an increase every 2 leads |
| First-level minimum | 100,000 | Latest user image explicitly shows 77,000 -> 100,000; generic minimum inferred from this transition |
| Initial automatic increase | 120,000 if the processed-result marker is zero and the current budget is lower | Chat specifies 2 leads -> 120,000 starting at 77,000; applies Maximum Budget |
| Subsequent automatic increase per pair | Four levels: `max(100000, round(budget * 1.2))` per level | Matches the chat's next 2 leads -> 248,832 from 120,000; later pairs extrapolate this formula |
| Hybrid manual increase | Two steps of 20%, with first-level floor 100,000 | Existing manual controls retained |
| Result jump | Process all complete new pairs in one write; retain the odd remainder | Local catch-up policy consistent with every-2-results instruction |
| Hold | Default requires spend >= budget; No Hold X3 requires spend * 3 >= budget; Loss Doll bypasses spend gate | Labels observed, numerical behavior inferred |
| Hybrid | Manual scaling; CPR protection/recovery remain automatic | Buttons observed; automatic Hybrid behavior unavailable |
| Funnels | Same numeric scaling policy for all funnels | Histories only establish LP To Form / Default / Loss Doll behavior |
| Equality | Scale CPR < cap; pause CPR >= pause limit; recovery CPR <= safe cap | Exact reference equality behavior unavailable |
| Counter scope | Account-local day and conversion | Reference attribution/day-reset implementation unavailable |

The authenticated UI and accessible histories do not reveal a universal budget
formula, Hybrid automation, Hold conditions, or funnel-specific rules. These
choices are reviewable local behavior, not claims about hidden source code.

## Validation and rollout

`AutomationReferenceFlowTest` exercises results 1 -> 2 -> 2 -> 3 -> 4 -> 5 -> 6,
including exact outgoing budgets 120,000, 248,832, and 515,978 from a starting
budget of 77,000, using fake Meta
responses. It also covers count jumps and odd remainders, legacy odd markers,
maximum budgets, a 100,000 starting budget, existing higher budgets, integer
rounding, failed writes/retries, independent
pause/recovery thresholds, metrics refreshes, day rollover, Hold, and Hybrid
ownership/budget boundaries.
Existing automation tests retain status, locking, stale-metric, disabled-write,
reconciliation, scheduling, and rate-limit checks.

Deploy code and run `php artisan migrate --force` before restarting workers on
the new code, then rebuild assets and refresh cached configuration. The migration
adds result state and `pause_cpr_limit`; no existing legacy threshold is rewritten.
The 2026-10-07 pair-scaling update needs no additional migration; it reuses that
state without rewriting existing markers. Refresh cached configuration and restart
existing workers to pick up the new policy.
Local tests and browser checks do not prove live Meta success or full parity for
unobserved reference modes. No deployment or live budget mutation was performed.
