<?php

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/ping', PingController::class);

