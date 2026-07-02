<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRoom extends Model
{
    protected $table = "chat_rooms";
    protected $fillable = ['title','user_id'];

    function user(){
        return $this->belongsTo(User::class,'user_id');
    }

    function document(){
        return $this->hasMany(Document::class,'chat_room_id');
    }

    function message(){
        return $this->hasMany(Message::class,'chat_room_id');
    }
}
