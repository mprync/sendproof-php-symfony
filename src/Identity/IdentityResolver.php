<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Identity;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/** Decides how the signed-in user is shown in the workbench. */
interface IdentityResolver
{
    /**
     * @return array{name:?string,role?:?string}|null Null when nobody is signed in
     */
    public function identify(Request $request, ?UserInterface $user): ?array;
}
