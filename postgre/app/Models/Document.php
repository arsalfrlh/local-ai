<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = "documents";
    protected $fillable = ['user_id','chat_room_id','file_name','file_path','file_type'];

    function documentChunk(){
        return $this->hasMany(DocumentChunk::class,'document_id');
    }
}
