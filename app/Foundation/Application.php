<?php

namespace App\Foundation;

use Illuminate\Foundation\Application as BaseApplication;

/**
 * The Laravel application with its namespace set, so Laravel never has to read composer.json
 * to find it (artisan does, to load app/Console/Commands). The deploy branch is shipped
 * without composer.json, because Hostinger runs "composer install" when it finds one and that
 * fails there (proc_open is disabled); see .github/workflows/deploy.yml.
 */
class Application extends BaseApplication
{
    /**
     * Same as "autoload.psr-4" in composer.json.
     *
     * @var string
     */
    protected $namespace = 'App\\';
}
