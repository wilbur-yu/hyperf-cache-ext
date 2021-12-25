<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace App\Kernel\Utils\Packer;

use Hyperf\Contract\PackerInterface;

class PhpSerializerPacker
{
    public function pack($data): string|int
    {
        return is_numeric($data) && !in_array($data, [INF, -INF], true)
               && !is_nan((float)$data) ? $data : serialize($data);
    }

    public function unpack(string $data)
    {
        return is_numeric($data) ? $data : unserialize($data);
    }
}
