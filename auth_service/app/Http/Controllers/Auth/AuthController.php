<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\DTO\Auth\LoginCredentials;
use App\DTO\Auth\RegisterUserData;
use App\DTO\Auth\TokenPair;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordChangeRequest;
use App\Http\Requests\RegistrationRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Services\Auth\ChangePasswordService;
use App\Services\Auth\LoginUserService;
use App\Services\Auth\LogoutUserService;
use App\Services\Auth\RefreshTokensService;
use App\Services\Auth\RegisterUserService;
use App\Services\Auth\TokenCookies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthController extends Controller
{
    public function __construct(
        private RegisterUserService $registerUser,
        private LoginUserService $loginUser,
        private RefreshTokensService $refreshTokens,
        private LogoutUserService $logoutUser,
        private ChangePasswordService $changePassword,
        private TokenCookies $tokenCookies,
    ) {}

    public function register(RegistrationRequest $request): JsonResponse
    {
        $user = $this->registerUser->handle(RegisterUserData::fromArray($request->validated()));

        return response()->json($user, 201);
    }

    public function getUser(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('role');

        return response()->json($user);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $tokens = $this->loginUser->handle(LoginCredentials::fromArray($request->validated()));

        return $this->tokenCookies->jsonWithTokens($tokens);
    }

    public function refresh(Request $request): JsonResponse
    {
        $tokens = $this->refreshTokens->handle($this->tokenCookies->refreshTokenFrom($request));

        if (! $tokens instanceof TokenPair) {
            return response()->json([
                'message' => 'Недействительный refresh-токен или пользователь не найден.',
            ], 401);
        }

        return $this->tokenCookies->jsonWithTokens($tokens);
    }

    public function logout(Request $request): Response
    {
        $this->logoutUser->handle(
            $this->tokenCookies->accessTokenFrom($request),
            $this->tokenCookies->refreshTokenFrom($request),
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

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $this->changePassword->handle(
            $user,
            $data['current_password'],
            $data['new_password'],
            $this->tokenCookies->accessTokenFrom($request),
        );

        return $this->tokenCookies->clear(
            response()->json(['message' => 'Пароль успешно изменён'])
        );
    }
}
