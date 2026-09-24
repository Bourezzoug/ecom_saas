<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Action costs
    |--------------------------------------------------------------------------
    |
    | Credits are charged once per user action (not per model call) when the
    | action starts, and refunded if it fails (docs/ARCHITECTURE.md §1.2).
    | Manual edits are always free. Values are placeholders until pricing is set.
    |
    */

    'actions' => [
        'generate_store' => (int) env('CREDITS_COST_GENERATE_STORE', 40),
        'generate_landing' => (int) env('CREDITS_COST_GENERATE_LANDING', 15),
        'regenerate_section' => (int) env('CREDITS_COST_REGENERATE_SECTION', 2),
        'import_design' => (int) env('CREDITS_COST_IMPORT_DESIGN', 10),
        'product_description' => (int) env('CREDITS_COST_PRODUCT_DESCRIPTION', 1), // per product
    ],

    /*
    |--------------------------------------------------------------------------
    | Signup grant
    |--------------------------------------------------------------------------
    |
    | Credits granted to a new user's personal team, so the product can be
    | tried before billing exists (M5). Set to 0 to disable.
    |
    */

    'signup_grant' => (int) env('CREDITS_SIGNUP_GRANT', 0),

];
