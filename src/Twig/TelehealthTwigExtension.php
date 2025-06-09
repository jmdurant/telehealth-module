<?php

/**
 * Telehealth Twig Extensions
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TelehealthTwigExtension extends AbstractExtension
{
    /**
     * Get the filters for this extension
     *
     * @return array
     */
    public function getFilters()
    {
        return [
            new TwigFilter('addCacheParam', [$this, 'addCacheParam']),
        ];
    }

    /**
     * Add a cache busting parameter to a URL
     *
     * @param string $path The path to add the cache param to
     * @return string The path with the cache param added
     */
    public function addCacheParam($path)
    {
        if (empty($path)) {
            return $path;
        }
        
        // Add a timestamp to bust cache
        return $path . '?v=' . time();
    }
} 