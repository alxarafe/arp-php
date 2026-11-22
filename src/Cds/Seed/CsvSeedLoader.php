<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds\Seed;

use Alxarafe\Arp\Dictionary\Cds\{CrudHost,ListGetHost,ReadCatalog};
use Alxarafe\Arp\Dictionary\Internal\{Projection,Scalar};

/** Maps CSV headers to ARP fields, pre-validates every row and inserts through managed CREATE. */
final class CsvSeedLoader
{
    public function __construct(
        private readonly CrudHost $host,
        private readonly ReadCatalog $catalog,
        private readonly string $target,
        private readonly string $projection,
        private readonly array $context,
        private readonly string $delimiter = ';'
    ) {
    }

    public function load(string $csv): array
    {
        $plan = $this->inspect($csv);
        if ($plan['errors'] !== []) {
            return [
                'processed' => $plan['processed'],
                'inserted' => 0,
                'rejected' => $plan['rejected'],
                'errors' => $plan['errors']
            ];
        }
        $inserted = 0;
        foreach ($plan['rows'] as $row) {
            $response = $this->host->invoke(Projection::encode((object)[
                'target' => $this->target,
                'operation' => 'CREATE',
                'inputs' => (object)['values' => (object)$row['values']]
            ]), $this->context);
            if (($response['status'] ?? '') !== 'success') {
                throw new SeedFailure(
                    'SEED_INSERT_REJECTED',
                    $row['line'],
                    null,
                    [
                        'response' => $response,
                        'processed' => $plan['processed'],
                        'inserted' => $inserted,
                        'rejected' => $plan['rejected'] + 1
                    ]
                );
            }
            ++$inserted;
        }
        return ['processed' => $plan['processed'],'inserted' => $inserted,'rejected' => 0,'errors' => []];
    }

    public function inspect(string $csv): array
    {
        $table = CsvTable::parse($csv, $this->delimiter);
        $snapshot = $this->catalog->resolve();
        if (!$snapshot->valid) {
            throw new SeedFailure('SEED_CATALOG_INVALID', null, null, [
                'issues' => $snapshot->projection()->issues
            ]);
        }
        $projection = ListGetHost::find($snapshot->projection()->projections, $this->projection);
        $model = ListGetHost::find($snapshot->projection()->models, $projection->entity);
        $fields = [];
        foreach ($model->fields as $field) {
            $fields[$field->name] = $field;
        }
        $visible = array_column($projection->fields, 'name');
        $headerSet = array_fill_keys($table->header, true);
        $errors = [];
        foreach ($table->header as $name) {
            if (!in_array($name, $visible, true)) {
                $errors[] = $this->error(1, $name, 'SEED_UNKNOWN_FIELD');
            }
        }
        foreach ($fields as $name => $field) {
            $provided = isset($headerSet[$name]);
            $required = !$provided && !(($field->physical->nullable ?? false) === true
                && !in_array($name, $model->physical->primaryKey, true));
            if ($required) {
                $errors[] = $this->error(1, $name, 'SEED_REQUIRED_FIELD');
            }
        }
        $structural = $errors !== [];
        $seen = [];
        $rows = [];
        $rejectedRows = 0;
        foreach ($table->rows as $entry) {
            $line = $entry['line'];
            $values = [];
            $rowErrors = [];
            foreach ($table->header as $name) {
                if (!isset($fields[$name])) {
                    continue;
                }
                $value = $entry['values'][$name];
                $values[$name] = $value;
                $decoded = self::scalar($fields[$name], $value);
                if (!$decoded['valid']) {
                    $rowErrors[] = $this->error($line, $name, 'SEED_INVALID_VALUE', [
                        'issues' => $decoded['issues']
                    ]);
                }
            }
            $key = $this->key($model, $fields, $values);
            if ($key !== null) {
                $identity = implode("\x1F", $key);
                if (isset($seen[$identity])) {
                    $rowErrors[] = $this->error(
                        $line,
                        $model->physical->primaryKey[0],
                        'SEED_DUPLICATE_KEY',
                        ['firstRow' => $seen[$identity]]
                    );
                } else {
                    $seen[$identity] = $line;
                }
            }
            if ($rowErrors === [] && !$structural) {
                $response = $this->host->validateCreate($this->target, (object)$values, $this->context);
                if (($response['status'] ?? '') !== 'success') {
                    $code = $response['diagnostics'][0]['code'] ?? 'SEED_CREATE_REJECTED';
                    if (
                        in_array($code, [
                            'UNAVAILABLE',
                            'CAPABILITY_UNAVAILABLE',
                            'INVALID_DEFINITION',
                            'INCOMPATIBLE_STORAGE',
                            'PERSISTENCE_FAILED',
                            'GENERATION_EXPIRED'
                        ], true)
                    ) {
                        throw new SeedFailure('SEED_CREATE_REJECTED', $line, null, ['response' => $response]);
                    }
                    $column = $code === 'REQUIRED_VALUE'
                        ? $this->missingRequired($fields, $values, $model)
                        : null;
                    $rowErrors[] = $this->error($line, $column, 'SEED_' . $code, ['response' => $response]);
                }
            }
            if ($rowErrors !== []) {
                ++$rejectedRows;
                $errors = array_merge($errors, $rowErrors);
            }
            $rows[] = ['line' => $line,'values' => $values,'errors' => $rowErrors];
        }
        $processed = count($rows);
        $rejected = $structural ? $processed : $rejectedRows;
        return [
            'header' => $table->header,
            'rows' => $rows,
            'errors' => $errors,
            'processed' => $processed,
            'valid' => $structural ? 0 : $processed - $rejectedRows,
            'rejected' => $rejected
        ];
    }

    private static function scalar(object $field, string $value): array
    {
        return Scalar::validate([
            'id' => $field->name,
            'type' => $field->type,
            'constraints' => $field->constraints
        ], $value);
    }

    private function key(object $model, array $fields, array $values): ?array
    {
        $key = [];
        foreach ($model->physical->primaryKey as $name) {
            if (!isset($fields[$name]) || !array_key_exists($name, $values)) {
                return null;
            }
            $decoded = self::scalar($fields[$name], $values[$name]);
            if (!$decoded['valid']) {
                return null;
            }
            $key[] = $decoded['normalizedValue'];
        }
        return $key;
    }

    private function missingRequired(array $fields, array $values, object $model): ?string
    {
        foreach ($fields as $name => $field) {
            if (
                !array_key_exists($name, $values)
                && !(($field->physical->nullable ?? false) === true
                && !in_array($name, $model->physical->primaryKey, true))
            ) {
                return $name;
            }
        }
        return null;
    }

    private function error(int $row, ?string $column, string $code, array $details = []): array
    {
        return ['row' => $row,'column' => $column,'code' => $code] + $details;
    }
}
