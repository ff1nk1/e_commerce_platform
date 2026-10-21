<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product-type keywords → catalog category scope
    |--------------------------------------------------------------------------
    |
    | First matching pattern in the current user message wins over sticky filters.
    | category_ilike is used for AttributeVocabulary SQL (ILIKE %value%).
    |
    */
    'product_types' => [
        [
            'key' => 'smartphones',
            'patterns' => ['телефон', 'телефоны', 'смартфон', 'смартфоны', 'айфон', 'iphone', 'android'],
            'category_ilike' => 'Смартфон',
        ],
        [
            'key' => 'laptops',
            'patterns' => ['ноутбук', 'ноутбуки', 'макбук', 'macbook', 'laptop', 'ultrabook'],
            'category_ilike' => 'Ноутбук',
        ],
        [
            'key' => 'monitors',
            'patterns' => ['монитор', 'мониторы', 'display', 'дисплей'],
            'category_ilike' => 'Монитор',
        ],
        [
            'key' => 'watches',
            'patterns' => ['часы', 'смарт.?час', 'watch', 'apple watch'],
            'category_ilike' => 'час',
        ],
        [
            'key' => 'jeans',
            'patterns' => ['джинсы', 'jeans'],
            'category_ilike' => 'Джинс',
        ],
        [
            'key' => 'tshirts',
            'patterns' => ['футболка', 'футболки', 't-?shirt'],
            'category_ilike' => 'Футболк',
        ],
        [
            'key' => 'jackets',
            'patterns' => ['куртка', 'куртки', 'jacket'],
            'category_ilike' => 'Куртк',
        ],
        [
            'key' => 'dresses',
            'patterns' => ['платье', 'платья', 'dress'],
            'category_ilike' => 'Плать',
        ],
        [
            'key' => 'components',
            'patterns' => ['комплектующ', 'видеокарт', 'процессор', 'ssd', 'hdd'],
            'category_ilike' => 'Комплектующ',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Synonym groups for attribute name resolution
    |--------------------------------------------------------------------------
    |
    | Keys are concept ids; values are lowercase aliases. Resolver picks the
    | vocabulary name that belongs to the same group (prefer names present in scope).
    |
    */
    'synonym_groups' => [
        'screen' => ['экран', 'диагональ', 'display', 'screen', 'дисплей'],
        'storage' => ['память', 'накопитель', 'storage', 'ssd', 'hdd'],
        'ram' => ['озу', 'ram', 'оперативн'],
        'battery' => ['аккумулятор', 'батарея', 'battery', 'ёмкость', 'емкость'],
        'color' => ['цвет', 'color', 'colour'],
        'size' => ['размер', 'size'],
        'brand' => ['бренд', 'brand', 'марка'],
        'material' => ['материал', 'material'],
        'camera' => ['камера', 'camera'],
        'os' => ['ос', 'система', 'os', 'android', 'ios'],
        'cpu' => ['процессор', 'cpu', 'chip'],
        'weight' => ['вес', 'weight'],
        'resolution' => ['разрешение', 'resolution'],
        'frequency' => ['частота', 'frequency', 'гц'],
    ],

    'vocab_cache_ttl_seconds' => (int) env('ATTRIBUTE_VOCAB_CACHE_TTL', 600),

    'vocab_prompt_cap' => 30,

    /*
    |--------------------------------------------------------------------------
    | Value matching strategies (vocabulary lives here, not in PHP services)
    |--------------------------------------------------------------------------
    */
    'value_match' => [
        'default_strategy' => 'text',
        'number_epsilon' => 0.05,
        'strategies_by_concept' => [
            'screen' => 'numeric',
            'storage' => 'numeric',
            'ram' => 'numeric',
            'battery' => 'numeric',
            'weight' => 'numeric',
            'frequency' => 'numeric',
            'resolution' => 'numeric',
            'camera' => 'numeric',
            'size' => 'size_range',
            'color' => 'text',
            'brand' => 'text',
            'material' => 'text',
            'os' => 'text',
            'cpu' => 'text',
        ],
        'strip_patterns' => [
            '/["”″]/u',
            '/\b(дюйм(а|ов)?|inch(es)?)\b/u',
        ],
        'stem_suffixes' => [
            'ого', 'его', 'ому', 'ему', 'ой', 'ый', 'ий', 'ая', 'ое', 'ые', 'их', 'ую', 'ими', 'ыми',
        ],
        'size_range_patterns' => [
            'from' => '/от\s*(\d+(?:[.,]\d+)?)/u',
            'exact' => '/^(\d+(?:[.,]\d+)?)$/u',
        ],
    ],

];
