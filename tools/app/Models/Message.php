<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $table = "messages";
    protected $fillable = ['chat_room_id','role','message'];

    function document(){
        return $this->hasMany(Document::class,'message_id');
    }

    function chatImage(){
        return $this->hasMany(ChatImage::class,'message_id');
    }
}
