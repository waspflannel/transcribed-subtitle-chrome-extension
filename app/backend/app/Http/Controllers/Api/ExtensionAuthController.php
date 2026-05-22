<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExtensionLoginRequest;
use App\Http\Responses\ApiErrorResponse;
use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Auth\ExtensionTokenIssuer;
use App\Services\Subtitles\SubtitleTier;
use App\Support\ExtensionTokenAbility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;

class ExtensionAuthController extends Controller
{
    public function login(ExtensionLoginRequest $request, ExtensionTokenIssuer $tokens): JsonResponse
    {
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

        if (! $user->hasVerifiedEmail()) {
            return ApiErrorResponse::make(
                'email_not_verified',
                'Verify your email address before using the extension.',
                403,
                request: $request,
            );
        }

        $issuedToken = $tokens->issue($user, $request->extensionInstallId());

        return response()->json([
            'account' => $this->accountSummary($user),
            'token' => [
                'plainTextToken' => $issuedToken->plainTextToken,
                'tokenType' => 'Bearer',
                'expiresAt' => $issuedToken->accessToken->expires_at->toJSON(),
                'abilities' => ExtensionTokenAbility::DEFAULT_ABILITIES,
            ],
        ]);
    }

    public function account(Request $request): JsonResponse
    {
        return response()->json([
            'account' => $this->accountSummary($this->extensionUser($request)),
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

    /**
     * @return array<string, mixed>
     */
    private function accountSummary(User $user): array
    {
        $jobs = SubtitleJob::query()
            ->whereBelongsTo($user)
            ->where('created_at', '>=', now()->startOfMonth())
            ->get(['status', 'video_duration_seconds']);

        $usedMinutes = $jobs
            ->filter(fn (SubtitleJob $job): bool => $job->status === 'completed')
            ->sum(fn (SubtitleJob $job): int => $this->billableMinutes($job));
        $pendingMinutes = $jobs
            ->filter(fn (SubtitleJob $job): bool => $job->status === 'running')
            ->sum(fn (SubtitleJob $job): int => $this->billableMinutes($job));
        $tier = SubtitleTier::default();
        $limit = $this->monthlyMinuteLimit($tier);

        return [
            'status' => 'authenticated',
            'id' => (string) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'emailVerified' => $user->hasVerifiedEmail(),
            'planName' => 'Local beta',
            'tierName' => $this->tierName($tier),
            'tierSpeedLabel' => $this->tierSpeedLabel($tier),
            'monthlyMinuteLimit' => $limit,
            'monthlyMinutesUsed' => $usedMinutes,
            'monthlyMinutesPending' => $pendingMinutes,
            'monthlyMinutesRemaining' => max(0, $limit - $usedMinutes - $pendingMinutes),
            'resetAt' => now()->addMonthNoOverflow()->startOfMonth()->toJSON(),
            'upgradeAvailable' => true,
        ];
    }

    private function billableMinutes(SubtitleJob $job): int
    {
        $seconds = $job->video_duration_seconds;

        return is_int($seconds) && $seconds > 0 ? max(1, (int) ceil($seconds / 60)) : 0;
    }

    private function tierName(string $tier): string
    {
        return match ($tier) {
            SubtitleTier::ULTIMATE => 'Ultimate',
            'pro' => 'Pro',
            'plus' => 'Plus',
            default => 'Base',
        };
    }

    private function tierSpeedLabel(string $tier): string
    {
        return match ($tier) {
            SubtitleTier::ULTIMATE => 'Maximum local parallelism',
            'pro' => 'Fast queue',
            'plus' => 'Priority queue',
            default => 'Standard queue',
        };
    }

    private function monthlyMinuteLimit(string $tier): int
    {
        return match ($tier) {
            SubtitleTier::ULTIMATE => 600,
            'pro' => 240,
            'plus' => 120,
            default => 60,
        };
    }

    private function extensionUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Extension API request is missing an authenticated user.');
        }

        return $user;
    }
}
