<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\AuthenticateSession;

class AuthenticateWebSession extends AuthenticateSession
{
    public function handle($request, Closure $next)
    {
        $this->auth->shouldUse(config('fortify.guard', 'web'));

        if ($request->hasSession()
            && $request->user()?->web_sessions_revoked_at !== null
            && ! $request->session()->has('password_hash_'.$this->auth->getDefaultDriver())) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }
}
