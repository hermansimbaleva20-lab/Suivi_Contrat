<?php
use Illuminate\Support\Facades\Route;
Route::get('/', fn () => response()->json(['name'=>'PRISE/PREDIRE API','version'=>'1.0']));
