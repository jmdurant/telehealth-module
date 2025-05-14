<?php

use OpenEMR\Core\AbstractModuleActionListener;
use OpenEMR\Modules\Telehealth\Bootstrap;

/**
 * Class to be called from Laminas Module Manager for reporting management actions.
 * Handles enable/disable/unregister/install/upgrade actions for the Telehealth module.
 *
 * @package   OpenEMR Modules
 * @link      https://www.open-emr.org
 * @author    (Based on FaxSMS module by Jerry Padgett)
 * @copyright Copyright (c) 2024 James DuRant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
class ModuleManagerListener extends AbstractModuleActionListener
{
    /**
     * Required method to return namespace for this module
     * @return string
     */
    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\Telehealth\\';
    }

    public $service;
    private $authUser;

    public function __construct()
    {
        parent::__construct();
        $this->authUser = (int)$this->getSession('authUserID');
        $this->service = new Bootstrap();
    }

    /**
     * Required method to return this class object for Laminas Manager.
     * @return ModuleManagerListener
     */
    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    /**
     * @param        $methodName
     * @param        $modId
     * @param string $currentActionStatus
     * @return string On method success a $currentAction status should be returned or error string.
     */
    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        } else {
            return "Module cleanup method $methodName does not exist.";
        }
    }

    public function enable($modId, $currentActionStatus): mixed
    {
        // Make sure globals.php is loaded for DB access
        if (!function_exists('sqlStatement')) {
            $globalsPath = realpath(__DIR__ . '/../../../globals.php');
            if ($globalsPath && file_exists($globalsPath)) {
                require_once($globalsPath);
                error_log('[Telehealth enable] globals.php loaded from: ' . $globalsPath);
            } else {
                error_log('[Telehealth enable] globals.php not found at expected path: ' . $globalsPath);
            }
        }
        if (empty($this->service)) {
            $this->service = new Bootstrap();
        }
        // Restore Fax/SMS pattern: fetch/persist settings
        $globals = method_exists($this->service, 'fetchPersistedSetupSettings')
            ? $this->service->fetchPersistedSetupSettings() ?? ''
            : '';
        if (empty($globals) && method_exists($this->service, 'getVendorGlobals')) {
            $globals = $this->service->getVendorGlobals();
        }
        if (method_exists($this->service, 'saveModuleListenerGlobals')) {
            $this->service->saveModuleListenerGlobals($globals);
        }
        // Set mod_ui_active=0 in the modules table for this module so the UI will show as enabled
        if (function_exists('sqlStatement')) {
            $result = sqlStatement("UPDATE modules SET mod_ui_active = 0 WHERE directory = ? OR directory = ?", ['telehealth', 'oe-module-telehealth']);
            error_log('[Telehealth enable] Ran SQL: UPDATE modules SET mod_ui_active = 0 WHERE directory = telehealth OR directory = oe-module-telehealth');
            error_log('[Telehealth enable] sqlStatement result: ' . print_r($result, true));
        } else {
            error_log('[Telehealth enable] sqlStatement function not available, could not update mod_ui_active.');
        }
        error_log('[Telehealth enable] Called enable() method for moduleId=' . $modId);
        return 'Success';
    }

    // --- OpenEMR expects this method for module installation lifecycle ---
    public function install($modId, $currentActionStatus): mixed
    {
        error_log('[Telehealth install] Called install() method for moduleId=' . $modId);
        // Add setup logic here if needed
        return 'Success';
    }

    private function disable($modId, $currentActionStatus): mixed
    {
        // You can add custom logic here to run on disable
        return $currentActionStatus;
    }

    private function unregister($modId, $currentActionStatus): mixed
    {
        // You can add custom logic here to run on unregister
        return $currentActionStatus;
    }

    private function install_sql($modId, $currentActionStatus): mixed
    {
        // You can add custom logic here to run on install
        return $currentActionStatus;
    }

    private function upgrade_sql($modId, $currentActionStatus): mixed
    {
        // You can add custom logic here to run on upgrade
        return $currentActionStatus;
    }
}
