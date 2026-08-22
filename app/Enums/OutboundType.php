<?php

namespace App\Enums;

enum OutboundType: string
{
    case New = 'new';
    case Reply = 'reply';
    case ReplyAll = 'reply_all';
    case Forward = 'forward';

    public function isReply(): bool
    {
        return $this === self::Reply || $this === self::ReplyAll;
    }
}
