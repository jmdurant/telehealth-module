<?php

/**
 * Telehealth Global Configuration
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Services\Globals\GlobalSetting;
use OpenEMR\Services\Globals\GlobalsService;
use Twig\Environment;
use OpenEMR\Common\Logging\SystemLogger;

class TelehealthGlobalConfig
{
    const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
    const MODULE_NAME = "Telehealth Virtual Care";

    private $cryptoGen;
    private $twig;
    private $publicWebPath;
    private $modulePublicPath;
    private $moduleDirectoryName;
    private $logger;

    public function __construct(string $modulePublicPath, string $moduleDirectoryName, Environment $twig)
    {
        $this->cryptoGen = new CryptoGen();
        $this->twig = $twig;
        $this->publicWebPath = $modulePublicPath;
        $this->modulePublicPath = $modulePublicPath;
        $this->moduleDirectoryName = $moduleDirectoryName;
        $this->logger = new SystemLogger();
        
        $this->logger->debug("TelehealthGlobalConfig initialized", [
            'modulePublicPath' => $modulePublicPath,
            'moduleDirectoryName' => $moduleDirectoryName
        ]);
    }

    public function setupConfiguration(GlobalsService $service)
    {
        global $GLOBALS;
        
        $section = xlt("TeleHealth");
        $service->createSection($section, 'Portal');

        // Add our ACL settings
        $settings = [
            'telehealth_enabled' => [
                'title' => 'Enable Telehealth',
                'description' => 'Enable telehealth functionality',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '1'
            ],
            'telehealth_provider_access' => [
                'title' => 'Provider Access',
                'description' => 'Allow providers to access telehealth',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '1'
            ],
            'telehealth_patient_access' => [
                'title' => 'Patient Access',
                'description' => 'Allow patients to access telehealth',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '1'
            ]
        ];

        foreach ($settings as $key => $config) {
            $value = $GLOBALS[$key] ?? $config['default'];
            $setting = new GlobalSetting(
                xlt($config['title']),
                $config['type'],
                $value,
                xlt($config['description']),
                true
            );
            $service->appendToSection(
                $section,
                $key,
                $setting
            );
        }
    }

    public function isTelehealthEnabled()
    {
        return $this->getGlobalSetting('telehealth_enabled') == '1';
    }

    public function isProviderAccessEnabled()
    {
        return $this->getGlobalSetting('telehealth_provider_access') == '1';
    }

    public function isPatientAccessEnabled()
    {
        return $this->getGlobalSetting('telehealth_patient_access') == '1';
    }

    private function getGlobalSetting($key)
    {
        global $GLOBALS;
        return $GLOBALS[$key] ?? null;
    }

    public function getPublicWebPath()
    {
        return $this->publicWebPath;
    }

    /**
     * Check if telehealth is configured
     *
     * @return bool
     */
    public function isTelehealthConfigured(): bool
    {
        // For now always return true - this is used to determine whether to load event handlers
        return true;
    }

    /**
     * Get the public path for assets
     *
     * @return string
     */
    public function getPublicPath(): string
    {
        return $this->modulePublicPath;
    }

    /**
     * Get the assets path
     *
     * @return string
     */
    public function getAssetsPath(): string
    {
        return $this->modulePublicPath . 'assets/';
    }

    /**
     * Get the module directory name
     *
     * @return string
     */
    public function getModuleDirectoryName(): string
    {
        return $this->moduleDirectoryName;
    }

    /**
     * Add a CryptoGen instance for encryption/decryption 
     * 
     * @return CryptoGen
     */
    public function getCryptoGen()
    {
        if (!isset($this->cryptoGen)) {
            $this->cryptoGen = new CryptoGen();
        }
        return $this->cryptoGen;
    }
} 