<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Nigerian banks (ONBOARDING_COMPLIANCE.md § 4.1)
|--------------------------------------------------------------------------
|
| The banks an organization can add an account at, as Paystack's bank
| codes (the codes its account-name lookup takes) => display name. A fixed
| list so the settings page works with no network call; App\Banking\BankCatalogue
| reads it. Add a bank here when a customer needs one that is missing.
|
*/

return [

    'banks' => [
        '044' => 'Access Bank',
        '023' => 'Citibank Nigeria',
        '050' => 'Ecobank Nigeria',
        '070' => 'Fidelity Bank',
        '011' => 'First Bank of Nigeria',
        '214' => 'First City Monument Bank (FCMB)',
        '00103' => 'Globus Bank',
        '058' => 'Guaranty Trust Bank (GTBank)',
        '030' => 'Heritage Bank',
        '301' => 'Jaiz Bank',
        '082' => 'Keystone Bank',
        '50211' => 'Kuda Microfinance Bank',
        '303' => 'Lotus Bank',
        '50515' => 'Moniepoint Microfinance Bank',
        '999992' => 'OPay',
        '107' => 'Optimus Bank',
        '999991' => 'PalmPay',
        '104' => 'Parallex Bank',
        '076' => 'Polaris Bank',
        '105' => 'PremiumTrust Bank',
        '101' => 'Providus Bank',
        '106' => 'Signature Bank',
        '221' => 'Stanbic IBTC Bank',
        '068' => 'Standard Chartered Bank',
        '232' => 'Sterling Bank',
        '100' => 'SunTrust Bank',
        '302' => 'TAJBank',
        '102' => 'Titan Trust Bank',
        '032' => 'Union Bank of Nigeria',
        '033' => 'United Bank for Africa (UBA)',
        '215' => 'Unity Bank',
        '035' => 'Wema Bank',
        '057' => 'Zenith Bank',
    ],

];
