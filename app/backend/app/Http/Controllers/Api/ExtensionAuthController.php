<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesExtensionUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExtensionLoginRequest;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Analytics\FunnelAnalytics;
use App\Services\Auth\ExtensionTokenIssuer;
use App\Services\Billing\BillingEntitlementService;
use App\Support\ExtensionTokenAbility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class ExtensionAuthController extends Controller
{
    use ResolvesExtensionUser;

    public function login(
        ExtensionLoginRequest $request,
        ExtensionTokenIssuer $tokens,
        BillingEntitlementService $billing,
        FunnelAnalytics $analytics,
    ): JsonResponse {
        if (app()->isProduction() && ! $request->secure()) {
            return ApiErrorResponse::make(
                'insecure_transport',
                'Extension login requires HTTPS.',
                400,
                request: $request,
            );
        }

        $user = User::query()
            ->where('email', $request->email())
            ->first();

        if ($user === null || ! Hash::check($request->password(), $user->password)) {
            return ApiErrorResponse::make(
                'invalid_credentials',
                'The provided credentials are invalid.',
                422,
                request: $request,
            );
        }

        $issuedToken = $tokens->issue($user, $request->extensionInstallId());
        $account = $billing->accountSummary($user);
        $analytics->extensionConnected($user, $request->extensionInstallId(), $account);

        return response()->json([
            'account' => [...$account, 'aiModel' => (string) config('ai.providers.'.config('ai.default').'.models.text.default')],
            'token' => [
                'plainTextToken' => $issuedToken->plainTextToken,
                'tokenType' => 'Bearer',
                'expiresAt' => $issuedToken->accessToken->expires_at->toJSON(),
                'abilities' => ExtensionTokenAbility::DEFAULT_ABILITIES,
            ],
        ]);
    }

    public function account(Request $request, BillingEntitlementService $billing): JsonResponse
    {
        return response()->json([
            'account' => [
                ...$billing->accountSummary($this->extensionUser($request)),
                'aiModel' => (string) config('ai.providers.'.config('ai.default').'.models.text.default'),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->extensionUser($request)->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }
}
