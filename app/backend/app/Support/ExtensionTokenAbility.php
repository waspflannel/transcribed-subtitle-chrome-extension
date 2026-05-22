<?php

namespace App\Support;

final class ExtensionTokenAbility
{
    public const ACCOUNT_READ = 'extension:account:read';

    public const SUBTITLES_WRITE = 'extension:subtitles:write';

    public const TOKENS_REVOKE = 'extension:tokens:revoke';

    public const DEFAULT_ABILITIES = [
        self::ACCOUNT_READ,
        self::SUBTITLES_WRITE,
        self::TOKENS_REVOKE,
    ];
}
