<?php
// Adjust the directory list to the barangay's approved address directory.
// Set demo_mode to true only for a presentation; it makes each cooldown 10 seconds.
return [
    'puroks' => array_map(static fn(int $number): string => 'Purok ' . $number, range(1, 10)),
    'streets' => ['Rizal Street', 'Bonifacio Street', 'Mabini Street', 'Luna Street', 'Del Pilar Street'],
    'demo_mode' => false,
    'cooldowns_seconds' => [1 => 180, 2 => 600, 3 => 3600, 4 => 86400],
    'pending_request_limit' => 3,
    'request_rate_limit' => 5,
    'request_rate_window_seconds' => 3600,
    'pickup_deadline_days' => 7,
    'max_pickup_reschedules' => 2,
];
