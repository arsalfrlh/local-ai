<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentCompanyChunk extends Model
{
    protected $table = "document_company_chunks";
    protected $fillable = ['document_company_id','chunk_index','content'];
}
