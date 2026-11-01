<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\Chat\ChatTurn;
use App\Http\Requests\ChatRequest;
use App\Services\Chat\SendChatMessageService;
use Illuminate\Http\JsonResponse;

final class ChatController extends Controller
{
    public function __construct(
        private readonly SendChatMessageService $chat,
    ) {}

    public function __invoke(ChatRequest $request): JsonResponse
    {
        $result = $this->chat->handle(ChatTurn::fromArray($request->validated()));

        return response()->json($result->toArray());
    }
}
