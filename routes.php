<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Paymos\Paymos;

Route::post('/extensions/gateways/paymos/webhook', [Paymos::class, 'webhook'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('extensions.gateways.paymos.webhook');
