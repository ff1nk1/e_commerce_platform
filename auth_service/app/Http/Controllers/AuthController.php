<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordChangeRequest;
use App\Http\Requests\RegistrationRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Services\Auth\AuthService;
use App\Services\Auth\TokenCookieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private TokenCookieService $tokenCookies,
    ) {}

    public function register(RegistrationRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return response()->json($user, 201);
    }

    public function getUser(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('role');

        return response()->json($user);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $tokens = $this->authService->login($request->validated());

        return $this->tokenCookies->jsonWithTokens(
            $tokens['access_token'],
            $tokens['refresh_token'],
        );
    }

    public function refresh(Request $request): JsonResponse
    {
        $oldRefreshToken = $request->cookie('refresh_token');
        $tokens = $this->authService->refreshTokens($oldRefreshToken);

        if (! $tokens) {
            return response()->json([
                'message' => 'Недействительный refresh-токен или пользователь не найден.',
            ], 401);
        }

        return $this->tokenCookies->jsonWithTokens(
            $tokens['access_token'],
            $tokens['refresh_token'],
        );
    }

    public function logout(Request $request): Response
    {
        $this->authService->logout(
            $this->accessTokenFromRequest($request),
            $this->refreshTokenFromRequest($request),
        );

        return $this->tokenCookies->clear(
            response()->json(['message' => 'Выход выполнен.'])
        );
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'message' => 'Данные успешно обновлены',
            'user' => $user->fresh(),
        ]);
    }

    public function changePassword(PasswordChangeRequest $request): Response
    {
        $data = $request->validated();
        $accessToken = $this->accessTokenFromRequest($request);

        try {
            $user = DB::transaction(function () use ($request, $data) {
                $user = $this->authService->verifyPassword($request->user(), $data['current_password']);
                $this->authService->archivePassword($user);
                $this->authService->setNewPassword($user, $data['new_password']);

                return $user;
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->authService->revokeAllSessionsForUser($user, $accessToken);

        return $this->tokenCookies->clear(
            response()->json(['message' => 'Пароль успешно изменён'])
        );
    }

    private function accessTokenFromRequest(Request $request): ?string
    {
        $fromCookie = $request->cookie('access_token')
            ?? $request->cookies->get('access_token');
        $fromBearer = $request->bearerToken();
        $token = $fromCookie ?? $fromBearer;

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function refreshTokenFromRequest(Request $request): ?string
    {
        $fromCookie = $request->cookie('refresh_token')
            ?? $request->cookies->get('refresh_token');

        return is_string($fromCookie) && $fromCookie !== '' ? $fromCookie : null;
    }
}
