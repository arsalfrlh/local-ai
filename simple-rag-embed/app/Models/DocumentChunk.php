<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table("document_chunk")]
#[Fillable(['document_id','content','embedding'])]

class DocumentChunk extends Model
{
    //
}
