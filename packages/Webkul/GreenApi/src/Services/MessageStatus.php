<?php

namespace Webkul\GreenApi\Services;

class MessageStatus
{
    public static function advance(string $current, string $incoming): string
    {
        if (in_array($incoming, ['noAccount', 'notInGroup', 'suspended', 'yellowCard'], true)) {
            $incoming = 'failed';
        }

        // Errors supersede acceptance; confirmed delivery supersedes delayed errors.
        $ranks = ['pending' => 0, 'sending' => 1, 'unknown' => 1, 'sent' => 2, 'failed' => 3, 'delivered' => 4, 'read' => 5];

        if (! isset($ranks[$incoming])) {
            return $current;
        }

        return $ranks[$incoming] >= ($ranks[$current] ?? 0) ? $incoming : $current;
    }
}
