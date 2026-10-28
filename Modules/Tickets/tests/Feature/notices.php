<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Helpers for the ticket notification tests (P04-03): what each address
 * was told, counted where people see it (the `notifications` table and
 * the array mailer), and the world's addresses.
 */

/**
 * What each address was told while $action ran: `app:{event}` per in-app
 * notification, `mail` per email, sorted.
 *
 * @return array<string, list<string>>
 */
function ticketToldBy(Closure $action): array
{
    DB::table('notifications')->delete();
    app('mailer')->getSymfonyTransport()->flush();

    $action();

    $told = [];

    foreach (DB::table('notifications')->orderBy('created_at')->get() as $row) {
        $data = json_decode((string) $row->data, true);
        $told[(string) User::query()->findOrFail($row->notifiable_id)->email][] = 'app:'.$data['event'];
    }

    foreach (app('mailer')->getSymfonyTransport()->messages() as $message) {
        foreach ($message->getOriginalMessage()->getTo() as $address) {
            $told[$address->getAddress()][] = 'mail';
        }
    }

    ksort($told);

    return array_map(function (array $lines): array {
        sort($lines);

        return $lines;
    }, $told);
}

const TN_KEMI = 'kemi.adebayo@example.com';
const TN_ADA = 'ada.owner@example.com';
const TN_SEYI = 'seyi.admin@example.com';
const TN_BOLA = 'bola.agent@example.com';
const TN_CHIKA = 'chika.agent@example.com';
const TN_FEMI = 'femi.okon@example.com';
const TN_NGOZI = 'ngozi.eze@example.com';
