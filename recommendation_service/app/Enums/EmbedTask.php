<?php

declare(strict_types=1);

namespace App\Enums;

enum EmbedTask: string
{
    case RetrievalDocument = 'RETRIEVAL_DOCUMENT';
    case RetrievalQuery = 'RETRIEVAL_QUERY';
}
