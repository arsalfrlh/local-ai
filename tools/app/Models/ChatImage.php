<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatImage extends Model
{
    protected $table = "chat_images";
    protected $fillable = ['message_id','image_name','image_path'];
}
