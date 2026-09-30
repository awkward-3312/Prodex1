<?php

// Technical browser/PWA sizes only. Originals are never modified or recolored.
$root = dirname(__DIR__);
$brand = json_decode(file_get_contents($root.'/resources/brand/prodex.json'), true, 512, JSON_THROW_ON_ERROR);
$source = imagecreatefrompng($root.'/public/'.$brand['assets']['icon']);
$directory = $root.'/public/images/brand-assets/icons';
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
foreach ([16, 32, 180, 192, 512] as $size) {
    $image = imagecreatetruecolor($size, $size);
    imagecopyresampled($image, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
    imagepng($image, $directory.'/icon-'.$size.'.png');
    if ($size === 32) {
        $png = file_get_contents($directory.'/icon-32.png');
        // ICO directory with one PNG-encoded image (32-bit RGBA-compatible format).
        $ico = pack('vvv', 0, 1, 1).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22).$png;
        file_put_contents($root.'/public/favicon.ico', $ico);
    }
}
