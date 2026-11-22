<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds\Seed;

/** RFC 4180-style CSV intake. Values remain exact strings; no numeric or null coercion is applied. */
final class CsvTable
{
    private function __construct(
        public readonly array $header,
        public readonly array $rows
    ) {
    }

    /** @return array{header:list<string>,rows:list<array{line:int,values:array<string,string>}>} */
    public static function parse(string $bytes, string $delimiter = ';'): self
    {
        if (
            strlen($delimiter) !== 1
            || in_array($delimiter, ['"', "\r", "\n", "\0"], true)
        ) {
            throw new \InvalidArgumentException('Invalid CSV delimiter');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if ($bytes === '') {
            throw new SeedFailure('SEED_CSV_HEADER_MISSING');
        }
        if (preg_match('//u', $bytes) !== 1) {
            throw new SeedFailure('SEED_CSV_ENCODING');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new SeedFailure('SEED_CSV_UNAVAILABLE');
        }
        fwrite($stream, $bytes);
        rewind($stream);

        $header = null;
        $rows = [];
        $line = 1;
        $offset = 0;
        try {
            while (($fields = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $end = ftell($stream);
                $start = $offset;
                $offset = $end === false ? $offset : $end;
                $recordLine = $line;
                if ($end !== false) {
                    $line += substr_count(substr($bytes, $start, $end - $start), "\n");
                }
                if ($fields === [null]) {
                    $fields = [''];
                }
                $fields = array_map(static fn($value) => $value ?? '', $fields);
                if ($header === null) {
                    $header = self::header($fields);
                    continue;
                }
                if (count($fields) !== count($header)) {
                    $column = count($fields) < count($header) ? $header[count($fields)] : null;
                    throw new SeedFailure(
                        'SEED_CSV_ROW_WIDTH',
                        $recordLine,
                        $column,
                        ['expected' => count($header),'actual' => count($fields)]
                    );
                }
                $rows[] = ['line' => $recordLine,'values' => array_combine($header, $fields)];
            }
        } finally {
            fclose($stream);
        }
        if ($header === null) {
            throw new SeedFailure('SEED_CSV_HEADER_MISSING');
        }
        return new self($header, $rows);
    }

    private static function header(array $fields): array
    {
        $seen = [];
        foreach ($fields as $index => $name) {
            if ($name === '') {
                throw new SeedFailure('SEED_CSV_HEADER_EMPTY', 1, (string)($index + 1));
            }
            if (isset($seen[$name])) {
                throw new SeedFailure('SEED_CSV_HEADER_DUPLICATE', 1, $name);
            }
            $seen[$name] = true;
        }
        return $fields;
    }
}
