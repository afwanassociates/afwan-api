<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\DataEntry\StoreCompanyRequest as BaseStoreCompanyRequest;

/**
 * An admin creates a company with its name, country and quota. New companies
 * are always active; they are deactivated from the list.
 */
class StoreCompanyRequest extends BaseStoreCompanyRequest {}
