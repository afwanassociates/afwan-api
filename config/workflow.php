<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workflow Steps
    |--------------------------------------------------------------------------
    |
    | The ordered steps each passport goes through, shown as the SPA's step bar.
    | A disabled step is shown as locked. Step 3 can be switched on and renamed
    | here (or through the env keys) without code changes; it becomes "ready"
    | for a passport once the step before it is passed.
    |
    */

    'steps' => [
        ['key' => 'passport', 'label' => 'Passport', 'enabled' => true],
        ['key' => 'medical', 'label' => 'Medical', 'enabled' => true],
        [
            'key' => 'step3',
            'label' => env('WORKFLOW_STEP3_LABEL', 'Step 3'),
            'enabled' => (bool) env('WORKFLOW_STEP3_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Labels and Tones
    |--------------------------------------------------------------------------
    |
    | The "latest status" shown on the All Passports overview reads
    | "<step label>: <status label>" (e.g. "Medical: Unfit"). The tone is the
    | colour the SPA uses for that status; unknown statuses get default_tone.
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
