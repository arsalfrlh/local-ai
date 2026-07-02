<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentChunk extends Model
{
    protected $table = "document_chunks";
    protected $fillable = ['document_id','index_chunk','content'];
}
