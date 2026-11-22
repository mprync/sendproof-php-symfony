<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Identity;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/** Shows the user identifier and no role. Replace it with your own resolver for display names. */
final class DefaultIdentityResolver implements IdentityResolver
{
    public function identify(Request $request, ?UserInterface $user): ?array
    {
        return $user === null ? null : ['name' => $user->getUserIdentifier()];
    }
}
