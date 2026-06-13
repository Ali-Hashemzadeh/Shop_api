<?php

namespace Modules\Catalog\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminRole
{
    public function __construct(private readonly IdentityManagerInterface $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->identity->isAdmin($user->id)) {
            abort(403, 'Administrative access required.');
        }

        return $next($request);
    }
}
