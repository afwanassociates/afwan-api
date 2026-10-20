<?php

namespace App\Policies;

/**
 * accounts, admin and super_admin record and edit cash receipts; only admin
 * and super_admin delete them.
 */
class CashReceiptPolicy extends AccountsEntryPolicy {}
