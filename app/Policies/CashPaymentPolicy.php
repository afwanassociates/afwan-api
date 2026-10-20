<?php

namespace App\Policies;

/**
 * accounts, admin and super_admin record and edit cash payments; only admin
 * and super_admin delete them.
 */
class CashPaymentPolicy extends AccountsEntryPolicy {}
