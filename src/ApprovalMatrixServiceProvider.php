<?php

namespace Bizzsol\ApprovalMatrix;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ApprovalMatrixServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/approvalmatrix.php', 'approvalmatrix');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'approvalmatrix');

        // Only the app that owns the shared DB schema loads the migrations (the DB is shared by sibling apps).
        if (config('approvalmatrix.load_migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Only the app that hosts the admin screens registers routes; consumers use the services only.
        if (! $this->app->routesAreCached()) {
            if (config('approvalmatrix.admin_ui')) {
                Route::middleware('web')->group(__DIR__.'/../routes/web.php'); // builder, simulator, org feeds + inbox
            } elseif (config('approvalmatrix.inbox_ui')) {
                Route::middleware('web')->group(__DIR__.'/../routes/inbox.php'); // approver inbox only
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([\Bizzsol\ApprovalMatrix\Console\RemindOverdue::class, \Bizzsol\ApprovalMatrix\Console\CoverageCommand::class]);
            $this->publishes([__DIR__.'/../config/approvalmatrix.php' => config_path('approvalmatrix.php')], 'approvalmatrix-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'approvalmatrix-migrations');
        }
    }
}
