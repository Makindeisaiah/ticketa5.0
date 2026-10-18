
<?php
declare(strict_types=1);

// Prefer environment variables configured in your hosting control panel.
return [
    'secret_key' => getenv('PAYSTACK_SECRET_KEY') ?: '',
    'currency' => 'XOF',
    'callback_url' => getenv('PAYSTACK_CALLBACK_URL') ?: '',
];
