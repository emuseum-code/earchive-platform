<?php

namespace App\Providers;

use App\Support\Activity;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // у БД UTC, у таблицях, картках і полях дат Filament: київський час
        \Filament\Support\Facades\FilamentTimezone::set(\App\Support\Tz::ZONE);
        Model::preventLazyLoading(! app()->isProduction());
        Model::automaticallyEagerLoadRelationships();
        Relation::enforceMorphMap([
            'user' => \App\Models\User::class,
            'museum' => \App\Models\Museum::class,
            'book' => \App\Models\Book::class,
            'scan' => \App\Models\Scan::class,
            'record' => \App\Models\Record::class,
            'ai_check' => \App\Models\AiCheck::class,
            'adaptation' => \App\Models\Adaptation::class,
            'export' => \App\Models\Export::class,
            'sync_run' => \App\Models\SyncRun::class,
            'processing_job' => \App\Models\ProcessingJob::class,
            'template' => \App\Models\RecognitionTemplate::class,
            'rule' => \App\Models\AdaptationRule::class,
            'unmapped_folder' => \App\Models\UnmappedFolder::class,
        ]);
        Event::listen(Login::class, function (Login $e) {
            $e->user->forceFill(['last_login_at' => now()])->saveQuietly();
            Activity::log('auth.login', $e->user, null, [], $e->user);
        });
    }
}
