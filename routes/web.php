<?php

use App\Http\Controllers\Api\MeetingRegistrationController;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public attendee cancellation link (from reschedule apology email).
Route::get('/meeting/cancel/{token}', [MeetingRegistrationController::class, 'cancelByToken']);

// Stripe sometimes redirects here when FRONTEND_URL was missing — forward to React app.
Route::get('/payment/success', function (Request $request) {
    $target = FrontendUrl::base() . '/payment/success';
    $query = $request->getQueryString();
    if ($query) {
        $target .= '?' . $query;
    }

    return redirect()->away($target);
});

Route::get('/payment/cancel', function () {
    return redirect()->away(FrontendUrl::base() . '/payment/cancel');
});
