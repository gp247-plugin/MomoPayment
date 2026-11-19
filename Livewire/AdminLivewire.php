<?php
#App\GP247\Plugins\MomoPayment\Livewire\AdminLivewire.php

namespace App\GP247\Plugins\MomoPayment\Livewire;

use App\GP247\Plugins\MomoPayment\AppConfig;
use GP247\Core\AdminShell\Infrastructure\ConfigForm;
use GP247\Core\AdminShell\Infrastructure\ReadinessCheck;
use GP247\Core\Models\AdminConfig;
use GP247\Shop\Models\ShopCurrency;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Payment\PaymentSettings;

/**
 * Admin settings screen for MoMo: sandbox/live mode, the three keys of each environment, the
 * order statuses after a payment / a full refund, and the prefix of order ids sent to MoMo.
 *
 * Keys are `password` fields, so core encrypts them at rest and never reveals them. The root
 * admin gets the store picker (one MoMo account per store); the screen stays owner-only
 * because Provider.php does not register it as a store-scoped segment. Above the form, "Check
 * before use" lists what still stops shoppers from paying with MoMo (local settings only).
 *
 * @aidlc-unit plugin-momo-payment
 * @aidlc-story US-momo-payment-per-store-credentials
 */
class AdminLivewire extends ConfigForm
{
    protected ?string $permission = null;

    private const PREFIX = 'MomoPayment_';

    /**
     * Make sure every setting row exists at GLOBAL before the form loads, so a site that
     * skipped an update still sees every field.
     *
     * @return void
     */
    public function mount(): void
    {
        $global = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
        $sort = 0;
        foreach (AppConfig::SETTINGS as $key => [$default, $secret]) {
            AdminConfig::firstOrCreate(
                ['group' => 'Plugins', 'key' => self::PREFIX . $key, 'store_id' => (string) $global],
                [
                    'code' => 'MomoPayment_config',
                    'sort' => ++$sort,
                    'value' => $default,
                    'security' => $secret,
                    'detail' => 'Plugins/MomoPayment::lang.' . $key,
                ]
            );
        }

        parent::mount();
    }

    protected function storeScoped(): bool
    {
        return true;
    }

    protected function group(): string
    {
        return 'Plugins';
    }

    protected function heading(): string
    {
        return trans('Plugins/MomoPayment::lang.config_heading');
    }

    protected function keys(): array
    {
        return array_map(fn ($key) => self::PREFIX . $key, array_keys(AppConfig::SETTINGS));
    }

    protected function fieldTypes(): array
    {
        $types = [
            self::PREFIX . 'test_mode' => 'bool',
            self::PREFIX . 'order_status_success' => 'select',
            self::PREFIX . 'order_status_refunded' => 'select',
        ];
        foreach (AppConfig::SETTINGS as $key => [, $secret]) {
            if ($secret) {
                $types[self::PREFIX . $key] = 'password';
            }
        }

        return $types;
    }

    protected function fieldOptions(): array
    {
        $orderStatus = ShopOrderStatus::getIdAll();

        return [
            self::PREFIX . 'order_status_success' => $orderStatus,
            self::PREFIX . 'order_status_refunded' => $orderStatus,
        ];
    }

    /**
     * Blocks — mode, Sandbox, Live, order — so a test key is never typed into a live field
     * by mistake; the block in use carries a badge. The IPN URL is the same for both
     * environments, so it is explained once, on the mode block.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sections(): array
    {
        $testMode = (bool) ($this->values[self::PREFIX . 'test_mode'] ?? true);
        $inUse = trans('Plugins/MomoPayment::lang.section_in_use');

        return [
            [
                'id' => 'mode',
                'title' => trans('Plugins/MomoPayment::lang.section_mode'),
                'hint' => trans('Plugins/MomoPayment::lang.section_mode_hint', ['url' => momopayment_ipn_url()]),
                'keys' => [self::PREFIX . 'test_mode'],
            ],
            [
                'id' => 'sandbox',
                'title' => trans('Plugins/MomoPayment::lang.section_sandbox'),
                'hint' => trans('Plugins/MomoPayment::lang.section_sandbox_hint'),
                'badge' => $testMode ? $inUse : '',
                'keys' => [self::PREFIX . 'partner_code_test', self::PREFIX . 'access_key_test', self::PREFIX . 'secret_key_test'],
            ],
            [
                'id' => 'live',
                'title' => trans('Plugins/MomoPayment::lang.section_live'),
                'hint' => trans('Plugins/MomoPayment::lang.section_live_hint'),
                'badge' => $testMode ? '' : $inUse,
                'keys' => [self::PREFIX . 'partner_code_live', self::PREFIX . 'access_key_live', self::PREFIX . 'secret_key_live'],
            ],
            [
                'id' => 'order',
                'title' => trans('Plugins/MomoPayment::lang.section_order'),
                'keys' => [self::PREFIX . 'order_status_success', self::PREFIX . 'order_status_refunded', self::PREFIX . 'order_prefix'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldHints(): array
    {
        return [
            self::PREFIX . 'order_prefix' => trans('Plugins/MomoPayment::lang.order_prefix_hint'),
        ];
    }

    /**
     * What the store still needs before shoppers can pay with MoMo: the payment step at
     * checkout, the plugin on for the store (both from gp247/shop), the three keys of the mode
     * in use, an active VND currency (MoMo takes nothing else); warnings while in sandbox and
     * while the IPN URL is on a domain MoMo cannot reach. Local settings only — the keys
     * themselves are only checked by MoMo at payment time.
     *
     * @return array<int, ReadinessCheck>
     *
     * @aidlc-story US-momo-payment-per-store-credentials
     * @aidlc-story US-admin-shell-config-form-readiness
     */
    protected function readiness(): array
    {
        $store = $this->readinessStoreId();
        $t = static fn (string $key, array $replace = []): string => (string) trans('Plugins/MomoPayment::lang.' . $key, $replace);
        // WHY '' = sandbox: an unsaved row falls back to config.php, whose default is sandbox.
        $testMode = !in_array($this->saved(self::PREFIX . 'test_mode', $store), ['0'], true);
        $suffix = $testMode ? '_test' : '_live';
        $section = $t($testMode ? 'section_sandbox' : 'section_live');
        $missingKey = false;
        foreach (['partner_code', 'access_key', 'secret_key'] as $key) {
            $missingKey = $missingKey || $this->saved(self::PREFIX . $key . $suffix, $store) === '';
        }
        $ipnHost = (string) parse_url(momopayment_ipn_url(), PHP_URL_HOST);
        $hasVnd = ShopCurrency::where('code', 'VND')->where('status', 1)->exists();

        return [
            ...$this->shopChecks($store, $t),
            ReadinessCheck::failIf($missingKey, 'momo_keys', $t('ready_keys_missing', ['mode' => $section]),
                ReadinessCheck::ERROR, '', $section, $t('ready_keys_ok', ['mode' => $section])),
            ReadinessCheck::failIf(!$hasVnd, 'momo_currency_vnd', $t('ready_currency_missing'),
                ReadinessCheck::ERROR, self::adminUrl('admin_currency.index'), $t('fix_currency'), $t('ready_currency_ok')),
            ReadinessCheck::failIf($testMode, 'momo_test_mode', $t('ready_test_mode_on'),
                ReadinessCheck::WARNING, '', $t('section_mode'), $t('ready_test_mode_off')),
            ReadinessCheck::failIf(self::isLocalHost($ipnHost), 'momo_ipn_public', $t('ready_ipn_local'),
                ReadinessCheck::WARNING, '', '', $t('ready_ipn_ok')),
        ];
    }

    /**
     * The shop-side conditions from gp247/shop, so every payment plugin shows the same thing;
     * an older shop without PaymentSettings::readiness() gets the same two checks here.
     *
     * @param string   $store Store being checked.
     * @param \Closure $t     Translator of this plugin's strings.
     * @return array<int, ReadinessCheck> payment_enabled, provider_enabled.
     */
    private function shopChecks(string $store, \Closure $t): array
    {
        if (method_exists(PaymentSettings::class, 'readiness')) {
            return PaymentSettings::readiness($store, 'MomoPayment');
        }

        return [
            ReadinessCheck::failIf(in_array($this->saved('use_payment', $store), ['', '0'], true), 'payment_enabled', $t('ready_payment_off'),
                ReadinessCheck::ERROR, self::adminUrl('admin_config.index'), $t('fix_order_tab'), $t('ready_payment_on')),
            ReadinessCheck::failIf($this->saved('MomoPayment', $store, 'Plugins') !== '1', 'provider_enabled', $t('ready_plugin_off'),
                ReadinessCheck::ERROR, self::adminUrl('admin_plugin.index'), $t('fix_plugins'), $t('ready_plugin_on')),
        ];
    }

    /**
     * A setting read straight from admin_config: the store row, else the GLOBAL row.
     *
     * WHY not gp247_config(): it caches the rows for the whole request, so right after a save
     * the box would still show the values from before it.
     *
     * @param string      $key   admin_config key.
     * @param string      $store Store id.
     * @param string|null $group admin_config group (null = any).
     * @return string Trimmed value, '' when unset.
     */
    private function saved(string $key, string $store, ?string $group = null): string
    {
        $global = (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0);
        foreach (array_unique([$store, $global]) as $scope) {
            $row = AdminConfig::where('key', $key)->where('store_id', $scope)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))->first();
            if ($row !== null && trim((string) $row->value) !== '') {
                return trim((string) $row->value);
            }
        }

        return '';
    }

    /**
     * @param string $route Admin route name.
     * @return string URL, or '' when the route is not registered.
     */
    private static function adminUrl(string $route): string
    {
        return \Illuminate\Support\Facades\Route::has($route) ? (string) gp247_route_admin($route) : '';
    }

    /**
     * @param string $host Host of the IPN URL.
     * @return bool MoMo cannot call it back (local name or private address).
     */
    private static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost') {
            return true;
        }
        foreach (['.local', '.localhost', '.test', '.internal', '.lan'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}
