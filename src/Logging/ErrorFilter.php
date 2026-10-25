<?php

namespace MonkeySoft\SitesMonkey\Logging;

use ErrorException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Monolog\LogRecord;
use Throwable;

/**
 * Decides which log records are worth sending to SitesMonkey. Bots hit public sites with broken or tampered requests
 * (for example a mangled Livewire payload) and every hit would otherwise become a report and a notification.
 */
class ErrorFilter
{
    public function shouldReport(LogRecord $record): bool
    {
        $exception = $record->context['exception'] ?? null;
        $exception = $exception instanceof Throwable ? $exception : null;

        if ($this->isIgnored($record, $exception) || $this->isGuestLivewirePayloadError($exception)) {
            return false;
        }

        return $this->passesThrottle($record, $exception);
    }

    private function isIgnored(LogRecord $record, ?Throwable $exception): bool
    {
        foreach ((array) config('sitesmonkey.ignore.exceptions', []) as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        $messages = array_filter([$record->message, $exception?->getMessage()]);

        foreach ((array) config('sitesmonkey.ignore.messages', []) as $pattern) {
            foreach ($messages as $message) {
                if (@preg_match((string) $pattern, (string) $message) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A visitor who is not logged in and posts a payload to Livewire that makes it crash on a type or JSON error is
     * a bot: real Livewire components never produce such a payload.
     */
    private function isGuestLivewirePayloadError(?Throwable $exception): bool
    {
        if (! config('sitesmonkey.ignore_guest_livewire_payload_errors', true) || $exception === null) {
            return false;
        }

        if (! ($exception instanceof \Error || $exception instanceof ErrorException || $exception instanceof JsonException)) {
            return false;
        }

        if (! app()->bound('request') || ! request()->is('livewire/update', 'livewire-*/update')) {
            return false;
        }

        try {
            return ! Auth::check();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The same error is sent once per period; the first occurrence is always sent.
     */
    private function passesThrottle(LogRecord $record, ?Throwable $exception): bool
    {
        $minutes = (int) config('sitesmonkey.throttle_minutes', 10);
        if ($minutes <= 0) {
            return true;
        }

        try {
            $fingerprint = md5(($exception !== null ? $exception::class.'|'.$exception->getMessage() : '').'|'.$record->message);

            return Cache::add('sitesmonkey:error:'.$fingerprint, true, now()->addMinutes($minutes));
        } catch (Throwable) {
            return true;
        }
    }
}
