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
    |   or a key in its "details" JSON when "in_details" is true. "required_for"
    |   lists the statuses that need the field; "rules" are extra Laravel
    |   validation rules; "not_future_for" lists statuses for which a date may
    |   not be after today.
    | - warning_days: valid_until within this many days counts as expiring soon.
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
            'statuses' => [
                'in_process' => 'Applied',
                'completed' => 'Approved',
                'rejected' => 'Rejected',
            ],
            'fields' => [
                ['name' => 'reference_no', 'label' => 'Permit / calling number', 'type' => 'text', 'max' => 100, 'required_for' => ['completed']],
                ['name' => 'step_date', 'label' => 'Date', 'type' => 'date', 'required_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'valid_until', 'label' => 'Valid until', 'type' => 'date', 'required_for' => []],
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
                ['name' => 'reference_no', 'label' => 'Visa number', 'type' => 'text', 'max' => 100, 'required_for' => ['completed']],
                ['name' => 'step_date', 'label' => 'Date', 'type' => 'date', 'required_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'valid_until', 'label' => 'Valid until', 'type' => 'date', 'required_for' => []],
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
                ['name' => 'reference_no', 'label' => 'Clearance number', 'type' => 'text', 'max' => 100, 'required_for' => ['completed']],
                ['name' => 'step_date', 'label' => 'Date', 'type' => 'date', 'required_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'training_certificate_date', 'label' => 'Training certificate date', 'type' => 'date', 'in_details' => true, 'required_for' => [], 'not_future_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'finger_date', 'label' => 'Finger date', 'type' => 'date', 'in_details' => true, 'required_for' => [], 'not_future_for' => ['in_process', 'completed', 'rejected']],
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
                ['name' => 'step_date', 'label' => 'Booking date', 'type' => 'date', 'required_for' => ['in_process', 'completed', 'rejected']],
                ['name' => 'airline', 'label' => 'Airline', 'type' => 'text', 'max' => 100, 'in_details' => true, 'required_for' => ['in_process', 'completed']],
                ['name' => 'flight_no', 'label' => 'Flight number', 'type' => 'text', 'max' => 20, 'in_details' => true, 'required_for' => ['in_process', 'completed'], 'rules' => ['regex:/^[A-Za-z0-9]+$/']],
                ['name' => 'departure_date', 'label' => 'Departure date', 'type' => 'date', 'in_details' => true, 'required_for' => ['in_process', 'completed'], 'not_future_for' => ['completed']],
                ['name' => 'ticket_pnr', 'label' => 'Ticket PNR', 'type' => 'text', 'max' => 20, 'in_details' => true, 'required_for' => []],
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
        'pending' => 'Pending',
        'waiting' => 'Waiting',
        'unfit' => 'Unfit',
        'expired' => 'Expired',
        'in_process' => 'In Process',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
        'departed' => 'Departed',
    ],

    'tones' => [
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
