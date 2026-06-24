<?php

use App\Http\Controllers\BarangApiController;
use App\Http\Controllers\DocumentApiController;
use App\Http\Controllers\RagApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/search',[BarangApiController::class,'search']);
Route::post('/barang',[BarangApiController::class,'store']);

Route::post('/knowledge',[RagApiController::class,'storeKnowLedge']);
Route::post('/ask',[RagApiController::class,'ask']);

// Route::post('/upload',[DocumentApiController::class,'uploadPdf']);
Route::post('/ask/pdf',[DocumentApiController::class,'askPdf']);
Route::post('/upload/file', [DocumentApiController::class,'uploadFile']);