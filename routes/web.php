<?php

use Illuminate\Support\Facades\Route;




Route::get('/', function () {
    return view('welcome');
});
Route::get('/myinfo', fn () => view('port'))->name('myinfo');