<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ChangeRoleRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with('role');

        if ($request->filled('email')) {
            $query->where('email', 'like', '%'.$request->input('email').'%');
        }

        $users = $query->paginate($request->input('per_page', 10));

        return response()->json($users);
    }

    // Добавим метод для получения списка ролей для фронтенда
    public function getRoles(): JsonResponse
    {
        return response()->json(Role::all());
    }

    public function changeRole(ChangeRoleRequest $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $user->role_id = $request->validated()['role_id'];
        $user->save();

        // Подгружаем обновленную роль, чтобы вернуть её на фронт
        $user->load('role');

        return response()->json([
            'message' => 'Роль успешно обновлена',
            'user' => $user,
        ]);
    }
}
