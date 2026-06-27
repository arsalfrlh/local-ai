<?php

use App\Http\Controllers\AuthApiController;
use App\Http\Controllers\BarangApiController;
use App\Http\Controllers\MessageApiController;
use App\Http\Controllers\QdrantApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('/barang',BarangApiController::class);
Route::get("/barang/{id}/recommendation",[BarangApiController::class,'recommendation']);

Route::apiResource('/qdrant',QdrantApiController::class);
Route::get('/qdrant/{id}/recommendation',[QdrantApiController::class,'recommendation']);

Route::post('/login',[AuthApiController::class,'login']);
Route::post('/register',[AuthApiController::class,'register']);

Route::middleware(['auth:sanctum'])->group(function(){
    Route::apiResource('/message',MessageApiController::class);
    Route::post('/create-room',[MessageApiController::class,'createRoom']);
});