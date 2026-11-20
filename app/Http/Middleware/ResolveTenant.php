<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private DomainTenantResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('tenancy.enabled') || $request->is('up') || $request->is('api/health')) {
            return $next($request);
        }

        $domain = strtolower(rtrim($request->getHost(), '.'));

        if (in_array($domain, config('tenancy.central_domains', []), true)) {
            return $next($request);
        }

        try {
            $tenant = $this->resolver->resolve($domain);
        } catch (TenantCouldNotBeIdentifiedException) {
            return response()->json([
                'message' => 'This domain is not registered for an active tenant.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($tenant->status !== 'active') {
            return response()->json([
                'message' => 'This tenant is not active.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            tenancy()->initialize($tenant);
        } catch (\Throwable $exception) {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            report($exception);

            return response()->json([
                'message' => 'The tenant database is unavailable.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            return $next($request);
        } finally {
            tenancy()->end();
        }
    }
}
