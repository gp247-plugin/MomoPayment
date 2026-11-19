<?php
/**
 * Provides everything needed for the Extension
 */

$config = file_get_contents(__DIR__.'/gp247.json');
$config = json_decode($config, true);
$extensionPath = $config['configGroup'].'/'.$config['configKey'];

$this->loadTranslationsFrom(__DIR__.'/Lang', $extensionPath);

if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {

    if (file_exists(__DIR__.'/config.php')) {
        $this->mergeConfigFrom(__DIR__.'/config.php', $extensionPath);
    }

    if (file_exists(__DIR__.'/function.php')) {
        require_once __DIR__.'/function.php';
    }

    // MoMo also collects / refunds the shop's payment requests (pay links, refunds of
    // checkout payments).
    if (function_exists('momopayment_register_payment_gateway')) {
        momopayment_register_payment_gateway();
    }

    if (class_exists(\Livewire\Livewire::class)) {
        \Livewire\Livewire::addNamespace('MomoPayment', classNamespace: 'App\\GP247\\Plugins\\MomoPayment\\Livewire');
    }

    // storeScope = "store" (gp247.json): each store may use its own MoMo account, set by the
    // root admin through the ConfigForm store picker. This plugin deliberately does NOT add
    // its admin segment to the store-scoped segments registry, so store-admins and vendors
    // cannot change where the money goes.
}
