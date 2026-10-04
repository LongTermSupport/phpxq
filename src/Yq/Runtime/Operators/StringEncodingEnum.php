<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

/**
 * The string encodings the `@...` operators apply themselves (the data formats go through the format registry),
 * valued with the name written after the `@`.
 */
enum StringEncodingEnum: string
{
    case Sh = 'sh';

    case Uri = 'uri';

    case Urid = 'urid';

    case Base64 = 'base64';

    case Base64d = 'base64d';

    case Base64Url = 'base64url';

    case Base64Urld = 'base64urld';

    case Html = 'html';
}
