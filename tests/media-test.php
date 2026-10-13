<?php
declare(strict_types=1);
require dirname(__DIR__) . '/media.php';
$cases = [
    ['bytes=0-0', 100, [0, 0]], ['bytes=0-9', 100, [0, 9]],
    ['bytes=10-', 100, [10, 99]], ['bytes=-10', 100, [90, 99]],
    ['bytes=-999', 100, [0, 99]], ['bytes=90-999', 100, [90, 99]],
    ['bytes=100-', 100, null], ['bytes=9-0', 100, null],
    ['bytes=-0', 100, null], ['bytes=-', 100, null],
    ['bytes=0-1,4-5', 100, null], ['bytes=0-1junk', 100, null],
    ['bytes=0-0', 0, null],
];
foreach ($cases as [$header, $size, $expected]) {
    if (parseByteRange($header, $size) !== $expected) throw new RuntimeException('Range failed: ' . $header);
}
echo 'PASS: ' . count($cases) . " byte-range cases\n";
