<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\CompressionController;

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
});