<?php

use App\Http\Controllers\AiApiController;
use App\Http\Controllers\AuthApiController;
use App\Http\Controllers\ChatApiController;
use App\Http\Controllers\DocumentCompanyApiController;
use App\Http\Controllers\MessageApicontroller;
use App\Http\Controllers\OrderApiController;
use App\Http\Controllers\ProductApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login',[AuthApiController::class,'login']);
Route::post('/register',[AuthApiController::class,'register']);

Route::middleware('auth:sanctum')->group(function(){
    Route::apiResource('/product',ProductApiController::class);
    Route::apiResource('/order',OrderApiController::class);
    Route::post('/ask',[AiApiController::class,'ask']);
    Route::apiResource('/document-company',DocumentCompanyApiController::class);
    Route::apiResource('/message',MessageApicontroller::class);
    Route::apiResource('/chat',ChatApiController::class);
});