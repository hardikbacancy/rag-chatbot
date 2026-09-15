<?php

use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

Route::post('/documents', [DocumentController::class, 'store']);
Route::get('/documents', [DocumentController::class, 'index']);
Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

Route::post('/conversations', [ConversationController::class, 'store']);
Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy']);
Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
