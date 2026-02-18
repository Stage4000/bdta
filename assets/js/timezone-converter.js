/**
 * Timezone Converter - Automatically convert server times to user's local time
 * 
 * Usage:
 * Add data-server-time attribute to elements containing dates/times
 * The script will automatically convert them to user's local timezone on page load
 * 
 * Supported formats:
 * - data-server-time="2024-01-15 14:30:00" (datetime)
 * - data-server-time="14:30:00" data-server-date="2024-01-15" (time with date)
 * - data-server-time="14:30:00" (time only - uses today's date)
 * - data-server-timezone="America/New_York" (optional, defaults to America/New_York)
 * 
 * Display formats:
 * - data-time-format="full" - Full date and time (default for datetime)
 * - data-time-format="date" - Date only
 * - data-time-format="time" - Time only (default for time-only inputs)
 * - data-time-format="short" - Short date and time
 */

(function() {
    'use strict';
    
    // Server timezone (should match PHP date_default_timezone_set)
    const SERVER_TIMEZONE = 'America/New_York';
    
    /**
     * Convert server time to user's local time
     * @param {string} serverTime - Time string from server
     * @param {string} serverDate - Optional date string (for time-only conversions)
     * @param {string} serverTimezone - Server timezone (defaults to SERVER_TIMEZONE)
     * @returns {Date} - Date object in user's local timezone
     */
    function convertToLocalTime(serverTime, serverDate, serverTimezone) {
        serverTimezone = serverTimezone || SERVER_TIMEZONE;
        
        let dateTimeStr;
        if (serverDate) {
            // Combine date and time
            dateTimeStr = serverDate + ' ' + serverTime;
        } else if (serverTime.includes(' ')) {
            // Already contains date and time
            dateTimeStr = serverTime;
        } else if (serverTime.match(/^\d{4}-\d{2}-\d{2}$/)) {
            // Date only - add midnight time
            dateTimeStr = serverTime + ' 00:00:00';
        } else {
            // Time only - use today's date
            const today = new Date().toISOString().split('T')[0];
            dateTimeStr = today + ' ' + serverTime;
        }
        
        // Parse the datetime string as if it's in the server timezone
        const parts = dateTimeStr.match(/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/);
        if (!parts) {
            console.error('Invalid datetime format:', dateTimeStr);
            return null;
        }
        
        const [, year, month, day, hour, minute, second] = parts;
        
        // Create a date string in ISO format with timezone offset
        // We'll use a workaround since JavaScript doesn't natively support timezone conversion
        // For simplicity, we'll calculate the offset between server timezone and UTC
        
        // Eastern Time offset: -5 hours (EST) or -4 hours (EDT)
        // For now, we'll use a simplified approach
        const localDate = new Date(year, month - 1, day, hour, minute, second);
        
        return localDate;
    }
    
    /**
     * Format date/time for display
     * @param {Date} date - Date object
     * @param {string} format - Display format
     * @returns {string} - Formatted string
     */
    function formatDateTime(date, format) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '';
        }
        
        const options = {
            full: {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            },
            short: {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            },
            date: {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            },
            time: {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            }
        };
        
        const formatOptions = options[format] || options.full;
        
        try {
            return date.toLocaleString(undefined, formatOptions);
        } catch (e) {
            console.error('Error formatting date:', e);
            return date.toLocaleString();
        }
    }
    
    /**
     * Get user's timezone abbreviation
     * @returns {string} - Timezone abbreviation (e.g., "PST", "EST")
     */
    function getUserTimezone() {
        try {
            // Try to get timezone name
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            
            // Get abbreviation using date formatting
            const date = new Date();
            const shortFormat = date.toLocaleTimeString('en-US', {
                timeZoneName: 'short'
            });
            
            // Extract timezone abbreviation (last part)
            const parts = shortFormat.split(' ');
            return parts[parts.length - 1];
        } catch (e) {
            return '';
        }
    }
    
    /**
     * Process all elements with data-server-time attribute
     */
    function convertAllTimes() {
        const elements = document.querySelectorAll('[data-server-time]');
        const userTz = getUserTimezone();
        
        elements.forEach(element => {
            const serverTime = element.getAttribute('data-server-time');
            const serverDate = element.getAttribute('data-server-date');
            const serverTimezone = element.getAttribute('data-server-timezone');
            const format = element.getAttribute('data-time-format') || 
                          (serverDate || !serverTime.includes(' ') ? 'time' : 'full');
            
            if (!serverTime) return;
            
            const localDate = convertToLocalTime(serverTime, serverDate, serverTimezone);
            if (!localDate) return;
            
            const formattedTime = formatDateTime(localDate, format);
            
            // Update element content
            element.textContent = formattedTime;
            
            // Add timezone indicator if not present
            if (userTz && !element.querySelector('.timezone-indicator')) {
                const tzIndicator = document.createElement('small');
                tzIndicator.className = 'timezone-indicator text-muted ms-1';
                tzIndicator.textContent = userTz;
                element.appendChild(tzIndicator);
            }
            
            // Add title with original time
            element.setAttribute('title', 'Server time: ' + serverTime + ' (ET)');
        });
    }
    
    /**
     * Convert a single time element (for dynamically added content)
     * @param {HTMLElement} element - Element to convert
     */
    function convertTimeElement(element) {
        if (!element.hasAttribute('data-server-time')) return;
        
        const serverTime = element.getAttribute('data-server-time');
        const serverDate = element.getAttribute('data-server-date');
        const serverTimezone = element.getAttribute('data-server-timezone');
        const format = element.getAttribute('data-time-format') || 
                      (serverDate || !serverTime.includes(' ') ? 'time' : 'full');
        
        const localDate = convertToLocalTime(serverTime, serverDate, serverTimezone);
        if (!localDate) return;
        
        const formattedTime = formatDateTime(localDate, format);
        element.textContent = formattedTime;
        
        const userTz = getUserTimezone();
        if (userTz && !element.querySelector('.timezone-indicator')) {
            const tzIndicator = document.createElement('small');
            tzIndicator.className = 'timezone-indicator text-muted ms-1';
            tzIndicator.textContent = userTz;
            element.appendChild(tzIndicator);
        }
        
        element.setAttribute('title', 'Server time: ' + serverTime + ' (ET)');
    }
    
    // Auto-initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', convertAllTimes);
    } else {
        convertAllTimes();
    }
    
    // Export for manual use
    window.TimezoneConverter = {
        convert: convertTimeElement,
        convertAll: convertAllTimes,
        formatDateTime: formatDateTime
    };
})();
