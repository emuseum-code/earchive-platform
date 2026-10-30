<?php

namespace App\Models;

use App\Enums\ApiDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['api_status' => ApiDelivery::class, 'api_response' => 'array'];
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(Export::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }
}
