<?php

/**
 * Handles form save actions for Telehealth
 *
 * @package   OpenEMR\Modules\Telehealth
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Controller;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\FormSaveEvent;
use OpenEMR\Modules\Telehealth\TelehealthGlobalConfig;
use Symfony\Component\EventDispatcher\EventDispatcher;

class TeleHealthClinicalNotesController
{
    /**
     * @var SystemLogger
     */
    private $logger;

    /**
     * @var TelehealthGlobalConfig
     */
    private $globalsConfig;

    /**
     * TeleHealthClinicalNotesController constructor.
     * @param TelehealthGlobalConfig $globalsConfig
     * @param SystemLogger $logger
     */
    public function __construct(TelehealthGlobalConfig $globalsConfig, SystemLogger $logger)
    {
        $this->globalsConfig = $globalsConfig;
        $this->logger = $logger;
    }

    /**
     * Subscribe to Form save events
     * @param EventDispatcher $eventDispatcher
     */
    public function subscribeToEvents(EventDispatcher $eventDispatcher)
    {
        $this->logger->debug("TeleHealthClinicalNotesController->subscribeToEvents() - Adding form save event listener");
        $eventDispatcher->addListener(FormSaveEvent::POST_SAVE, [$this, 'onClinicalNoteSave']);
    }

    /**
     * Handle form save events for telehealth forms
     * @param FormSaveEvent $event
     * @return FormSaveEvent
     */
    public function onClinicalNoteSave(FormSaveEvent $event)
    {
        $this->logger->debug("TeleHealthClinicalNotesController->onClinicalNoteSave() - Processing form save");
        
        if (!$this->globalsConfig->isTelehealthConfigured()) {
            $this->logger->debug("TeleHealthClinicalNotesController->onClinicalNoteSave() - Telehealth not configured, skipping");
            return $event;
        }
        
        $formId = $event->getFormId();
        $formName = $event->getFormName();
        
        // Only process telehealth forms
        if ($formName !== 'telehealth') {
            return $event;
        }
        
        $this->logger->debug("TeleHealthClinicalNotesController->onClinicalNoteSave() - Processing telehealth form", [
            'formId' => $formId,
            'formName' => $formName
        ]);
        
        // Get the form data
        $formData = $event->getFormData();
        
        // Remove time component from date fields to ensure consistent date-only storage
        if (isset($formData['date'])) {
            // Parse the date and ensure it's stored without time component
            $date = new \DateTime($formData['date']);
            $formData['date'] = $date->format('Y-m-d');
            $event->setFormData($formData);
            
            $this->logger->debug("TeleHealthClinicalNotesController->onClinicalNoteSave() - Adjusted date format", [
                'original' => $formData['date'],
                'adjusted' => $date->format('Y-m-d')
            ]);
        }
        
        return $event;
    }
} 