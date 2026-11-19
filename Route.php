<?php
use Illuminate\Support\Facades\Route;

$config = file_get_contents(__DIR__.'/gp247.json');
$config = json_decode($config, true);

if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {

    // Shopper-facing return URLs: normal storefront middleware (session, locale, domain).
    // Throttled: each hit asks MoMo's query API.
    Route::group(
        [
            'middleware' => GP247_FRONT_MIDDLEWARE,
            'prefix'    => 'plugin/momo-payment',
        ],
        function () {
            Route::get('return', [\App\GP247\Plugins\MomoPayment\Controllers\FrontController::class, 'paymentReturn'])
                ->middleware('throttle:30,1')
                ->name('momo-payment.return');
            Route::get('payment-request/return', [\App\GP247\Plugins\MomoPayment\Controllers\FrontController::class, 'paymentRequestReturn'])
                ->middleware('throttle:30,1')
                ->name('momo-payment.payment_request.return');
        }
    );

    // IPN: deliberately OUTSIDE the storefront group. It is a server-to-server POST (no
    // session, no CSRF token), and the storefront middleware can answer it with a redirect or
    // a maintenance page that MoMo would count as delivered — the payment would be lost. Its
    // only gate is the signature. Throttled generously: an unsigned flood is refused before
    // it reaches the database.
    Route::post('plugin/momo-payment/ipn', [\App\GP247\Plugins\MomoPayment\Controllers\FrontController::class, 'ipn'])
        ->middleware('throttle:600,1')
        ->name('momo-payment.ipn');

    Route::group(
        [
            'prefix' => GP247_ADMIN_PREFIX.'/momo-payment',
            'middleware' => GP247_ADMIN_MIDDLEWARE,
        ],
        function () {
            Route::get('/', \App\GP247\Plugins\MomoPayment\Livewire\AdminLivewire::class)
                ->name('admin_momo-payment.index');
        }
    );
}
