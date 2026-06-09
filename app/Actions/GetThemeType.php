<?php

declare(strict_types=1);

namespace App\Actions;

class GetThemeType
{
    public array $types = ['primary', 'success', 'info', 'danger', 'warning'];

    public int $seed;

    public function handle($format = '?', $seed = '')
    {
        $this->seed = crc32($seed);

        return str_replace('?', $this->randomType(), $format);
    }

    public function randomType()
    {
        mt_srand($this->seed);

        return $this->types[mt_rand(0, count($this->types) - 1)] ?? '';
    }
}
