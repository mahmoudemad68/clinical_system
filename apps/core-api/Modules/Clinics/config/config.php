<?php

declare(strict_types=1);

return [
    'name' => 'Clinics',
    'public_name_max_length' => 200,
    'address_max_length' => 500,
    /*
     | Egypt service-area bounding box. ENGINEERING_DEFAULT — not a legal or
     | geographic certification. V1 application allows EG only; the schema
     | remains country-ready.
     */
    'egypt_latitude_min' => 22.0,
    'egypt_latitude_max' => 31.7,
    'egypt_longitude_min' => 24.7,
    'egypt_longitude_max' => 36.9,
    /*
     | Staff invitation lifetime. ENGINEERING_DEFAULT until a product-approved
     | TTL exists. Phase 09 owns notification delivery; this chunk does not
     | send SMS or email.
     */
    'invitation_ttl_hours' => 72,
    'list_default_limit' => 25,
    'list_max_limit' => 100,
];
