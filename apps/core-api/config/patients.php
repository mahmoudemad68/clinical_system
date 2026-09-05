<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Patients module — ENGINEERING_DEFAULT demographic bounds
|--------------------------------------------------------------------------
|
| These values are storage/sanity bounds, not clinical standards, not
| Egyptian civil-status policy, and not an approved product gender
| taxonomy. There is no authoritative clinical height/weight protocol in
| this repository. Out-of-range values are rejected (422). They are never
| silently clamped.
|
| Gender is a closed ENGINEERING_DEFAULT pair matching the two values the
| V1 onboarding form accepts. It is not derived from National ID digits
| and is not a legal sex classification.
|
*/

return [

    'gender' => ['male', 'female'],

    'blood_types' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],

    'marital_statuses' => ['single', 'married', 'divorced', 'widowed'],

    'height_cm' => [
        'min' => 30.0,
        'max' => 300.0,
    ],

    'weight_kg' => [
        'min' => 1.0,
        'max' => 700.0,
    ],

    /*
    | Earliest date_of_birth accepted as a column-sanity bound. Not a
    | clinical age limit. Future dates are rejected against Clock.
    */
    'date_of_birth_min' => '1850-01-01',

    'full_name_max_length' => 200,

];
