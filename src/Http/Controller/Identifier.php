<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Exception\CustomerNotFound;
use App\Http\Request;

final class Identifier
{
    /**
     * A non-numeric segment cannot identify a resource, so it is a 404 rather
     * than a validation error.
     *
     * @throws CustomerNotFound
     */
    public static function fromPath(Request $request, string $name): int
    {
        $raw = $request->pathParameter($name);

        if (\preg_match('/^[1-9]\d{0,18}$/', $raw) !== 1) {
            throw new CustomerNotFound(\sprintf('"%s" is not a valid customer id.', $raw));
        }

        return (int) $raw;
    }
}
