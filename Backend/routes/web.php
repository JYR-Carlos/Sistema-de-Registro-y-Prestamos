<?php

use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\GmailOAuthController;
use App\Http\Controllers\Auth\SsoController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
Route::get('/', fn() => response()->json(['message' => 'API funcionando 🚀']));
Route::get('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);

// Entrada del SSO: aqui aterriza el navegador que viene redirigido desde UTAmed
// con el token en la URL. Valida, guarda la sesion tras un ticket de un solo uso
// y manda al SPA, para que el token firmado no quede dando vueltas en la barra
// de direcciones del frontend.
Route::get('/auth/sso/consume', [SsoController::class, 'consume']);

// Gmail API OAuth - Rutas temporales para obtener refresh_token (usar solo una vez)
Route::get('/gmail/authorize', [GmailOAuthController::class, 'redirectToGmail']);
Route::get('/gmail/callback',  [GmailOAuthController::class, 'callback']);

//Auth::routes();

//Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
