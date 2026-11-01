<?php

declare(strict_types=1);

namespace App\Services\Gateway\Forwarders;

use App\DTO\Gateway\ProxyOptions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface Forwarder
{
    public function forward(Request $request, string $url, ProxyOptions $options): Response;
}
