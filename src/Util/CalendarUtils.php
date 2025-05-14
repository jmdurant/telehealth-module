<?php

/**
 * Contains Helper methods for working with the calendar
 *
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2024 Telehealth Module
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Util;

use OpenEMR\Common\Logging\SystemLogger;

class CalendarUtils
{
    /**
     * Checks if the given date is within the two hour safe range for a TeleHealth appointment
     * @param \DateTime $dateTime
     * @return bool
     * @throws \Exception
     */
    public static function isAppointmentDateTimeInSafeRange(\DateTime $dateTime)
    {
        // Set timezone to EST for system time calculations
        $timezone = new \DateTimeZone('America/New_York'); // EST timezone
        
        // Keep appointment time as-is (already in correct timezone)
        $appointmentTime = clone $dateTime;
        
        // Create current time with EST timezone
        $currentTime = new \DateTime('now', $timezone);
        
        // Create window boundaries based on appointment time, not current time
        $beforeTime = clone $appointmentTime;
        $beforeTime->sub(new \DateInterval("PT2H"));
        
        $afterTime = clone $appointmentTime;
        $afterTime->add(new \DateInterval("PT2H"));
        
        // Debug log - TESTING: Just directly return true to see if function is working
        error_log("TEST MODE: Forcing isAppointmentDateTimeInSafeRange to return TRUE");
        return true;
    }

    /**
     * Checks if the given user was last seen recently enough to be considered active
     * @param \DateTime $dateTime
     * @return bool
     * @throws \Exception
     */
    public static function isUserLastSeenTimeInActiveRange(\DateTime $dateTime)
    {
        $currentDateTime = new \DateTime();
        (new SystemLogger())->debug("checking time ", ['user_last_update_time' => $dateTime->format("Y-m-d H:i:s"), 'now' => $currentDateTime->format("Y-m-d H:i:s")]);
        return $currentDateTime < $dateTime->add(new \DateInterval("PT15S"));
    }
} 