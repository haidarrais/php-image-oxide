<?php

declare(strict_types=1);

namespace ImageOxide\Driver;

/**
 * A driver executes an op chain against one input file and writes the result.
 * The GD driver must match the daemon's pixel semantics exactly — the two
 * implementations are the graceful-degradation contract.
 */
interface Driver
{
    /**
     * @param array<int, array<string, mixed>> $ops
     */
    public function process(string $inputPath, array $ops, string $outputPath, ?int $quality = null): ProcessResult;

    public function capabilities(): Capabilities;
}
