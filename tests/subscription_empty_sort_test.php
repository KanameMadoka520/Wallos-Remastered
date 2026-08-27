<?php

function wallos_subscription_empty_sort_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    foreach ([
        'subscriptions.php' => 'foreach ($subscriptions as $subscription)',
        'endpoints/subscriptions/get.php' => 'foreach ($subscriptions as $subscription)',
    ] as $relativePath => $loopNeedle) {
        $source = file_get_contents(__DIR__ . '/../' . $relativePath);
        wallos_subscription_empty_sort_assert($source !== false, 'Unable to read ' . $relativePath . '.');

        $initializationPosition = strrpos($source, '$print = [];');
        $loopPosition = $initializationPosition === false
            ? false
            : strpos($source, $loopNeedle, $initializationPosition);
        wallos_subscription_empty_sort_assert(
            $initializationPosition !== false
                && $loopPosition !== false
                && $initializationPosition < $loopPosition,
            $relativePath . ' must initialize the rendered list before an empty subscription loop.'
        );
    }

    $emptyList = [];
    usort($emptyList, static function ($left, $right) {
        return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });
    wallos_subscription_empty_sort_assert($emptyList === [], 'Sorting an initialized empty list must stay safe.');

    echo "Empty subscription sorting regression test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
