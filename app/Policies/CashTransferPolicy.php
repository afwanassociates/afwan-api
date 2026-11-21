<?php

namespace App\Policies;

/**
 * accounts, admin and super_admin record and edit cash transfers; only admin
 * and super_admin delete them.
 */
class CashTransferPolicy extends AccountsEntryPolicy {}
