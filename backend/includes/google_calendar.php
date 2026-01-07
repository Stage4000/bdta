<?php
/**
 * Google Calendar Integration Configuration
 * 
 * Configuration is managed through Admin Panel > Settings > Calendar
 * Enable Google Calendar sync and configure credentials in settings.
 */

require_once __DIR__ . '/settings.php';

class GoogleCalendarIntegration {
    private $credentials_file;
    private $calendar_id;
    
    public function __construct() {
        $this->calendar_id = Settings::get('google_calendar_id', 'primary');
        $credentials_path = Settings::get('google_calendar_credentials_file', __DIR__ . '/google-calendar-credentials.json');
        $this->credentials_file = $credentials_path;
    }
    
    /**
     * Check if Google Calendar integration is configured
     */
    public function isConfigured() {
        return Settings::get('google_calendar_enabled', false) && file_exists($this->credentials_file);
    }
    
    /**
     * Add event to Google Calendar
     * Requires google/apiclient library: composer require google/apiclient
     */
    public function addEvent($booking) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Google Calendar not configured'];
        }
        
        try {
            // This is a placeholder - requires Google API client library
            // Install with: composer require google/apiclient
            
            /*
            $client = new Google_Client();
            $client->setAuthConfig($this->credentials_file);
            $client->addScope(Google_Service_Calendar::CALENDAR);
            
            $service = new Google_Service_Calendar($client);
            
            $event = new Google_Service_Calendar_Event([
                'summary' => $booking['service_type'] . ' - ' . $booking['client_name'],
                'description' => 'Client: ' . $booking['client_name'] . "\n" .
                                'Email: ' . $booking['client_email'] . "\n" .
                                'Phone: ' . $booking['client_phone'] . "\n" .
                                'Notes: ' . $booking['notes'],
                'start' => [
                    'dateTime' => $booking['appointment_date'] . 'T' . $booking['appointment_time'] . ':00',
                    'timeZone' => 'America/New_York',
                ],
                'end' => [
                    'dateTime' => $booking['appointment_date'] . 'T' . 
                                  date('H:i', strtotime($booking['appointment_time']) + $booking['duration_minutes'] * 60) . ':00',
                    'timeZone' => 'America/New_York',
                ],
                'attendees' => [
                    ['email' => $booking['client_email']],
                ],
                'reminders' => [
                    'useDefault' => false,
                    'overrides' => [
                        ['method' => 'email', 'minutes' => 24 * 60],
                        ['method' => 'popup', 'minutes' => 60],
                    ],
                ],
            ]);
            
            $event = $service->events->insert($this->calendar_id, $event);
            
            return [
                'success' => true,
                'event_id' => $event->getId(),
                'link' => $event->getHtmlLink()
            ];
            */
            
            return [
                'success' => false,
                'message' => 'Google API client library not installed. Run: composer require google/apiclient'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
?>
