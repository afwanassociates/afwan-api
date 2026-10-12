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
                ['name' => 'valid_until', 'label' => 'Expiry date', 'phase' => 'result', 'type' => 'date', 'required_for' => ['completed'], 'after' => 'details.issue_date'],
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
            'fields' => [
                ['name' => 'reference_no', 'label' => 'Visa number', 'phase' => 'result', 'type' => 'text', 'max' => 100, 'required_for' => ['completed']],
                ['name' => 'step_date', 'label' => 'Visa submitted date', 'phase' => 'start', 'type' => 'date', 'required_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'valid_until', 'label' => 'Valid until', 'phase' => 'result', 'type' => 'date', 'required_for' => []],
            ],
            // Not started yet shows under Calling's Permitted tab; the visa page starts
            // at the visa submitted date.
            'tabs' => [
                ['key' => 'pending', 'label' => 'Pending', 'states' => ['in_process'], 'order' => 'status_date_asc'],
                ['key' => 'issued', 'states' => ['completed'], 'order' => 'completed_desc'],
                ['key' => 'rejected', 'states' => ['rejected'], 'order' => 'status_date_desc'],
            ],
            'status_texts' => [
                'waiting' => 'Permitted - enter visa submitted date',
                'in_process' => 'Visa submitted',
            ],
            'warning_days' => 30,
        ],
        [
            'key' => 'bmet',
            'label' => 'BMET Clearance',
            'short_label' => 'BMET',
            'enabled' => true,
            'statuses' => [
                'in_process' => 'Submitted',
                'completed' => 'Cleared',
                'rejected' => 'Rejected',
            ],
            'fields' => [
                ['name' => 'reference_no', 'label' => 'Clearance number', 'phase' => 'result', 'type' => 'text', 'max' => 100, 'required_for' => ['completed']],
                ['name' => 'step_date', 'label' => 'Date', 'phase' => 'start', 'type' => 'date', 'required_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'training_certificate_date', 'label' => 'Training certificate date', 'phase' => 'start', 'type' => 'date', 'in_details' => true, 'required_for' => [], 'not_future_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'finger_date', 'label' => 'Finger date', 'phase' => 'start', 'type' => 'date', 'in_details' => true, 'required_for' => [], 'not_future_for' => ['in_process', 'completed', 'rejected']],
            ],
            'warning_days' => 30,
        ],
        [
            'key' => 'flight',
            'label' => 'Flight',
            'short_label' => 'Flight',
            'enabled' => true,
            'statuses' => [
                'in_process' => 'Ticket booked',
                'completed' => 'Departed',
                'rejected' => 'Cancelled',
            ],
            'fields' => [
                ['name' => 'step_date', 'label' => 'Booking date', 'phase' => 'start', 'type' => 'date', 'required_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'airline', 'label' => 'Airline', 'phase' => 'start', 'type' => 'text', 'max' => 100, 'in_details' => true, 'required_for' => ['in_process', 'completed']],
                ['name' => 'flight_no', 'label' => 'Flight number', 'phase' => 'start', 'type' => 'text', 'max' => 20, 'in_details' => true, 'required_for' => ['in_process', 'completed'], 'rules' => ['regex:/^[A-Za-z0-9]+$/']],
                ['name' => 'departure_date', 'label' => 'Departure date', 'phase' => 'start', 'type' => 'date', 'in_details' => true, 'required_for' => ['in_process', 'completed'], 'not_future_for' => ['completed']],
                ['name' => 'ticket_pnr', 'label' => 'Ticket PNR', 'phase' => 'start', 'type' => 'text', 'max' => 20, 'in_details' => true, 'required_for' => []],
            ],
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
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
        'departed' => 'Departed',
    ],

    'tones' => [
        'not_started' => 'amber',
        'fit' => 'amber',
        'unfit' => 'red',
        'rejected' => 'red',
        'cancelled' => 'red',
        'pending' => 'amber',
        'waiting' => 'amber',
        'expired' => 'amber',
        'in_process' => 'blue',
        'completed' => 'green',
        'departed' => 'green',
    ],

    'default_tone' => 'gray',

];
