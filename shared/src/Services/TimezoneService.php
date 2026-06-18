<?php

namespace SynergyERP\Shared\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Torann\GeoIP\GeoIP;

class TimezoneService
{
    protected $geoip;

    public function __construct(GeoIP $geoip)
    {
        $this->geoip = $geoip;
    }

    /**
     * Get timezone from IP address
     */
    public function getTimezoneFromIp(string $ip = null): string
    {
        $ip = $ip ?: Request::ip();
        
        // If GeoIP is not properly initialized, return default
        if (!isset($this->geoip)) {
            return config('app.timezone', 'UTC');
        }
        
        try {
            $location = $this->geoip->getLocation($ip);
            if ($location && !empty($location['timezone'])) {
                return $location['timezone'];
            }
            
            // If location is found but timezone is empty, try to get from default location
            if ($location && isset($location['default']) && $location['default'] === true) {
                return config('geoip.default_location.timezone', 'UTC');
            }
        } catch (\Exception $e) {
            Log::warning("Failed to detect timezone for IP: {$ip}", ['error' => $e->getMessage()]);
        }
        
        return config('app.timezone', 'UTC');
    }

    /**
     * Set application timezone based on IP
     */
    public function setApplicationTimezone(string $ip = null): void
    {
        if (!app()->runningInConsole()) {
            $timezone = $this->getTimezoneFromIp($ip);
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }
    }
}
