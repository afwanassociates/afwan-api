<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Medical Validity
    |--------------------------------------------------------------------------
    |
    | A fit result is valid for this many calendar months after the medical
    | date (month ends do not overflow: 30 November + 3 months = 28 February).
    | A fit passport is "expiring soon" when it has this many days or fewer left.
    |
    */

    'validity_months' => 3,

    'expiring_soon_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | Business Timezone
    |--------------------------------------------------------------------------
    |
    | "Today" for medical dates and statuses is the calendar date here, not UTC.
    |
    */

    'timezone' => 'Asia/Dhaka',

];
