<?php
/**
 * Plugin format 2.1
 */
#App\GP247\Plugins\MomoPayment\AppConfig.php
namespace App\GP247\Plugins\MomoPayment;

use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminHome;
use GP247\Core\Models\AdminMenu;
use GP247\Core\ExtensionConfigDefault;

/**
 * MoMo Payment plugin lifecycle.
 *
 * Every setting lives in admin_config (group "Plugins", code "MomoPayment_config"), so a 1-click
 * update — which replaces the plugin files — keeps the site owner's keys. The plugin owns no
 * table: payments and refunds go to the shop's ledgers.
 *
 * @aidlc-unit plugin-momo-payment
 * @aidlc-story US-momo-payment-per-store-credentials
 */
class AppConfig extends ExtensionConfigDefault
{
    private const MENU_URI = 'admin::momo-payment';

    /**
     * Settings seeded at GLOBAL: key => [default, secret]. Mirrored by the settings screen.
     * There is deliberately no "payment status" setting: it is derived from the ledger.
     */
    public const SETTINGS = [
        'test_mode' => ['1', 0],
        'partner_code_test' => ['', 0],
        'access_key_test' => ['', 1],
        'secret_key_test' => ['', 1],
        'partner_code_live' => ['', 0],
        'access_key_live' => ['', 1],
        'secret_key_live' => ['', 1],
        'order_status_success' => ['2', 0],
        'order_status_refunded' => ['7', 0],
        'order_prefix' => ['', 0],
    ];

    public function __construct()
    {
        //Read config from gp247.json
        $config = file_get_contents(__DIR__.'/gp247.json');
        $config = json_decode($config, true);
        $this->configGroup = $config['configGroup'];
        $this->configKey = $config['configKey'];
        $this->configCode = $config['configCode'] ?? $this->configKey;
        $this->requireCore = $config['requireCore'] ?? [];
        $this->requireComposerPackages = $config['requireComposerPackages'] ?? [];
        $this->requireGp247Extensions = $config['requireGp247Extensions'] ?? [];
        //Path
        $this->appPath = $this->configGroup . '/' . $this->configKey;
        //Language
        $this->title = trans($this->appPath.'::lang.title');
        //Image logo or thumb
        $this->image = $this->appPath.'/'.$config['image'];
        //
        $this->version = $config['version'];
        $this->auth = $config['auth'];
        $this->link = $config['link'];
    }

    public function install()
    {
        $check = AdminConfig::where('key', $this->configKey)
            ->where('group', $this->configGroup)->first();
        if ($check) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.plugin_exist')];
        }

        try {
            AdminConfig::insert($this->seedRows());
            $this->ensureMenu();
            $return = ['error' => 0, 'msg' => gp247_language_render('admin.extension.install_success')];
        } catch (\Throwable $e) {
            $return = ['error' => 1, 'msg' => $e->getMessage()];
        }

        return $return;
    }

    /**
     * Converge an existing install: add any setting row a later version introduced, never
     * touch a value the site owner saved. Idempotent — safe on every update.
     *
     * @param string|null $fromVersion Version installed before this update (null = unknown).
     * @return array{error:int,msg:string}
     */
    public function update(?string $fromVersion = null)
    {
        try {
            foreach ($this->seedRows() as $row) {
                $exists = AdminConfig::where('group', $row['group'])
                    ->where('key', $row['key'])
                    ->where('store_id', (string) $row['store_id'])
                    ->exists();
                if (!$exists) {
                    AdminConfig::insert($row);
                }
            }
            $this->ensureMenu();

            return ['error' => 0, 'msg' => ''];
        } catch (\Throwable $e) {
            return ['error' => 1, 'msg' => $e->getMessage()];
        }
    }

    public function uninstall()
    {
        // Only the plugin's own settings and menu. Money recorded through MoMo stays in the
        // shop's ledgers — it is the store's accounting, not plugin data.
        try {
            // By key prefix as well as by code: a store override written outside the settings
            // screen can carry another `code`, and a surviving key would come back on reinstall.
            (new AdminConfig)
                ->where('group', $this->configGroup)
                ->where(function ($query) {
                    $query->where('key', $this->configKey)
                        ->orWhere('key', 'like', $this->configKey.'\_%')
                        ->orWhere('code', $this->configKey.'_config');
                })
                ->delete();

            AdminHome::where('extension', $this->appPath)->delete();
            AdminMenu::where('uri', self::MENU_URI)->delete();

            $return = ['error' => 0, 'msg' => gp247_language_render('admin.extension.uninstall_success')];
        } catch (\Throwable $e) {
            $return = ['error' => 1, 'msg' => $e->getMessage()];
        }

        return $return;
    }

    public function enable()
    {
        $process = (new AdminConfig)
            ->where('group', $this->configGroup)
            ->where('key', $this->configKey)
            ->update(['value' => self::ON]);
        AdminHome::where('extension', $this->appPath)->update(['status' => 1]);

        if (!$process) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.action_error', ['action' => 'Enable'])];
        }

        return ['error' => 0, 'msg' => gp247_language_render('admin.extension.enable_success')];
    }

    public function disable()
    {
        $return = ['error' => 0, 'msg' => ''];
        $process = (new AdminConfig)
            ->where('group', $this->configGroup)
            ->where('key', $this->configKey)
            ->update(['value' => self::OFF]);
        if (!$process) {
            $return = ['error' => 1, 'msg' => gp247_language_render('admin.extension.action_error', ['action' => 'Disable'])];
        }

        AdminHome::where('extension', $this->appPath)->update(['status' => 0]);

        return $return;
    }

    public function removeStore($storeId = null)
    {
        // Store overrides are plain admin_config rows handled by core.
    }

    public function setupStore($storeId = null)
    {
        // Nothing to provision: a new store inherits the GLOBAL MoMo settings.
    }

    public function clickApp()
    {
        return redirect(gp247_route_admin('admin_momo-payment.index'));
    }

    /**
     * Get info plugin
     *
     * @return array<string, mixed>
     */
    public function getInfo()
    {
        return [
            'title' => $this->title,
            'key' => $this->configKey,
            'code' => $this->configCode,
            'image' => $this->image,
            'permission' => self::ALLOW,
            'version' => $this->version,
            'auth' => $this->auth,
            'link' => $this->link,
            'value' => 0,
            'appPath' => $this->appPath,
            'note' => trans($this->appPath.'::lang.checkout_note'),
        ];
    }

    /**
     * Rows seeded at GLOBAL on install (and re-added by update() when missing).
     *
     * WHY every row carries the same columns, `security` included: AdminConfig::insert()
     * takes its column list from the first row, and a mismatch makes MySQL reject the whole
     * batch. Key rows are flagged security = 1 so core encrypts them at rest.
     *
     * @return array<int, array<string, mixed>>
     */
    private function seedRows(): array
    {
        $rows = [[
            'group' => $this->configGroup,
            'code' => $this->configCode,
            'key' => $this->configKey,
            'sort' => 0,
            'store_id' => GP247_STORE_ID_GLOBAL,
            'value' => self::ON,
            'security' => 0,
            'detail' => $this->appPath.'::lang.title',
        ]];

        $sort = 0;
        foreach (self::SETTINGS as $key => [$default, $security]) {
            $rows[] = [
                'group' => $this->configGroup,
                'code' => $this->configKey.'_config',
                'key' => $this->configKey.'_'.$key,
                'sort' => ++$sort,
                'store_id' => GP247_STORE_ID_GLOBAL,
                'value' => $default,
                'security' => $security,
                'detail' => $this->appPath.'::lang.'.$key,
            ];
        }

        return $rows;
    }

    /**
     * Menu entry under the shop's Payment block (skipped when the block is absent).
     */
    private function ensureMenu(): void
    {
        $paymentBlock = AdminMenu::where('key', 'ADMIN_SHOP_PAYMENT')->first();
        if ($paymentBlock && !AdminMenu::where('uri', self::MENU_URI)->exists()) {
            AdminMenu::insert([
                'parent_id' => $paymentBlock->id,
                'sort' => 41,
                'title' => 'MoMo',
                'icon' => 'fas fa-wallet',
                'uri' => self::MENU_URI,
                'key' => null,
                'type' => 0,
            ]);
        }
    }
}
