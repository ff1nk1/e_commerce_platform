<?php

namespace App\Support;

final class PgVectorLiteral
{
    /**
     * @param  list<float>  $vector
     */
    public static function from(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn (float $v): string => sprintf('%.8F', $v),
            $vector
        )).']';
    }
}
