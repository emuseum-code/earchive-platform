<?php

namespace App\Console\Commands;

use App\Services\QueueManager;
use Illuminate\Console\Command;

class RecoverQueue extends Command
{
    protected $signature = 'archive:recover {--stale=15 : Хвилин, після яких «виконується» вважається завислим}';

    protected $description = 'Відновити чергу обробки після збою або перезапуску';

    public function handle(QueueManager $q): int
    {
        $this->info('Перевідправлено завдань: '.$q->recover((int) $this->option('stale')));

        return self::SUCCESS;
    }
}
