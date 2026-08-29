<?php
/**
 * RegPickProviderState — fallback for the rare case where Nalo's NETWORK
 * field doesn't match anything we recognise.
 *
 * Normal flow goes UNREGISTERED_MENU → REG_CONFIRM_NAME directly because
 * the network is auto-detected from Nalo. This state only runs when:
 *   - The NETWORK field is empty / missing
 *   - It has some new value we haven't mapped (e.g. a new MVNO)
 *
 *   Pick Your MoMo Network:
 *   --
 *   1. MTN
 *   2. Telecel
 *   3. AirtelTigo
 *
 * Input 1/2/3 → call ANM, route to REG_CONFIRM_NAME on success.
 * Other      → re-render.
 */

function render_REG_PICK_PROVIDER(array &$session): array
{
    $msg = "Pick Your MoMo Network:\n"
         . "--\n"
         . "1. MTN\n"
         . "2. Telecel\n"
         . "3. AirtelTigo";
    return respStay($msg);
}

function handle_REG_PICK_PROVIDER(string $input, array &$session): array
{
    $choice = trim($input);
    $bankCode = match ($choice) {
        '1' => 'MTN',
        '2' => 'VOD',
        '3' => 'AIR',
        default => null,
    };

    if ($bankCode === null) {
        return respStay(
            "Invalid choice. Try again:\n"
            . "1. MTN\n"
            . "2. Telecel\n"
            . "3. AirtelTigo"
        );
    }

    $phoneLocal = displayMsisdn($session['msisdn']);

    try {
        $name = anmNameLookup($phoneLocal, $bankCode);
    } catch (RuntimeException $e) {
        ussdLog('REG_LOOKUP_FAILED', [
            'msisdn'    => $session['msisdn'],
            'bank_code' => $bankCode,
            'error'     => $e->getMessage(),
        ]);
        return respEnd($e->getMessage() . "\nPlease try again later.");
    }

    $session['data']['regBankCode'] = $bankCode;
    $session['data']['regName']     = $name;

    return respNext('REG_CONFIRM_NAME');
}
