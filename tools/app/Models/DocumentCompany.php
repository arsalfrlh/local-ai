<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentCompany extends Model
{
    protected $table = "document_companies";
    protected $fillable = ['file_name','file_path','file_type'];

    function documentCompanyChunk(){
        return $this->hasMany(DocumentCompanyChunk::class,'document_company_id');
    }
}
