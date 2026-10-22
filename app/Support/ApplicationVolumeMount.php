<?php

namespace App\Support;

class ApplicationVolumeMount
{
    public static function format(string $source, string $destination, bool $readOnly): string
    {
        [$path, $mode] = array_pad(explode(':', $destination, 2), 2, '');
        $options = $mode === '' ? [] : explode(',', $mode);
        if ($readOnly) {
            $options = array_values(array_diff($options, ['ro', 'rw']));
            array_unshift($options, 'ro');
        }

        return $source.':'.$path.($options === [] ? '' : ':'.implode(',', $options));
    }
}
