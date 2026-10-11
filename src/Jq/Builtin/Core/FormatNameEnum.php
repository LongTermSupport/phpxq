<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

/**
 * The jq `@format` names, valued with the name written after the `@`.
 *
 * @internal
 */
enum FormatNameEnum: string
{
    case Text = 'text';

    case Json = 'json';

    case Csv = 'csv';

    case Tsv = 'tsv';

    case Html = 'html';

    case Uri = 'uri';

    case Urid = 'urid';

    case Sh = 'sh';

    case Base64 = 'base64';

    case Base64d = 'base64d';

    case Base32 = 'base32';

    case Base32d = 'base32d';
}
