<?php

namespace Webkul\GreenApi\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class GreenApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/green-api.php', 'green-api');
        $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');
    }

    public function boot(): void
    {
        $this->app->register(ModuleServiceProvider::class);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'green_api');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'green_api');
        $this->loadRoutesFrom(__DIR__.'/../Routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/webhooks.php');

        view()->composer('green_api::lead-action', function ($view) {
            $view->with('greenApiPhones', collect($view->getData()['lead']->person->contact_numbers ?? [])->pluck('value')->filter());
        });

        Event::listen('admin.leads.view.actions.after', function ($event) {
            $event->addTemplate('green_api::lead-action');
        });
    }
}
