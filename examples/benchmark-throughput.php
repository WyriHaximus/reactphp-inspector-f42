<?php

use WyriHaximus\React\Inspector\F42\GlobalState;

require dirname(__DIR__) . '/vendor/autoload.php';

$args = getopt('i:o:t:');
$if = isset($args['i']) ? $args['i'] : '/dev/zero';
$of = isset($args['o']) ? $args['o'] : '/dev/null';
$t  = isset($args['t']) ? $args['t'] : 1;

// passing file descriptors requires mapping paths (https://bugs.php.net/bug.php?id=53465)
$if = str_replace('/dev/fd/', 'php://fd/', $if);
$of = str_replace('/dev/fd/', 'php://fd/', $of);

$loop = new React\EventLoop\StreamSelectLoop();

// setup information stream
$info = new React\Stream\WritableResourceStream(STDERR, $loop);
if (extension_loaded('xdebug')) {
    $info->write('NOTICE: The "xdebug" extension is loaded, this has a major impact on performance.' . PHP_EOL);
}
$info->write('piping from ' . $if . ' to ' . $of . ' (for max ' . $t . ' second(s)) ...'. PHP_EOL);

// setup input and output streams and pipe inbetween
$in = new React\Stream\ReadableResourceStream(fopen($if, 'r'), $loop);
$out = new React\Stream\WritableResourceStream(fopen($of, 'w'), $loop);
$in->pipe($out);

$timer = $loop->addPeriodicTimer(1, function () {
    $state = GlobalState::get();
    GlobalState::reset();

    $read = $state['read'];
    $write = $state['write'];

    echo 'Read: ', round(($read / 1024) /1024), 'MB/s', PHP_EOL;
    echo 'write: ', round(($write / 1024) /1024), 'MB/s', PHP_EOL;
});

// stop input stream in $t seconds
$start = microtime(true);
$timeout = $loop->addTimer($t, function () use ($in, &$bytes, $timer) {
    $in->close();
    $timer->cancel();
});

// print stream position once stream closes
$in->on('close', function () use ($in, $start, $timeout, $info) {
    $t = microtime(true) - $start;
    $timeout->cancel();

    $bytes = ftell($in->stream);

    $info->write('read ' . $bytes . ' byte(s) in ' . round($t, 3) . ' second(s) => ' . round($bytes / 1024 / 1024 / $t, 1) . ' MiB/s' . PHP_EOL);
    $info->write('peak memory usage of ' . round(memory_get_peak_usage(true) / 1024 / 1024, 1) . ' MiB' . PHP_EOL);
});

$loop->run();
