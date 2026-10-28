<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ChatRequest;
use App\Services\Chat\RecommendationChatService;
use Illuminate\Http\JsonResponse;

final class ChatController extends Controller
{
    public function __construct(
        private readonly RecommendationChatService $chat,
    ) {}

    public function __invoke(ChatRequest $request): JsonResponse
    {
        $result = $this->chat->chat($request->validated());

        return response()->json($result);
    }
}
