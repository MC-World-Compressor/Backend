<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\CompressionController;
use App\Http\Controllers\API\ServidorController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/subidas', function () {
    return view('subidas');
});

Route::get('/uploads', function () {
    return view('subidas');
});



//API

Route::middleware('api')->prefix('api')->group(function () {
    Route::get('/test', function () {
        return response()->json(['status' => 'ok']);
    });
    
    Route::post('/comprimir', [CompressionController::class, 'compressWorld']);
    Route::get('/cola', [ServidorController::class, 'getCola']);
    Route::post('/subir', [ServidorController::class, 'subirMundo']);
    Route::get('/status/{id}', [ServidorController::class, 'getStatus']);
    
});