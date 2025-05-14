<?php

/**
 * Module Interface for OpenEMR modules
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * This interface is a fallback in case the OpenEMR ModuleInterface is not available
 */
interface ModuleInterface
{
    /**
     * Module setup method
     * 
     * @param EventDispatcherInterface|null $eventDispatcher
     * @return void
     */
    public function setup(EventDispatcherInterface $eventDispatcher = null): void;
} 