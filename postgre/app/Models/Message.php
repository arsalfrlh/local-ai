<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Override;

class Message extends Model
{
    protected $table = "messages";
    protected $fillable = ['chat_room_id','role','message','is_rag'];

    #[Override]
    protected function casts()
    {
        return [
            'is_rag' => 'boolean'
        ];
    }
}
