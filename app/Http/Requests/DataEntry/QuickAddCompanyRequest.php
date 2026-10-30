<?php

namespace App\Http\Requests\DataEntry;

/**
 * Quick Add a company from the Add Passport page: the same three required
 * fields as the admin Companies CRUD (name, country and quota).
 */
class QuickAddCompanyRequest extends StoreCompanyRequest {}
