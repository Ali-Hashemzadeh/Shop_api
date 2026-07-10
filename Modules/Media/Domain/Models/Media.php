<?php

namespace Modules\Media\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $fillable = [
        'file_path',
        'mime_type',
        'file_size',
        'original_name',
    ];

    /**
     * A helpful accessor to grab the fully qualified, clickable local URL
     * directly off the model instance without messing with the Storage facade manually.
     */
    public function getUrlAttribute(): string
    {
        // Files are stored on the 'public' disk, whose configured url base
        // (APP_URL/storage) yields a fully qualified, domain-prefixed URL.
        return Storage::disk('public')->url($this->file_path);
    }
}
