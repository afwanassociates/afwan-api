<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workflow Steps
    |--------------------------------------------------------------------------
    |
    | The ordered steps each passport goes through, strictly one after the
    | other. "passport" and "medical" have their own modules; every later step
    | keeps its history in passport_step_records and is described here:
    |
    | - statuses: the label of each App\Enums\StepStatus for this step.
    | - fields: the record's form. "name" is a passport_step_records column,
    |   or a key in its "details" JSON when "in_details" is true. "phase" says
    |   which action asks for it: "start" (applying) or "result" (the outcome);
    |   start when left out. "required_for"
    |   lists the statuses that need the field; "rules" are extra Laravel
    |   validation rules; "not_future_for" lists statuses for which a date may
    |   not be after today.
    | - warning_days: valid_until within this many days counts as expiring soon.
    | - tabs (optional): the step page's tabs, each a list of record states
    |   (waiting = no record yet, in_process, rejected, completed, next_waiting
    |   = completed and the next step not started) and an order. A tab without
    |   a label takes its status label. Without tabs a step gets pending,
    |   in_process, completed and rejected.
    | - single_update (optional): the step is saved with one update request
    |   instead of start / result.
    | - date_from (optional): the field the record's date (step_date) is taken
    |   from, for a step without a date field of its own.
    | - list_hidden (optional): row fields this step's list leaves out.
    | - list_extras (optional): extra row fields on this step's list
    |   (visa_issue_date, visa_valid_until, bmet_dates = training_date,
    |   finger_date and clearance_date, flight_status = bmet_cleared,
    |   clearance_date, booking_date, confirmed_date, can_confirm, missing).
    | - starts_after (optional): the step may have records once this earlier
    |   step is completed, alongside the steps in between (flight runs with
    |   BMET after the visa); completing it still needs every earlier step.
    |   parallel_texts give the status line meanwhile.
    | - milestones / complete_action (optional): the step is saved with one
    |   action per milestone, each storing its date in "field", then the
    |   complete action (needs every milestone) instead of start / result.
    |   A field's phase is then the action that asks for it.
    | - result_date_from (optional): a completed result takes the record's
    |   date from this field (visa: the issue date).
    | - keep_date_as (optional): when an older in-process record is completed
    |   by a single update, its own date is kept in this details key.
    | - Tab state previous_open: still at the step before (any status).
    | - status_texts (optional): the passport's status line for a stage status
    |   at this step (e.g. "Visa submitted").
    | - A field's "after" names the field its date must follow (valid_until
    |   follows step_date unless set).
    |
    | Adding or renaming a step only needs a change here (plus any extra
    | validation rules in its fields).
    |
    */

    'steps' => [
        [
            'key' => 'passport',
            'label' => 'Passport',
            'short_label' => 'Passport',
            'enabled' => true,
        ],
        [
            'key' => 'medical',
            'label' => 'Medical',
            'short_label' => 'Medical',
            'enabled' => true,
        ],
        [
            'key' => 'calling',
            'label' => 'Calling / Work Permit',
            'short_label' => 'Calling',
            'enabled' => true,
            // No rejected path: enter the calling date, then the permit details.
            'statuses' => [
                'in_process' => 'Calling date entered',
                'completed' => 'Permitted',
            ],
            'fields' => [
                ['name' => 'receive_date', 'label' => 'Calling receive date', 'phase' => 'result', 'type' => 'date', 'in_details' => true, 'required_for' => ['completed'], 'not_future_for' => ['completed']],
                ['name' => 'issue_date', 'label' => 'Issued date', 'phase' => 'result', 'type' => 'date', 'in_details' => true, 'required_for' => ['completed'], 'not_future_for' => ['completed']],
                ['name' => 'valid_until', 'label' => 'Calling expiry', 'phase' => 'result', 'type' => 'date', 'required_for' => ['completed'], 'after' => 'details.issue_date'],
            ],
            // The step page's tabs, by record state: Permitted until the visa
            // submitted date is entered (next_waiting = done, next step not started).
            'tabs' => [
                ['key' => 'pending', 'label' => 'Pending', 'states' => ['waiting', 'in_process'], 'order' => 'medical_fit_asc'],
                ['key' => 'permitted', 'label' => 'Permitted', 'states' => ['next_waiting'], 'order' => 'completed_desc'],
            ],
            // Saved with one update (the three permit dates), not through
            // start / result. in_process exists only on older records that
            // had a calling date; new records are created completed.
            'single_update' => true,
            // The record's date is the calling receive date.
            'date_from' => 'receive_date',
            'keep_date_as' => 'calling_date',
            // Left out of this step's list rows.
            'list_hidden' => ['entry_mode', 'step_date', 'submitted_date'],
            'status_texts' => [
                'in_process' => 'Calling date entered',
            ],
            'warning_days' => 30,
        ],
        [
            'key' => 'visa',
            'label' => 'Visa',
            'short_label' => 'Visa',
            'enabled' => true,
            'statuses' => [
                'in_process' => 'Submitted',
                'completed' => 'Visa issued',
                'rejected' => 'Rejected',
            ],
            // The Visa issued form: issue date, expired date, then the
            // optional visa number.
            'fields' => [
                ['name' => 'step_date', 'label' => 'Visa submitted date', 'phase' => 'start', 'type' => 'date', 'required_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'issue_date', 'label' => 'Issue date', 'phase' => 'result', 'type' => 'date', 'in_details' => true, 'required_for' => ['completed'], 'not_future_for' => ['completed']],
                ['name' => 'valid_until', 'label' => 'Expired date', 'phase' => 'result', 'type' => 'date', 'required_for' => ['completed'], 'after' => 'details.issue_date'],
                ['name' => 'reference_no', 'label' => 'Visa number', 'phase' => 'result', 'type' => 'text', 'max' => 50, 'required_for' => []],
            ],
            // An issued visa's record date is its issue date.
            'result_date_from' => 'issue_date',
            // Not started yet shows under Calling's Permitted tab; the visa page starts
            // at the visa submitted date.
            'tabs' => [
                ['key' => 'pending', 'label' => 'Pending', 'states' => ['in_process'], 'order' => 'status_date_asc'],
                // Leaves once the flight is confirmed (the passport is done).
                ['key' => 'issued', 'states' => ['completed'], 'order' => 'completed_desc', 'drop_when_flight_done' => true],
                ['key' => 'rejected', 'states' => ['rejected'], 'order' => 'status_date_desc'],
            ],
            'status_texts' => [
                'waiting' => 'Permitted - enter visa submitted date',
                'in_process' => 'Visa submitted',
            ],
            'list_hidden' => ['entry_mode'],
            'warning_days' => 30,
        ],
        [
            'key' => 'bmet',
            'label' => 'BMET Clearance',
            'short_label' => 'BMET',
            'enabled' => true,
            // No rejected path: Training done and Finger done (any order), then
            // BMET clearance done. Older submitted records stay in Pending;
            // rejected ones are history only.
            'statuses' => [
                'in_process' => 'In progress',
                'completed' => 'BMET cleared',
            ],
            'fields' => [
                ['name' => 'training_certificate_date', 'label' => 'Training date', 'phase' => 'training', 'type' => 'date', 'in_details' => true, 'required_for' => [], 'not_future_for' => ['in_process', 'completed']],
                ['name' => 'finger_date', 'label' => 'Finger date', 'phase' => 'finger', 'type' => 'date', 'in_details' => true, 'required_for' => [], 'not_future_for' => ['in_process', 'completed']],
                ['name' => 'step_date', 'label' => 'Clearance date', 'phase' => 'clearance', 'type' => 'date', 'required_for' => ['in_process', 'completed']],
            ],
            // One action per milestone (POST / DELETE .../steps/bmet/{action}).
            'milestones' => [
                ['key' => 'training', 'label' => 'Training done', 'field' => 'training_certificate_date'],
                ['key' => 'finger', 'label' => 'Finger done', 'field' => 'finger_date'],
            ],
            'complete_action' => ['key' => 'clearance', 'label' => 'BMET clearance done'],
            'tabs' => [
                ['key' => 'pending', 'label' => 'Pending', 'states' => ['waiting', 'in_process'], 'order' => 'waiting_asc'],
                // Leaves once the flight is confirmed (the passport is done).
                ['key' => 'cleared', 'label' => 'BMET cleared', 'states' => ['completed'], 'order' => 'completed_desc', 'drop_when_flight_done' => true],
            ],
            'list_extras' => ['visa_issue_date', 'visa_valid_until', 'bmet_dates'],
            'warning_days' => 30,
        ],
        [
            'key' => 'flight',
            'label' => 'Flight',
            'short_label' => 'Flight',
            'enabled' => true,
            // Runs alongside BMET once the visa is issued: the booking date can
            // be saved before BMET is cleared; confirming needs both.
            'starts_after' => 'visa',
            // Booked (in process), then Flight confirmed (final). Cancelled
            // flights are history only.
            'statuses' => [
                'in_process' => 'Booked',
                'completed' => 'Flight done',
            ],
            'fields' => [
                // The booking date may be today or later (not before the visa issue date).
                ['name' => 'booking_date', 'label' => 'Flight date', 'phase' => 'booking', 'type' => 'date', 'in_details' => true, 'required_for' => ['in_process', 'completed']],
                ['name' => 'confirmed_date', 'label' => 'Confirmed date', 'phase' => 'confirm', 'type' => 'date', 'in_details' => true, 'required_for' => [], 'not_future_for' => ['completed']],
            ],
            // Pending: visa issued and not confirmed, BMET cleared or not
            // (previous_open = still at BMET).
            'tabs' => [
                ['key' => 'pending', 'label' => 'Pending', 'states' => ['previous_open', 'waiting', 'in_process'], 'order' => 'waiting_asc'],
                ['key' => 'confirmed', 'label' => 'Flight done', 'states' => ['completed'], 'order' => 'completed_desc'],
            ],
            // Saved with its own actions (POST .../steps/flight/booking and
            // .../confirm), not start / result.
            'single_update' => true,
            // A booked record is dated by its booking date, a confirmed one by
            // the day it was confirmed.
            'date_from' => 'booking_date',
            'result_date_from' => 'confirmed_date',
            // The status line while BMET and the flight run side by side
            // (:date = the booking date).
            'parallel_texts' => [
                'pending' => 'Visa issued - BMET and flight pending',
                'booked' => 'Flight booked (:date) - BMET pending',
                'cleared' => 'BMET cleared - flight booking date pending',
                'ready' => 'Ready to confirm flight',
            ],
            // Why Flight confirm is refused (missing: bmet, booking_date, or both).
            'confirm_messages' => [
                'bmet' => 'BMET clearance must be done before confirming the flight',
                'booking_date' => 'Enter the flight booking date before confirming the flight',
                'both' => 'BMET clearance and the flight booking date are required before confirming the flight',
            ],
            'list_extras' => ['visa_valid_until', 'flight_status'],
            'warning_days' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Passport Expiry Warning
    |--------------------------------------------------------------------------
    |
    | Warn when the passport expires less than this many months after the
    | flight departure date (or after today at the visa and BMET steps).
    |
    */

    'passport_expiry_warning_months' => 6,

    /*
    |--------------------------------------------------------------------------
    | Status Labels and Tones
    |--------------------------------------------------------------------------
    |
    | The "latest status" shown on the All Passports overview reads
    | "<step label>: <status label>" (e.g. "Visa: Submitted"); step records use
    | their step's own status labels above. The tone is the colour the SPA
    | uses for that status; unknown statuses get default_tone.
    |
    */

    'status_labels' => [
        'not_started' => 'Not Started',
        'pending' => 'Pending',
        'waiting' => 'Waiting',
        'unfit' => 'Unfit',
        'fit' => 'Fit - not marked',
        'expired' => 'Expired',
        'in_process' => 'In Process',
        'rejected' => 'Rejected',
        'completed' => 'Completed',
    ],

    'tones' => [
        'not_started' => 'amber',
        'fit' => 'amber',
        'unfit' => 'red',
        'rejected' => 'red',
        'pending' => 'amber',
        'waiting' => 'amber',
        'expired' => 'amber',
        'in_process' => 'blue',
        'completed' => 'green',
    ],

    'default_tone' => 'gray',

];
