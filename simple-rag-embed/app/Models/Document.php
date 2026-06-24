<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table("document")]
#[Fillable(['filename'])]

class Document extends Model
{
    public function documentChunk(){
        return $this->hasMany(DocumentChunk::class,'document_id');
    }
}
