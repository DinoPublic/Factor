<?php

use App\Http\Controllers\Api\LecturaController;
use Illuminate\Support\Facades\Route;

Route::middleware('distribuidora')->group(function () {
    Route::post('/lecturas', [LecturaController::class, 'store']);
});
