<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

use Alxarafe\Arp\Dictionary\Internal\{Parser,Scalar,Number,Integer};
use Alxarafe\Arp\Dictionary\ExactInteger;

use function array_diff as difference;

/** Local host boundary. Every policy/composition mutation, including ABA, must use changeAccess(). */
final class ListGetHost
{
    private int $epoch = 0;
    private string $secret;
    private array $active;

    /** Targets: projection/defaultLimit/maxLimit/fields. Authorizer: allowed/fields/equalities (null unsupported).
     * Context identity must include principal, tenant and scope; policyRevision is supplied by the application.
     */
    public function __construct(
        private readonly ReadCatalog $catalog,
        private readonly RecordReader $reader,
        array $active,
        private readonly \Closure $authorizer
    ) {
        $this->active = self::targets($active);
        $this->secret = random_bytes(32);
    }

    private static function targets(array $active): array
    {
        foreach ($active as $target) {
            if (
                !is_string($target['projection']) || !is_array($target['fields'])
                || !is_int($target['defaultLimit']) || !is_int($target['maxLimit'])
                || $target['defaultLimit'] < 1 || $target['defaultLimit'] > $target['maxLimit']
                || $target['maxLimit'] > 100
            ) {
                throw new \InvalidArgumentException('Invalid target');
            }
        }
        return $active;
    }

    public function changeAccess(array $replacement, \Closure $mutation): void
    {
        $replacement = self::targets($replacement);
        ++$this->epoch;
        $this->active = $replacement;
        $mutation();
    }

    private static function need(bool $condition, string $code): void
    {
        ReadFailure::need($condition, $code);
    }
    private static function shape(mixed $value, array $allowed, string $code = 'INPUT_SHAPE'): object
    {
        self::need($value instanceof \stdClass && !difference(array_keys((array)$value), $allowed), $code);
        return $value;
    }
    private static function exact(object $value, array $keys, string $code): void
    {
        self::need(!difference($keys, array_keys((array)$value))
            && !difference(array_keys((array)$value), $keys), $code);
    }
    public static function find(array $values, string $id): object
    {
        foreach ($values as $value) {
            if ($value->id === $id) {
                return $value;
            }
        }
        throw new ReadFailure('CAPABILITY_UNAVAILABLE');
    }
    public static function scalar(string $type, mixed $value, array|object $constraints = []): array
    {
        try {
            $capacity = $type === 'INTEGER' ? Integer::capacity($constraints) : new \stdClass();
            return Scalar::validate(['id' => 'read','type' => $type,'constraints' => $capacity], $value);
        } catch (\Alxarafe\Arp\Dictionary\Internal\Failure $error) {
            return ['valid' => false,'issues' => [['code' => $error->errorCode]]];
        }
    }
    private function access(string $target, string $operation, array $context, array $fields): array
    {
        $decision = ($this->authorizer)($target, $operation, $context);
        self::need(is_array($decision) && ($decision['allowed'] ?? false) === true
            && is_array($decision['fields'] ?? null), 'UNAVAILABLE');
        foreach ($decision['fields'] as $field) {
            self::need(is_string($field), 'UNAVAILABLE');
        }
        self::need(!difference($fields, $decision['fields']), 'UNAVAILABLE');
        $granted = array_values(array_unique($decision['fields'], SORT_STRING));
        sort($granted, SORT_STRING);
        self::need(array_key_exists('equalities', $decision), 'CAPABILITY_UNAVAILABLE');
        // Detach application-owned policy objects before later callbacks can mutate them.
        $equalities = $decision['equalities'];
        self::need($equalities === null || $equalities instanceof \stdClass, 'CAPABILITY_UNAVAILABLE');
        $restrictions = $equalities === null ? null : (array)$equalities;
        if ($restrictions !== null) {
            ksort($restrictions, SORT_STRING);
        }
        return ['allowed' => true,'fields' => $granted,
            'equalities' => $restrictions === null ? null : (object)$restrictions];
    }

    public function invoke(string $request, array $context): array
    {
        try {
            self::need(strlen($request) <= 1048576, 'INPUT_SHAPE');
            try {
                $decoded = Parser::parse($request, 'json');
            } catch (\Throwable) {
                throw new ReadFailure('INPUT_SHAPE');
            }
            self::need($decoded instanceof \stdClass, 'INPUT_SHAPE');
            return $this->execute($decoded, $context);
        } catch (ReadFailure $e) {
            return self::failure($e->errorCode);
        } catch (\Throwable) {
            return self::failure('READ_FAILED');
        }
    }

    private function execute(object $request, array $context): array
    {
        $target = is_string($request->target ?? null) ? $request->target : '';
        $operation = is_string($request->operation ?? null) ? $request->operation : '';
        $registration = $this->active[$target] ?? null;
        self::need($registration !== null && in_array($operation, ['LIST','GET'], true), 'UNAVAILABLE');
        $initial = $this->access($target, $operation, $context, $registration['fields']);
        $epoch = $this->epoch;
        self::shape($request, ['target','operation','inputs']);
        $snapshot = $this->catalog->resolve();
        if (!$snapshot->valid) {
            throw new ReadFailure(match ($snapshot->projection()->issues[0]->family) {
                'execution-failure' => 'READ_FAILED', 'physical-incompatible' => 'INCOMPATIBLE_STORAGE',
                default => 'INVALID_DEFINITION'
            });
        }
        $projection = self::find($snapshot->projection()->projections, $registration['projection']);
        self::need(in_array($operation, $projection->operations, true), 'UNAVAILABLE');
        $fields = array_map(static fn($f) => $f->name, $projection->fields);
        self::need(
            !difference($fields, $registration['fields']) && !difference($registration['fields'], $fields),
            'INVALID_DEFINITION'
        );
        self::need(!difference($fields, $initial['fields']), 'UNAVAILABLE');
        self::need($initial['equalities'] !== null, 'CAPABILITY_UNAVAILABLE');
        $prepared = $this->reader->prepare($snapshot, $registration['projection'], $initial['equalities']);
        $keys = $prepared->keys;
        self::need(count($keys) > 0 && count($keys) <= 7 && PHP_INT_SIZE === 8, 'CAPABILITY_UNAVAILABLE');
        $generation = $this->catalog->publishRead($snapshot, fn() => $this->catalog->generation());
        self::need(
            is_string($context['identity'] ?? null) && is_string($context['policyRevision'] ?? null),
            'UNAVAILABLE'
        );
        $binding = [$target,$registration['projection'],$context['identity'],$context['policyRevision'],(string)$epoch];
        $inputs = self::shape($request->inputs ?? null, $operation === 'GET' ? ['key'] : ['limit','continuation']);
        $get = $operation === 'GET';
        $opaque = (bool)difference(array_column($keys, 'name'), $fields);
        $limit = $registration['defaultLimit'];
        $position = null;
        $absent = false;
        if ($get) {
            self::exact($inputs, ['key'], 'INPUT_SHAPE');
            $key = self::shape($inputs->key, $opaque ? ['reference'] : ['components'], 'INVALID_KEY');
            if ($opaque) {
                self::exact($key, ['reference'], 'INVALID_KEY');
                self::need(is_string($key->reference) && strlen($key->reference) > 0
                    && strlen($key->reference) <= 128, 'INVALID_KEY');
                $decoded = $this->open($key->reference, $binding, 'GET', $generation, $keys);
                $absent = $decoded === null;
                $position = $decoded['position'] ?? null;
            } else {
                self::exact($key, ['components'], 'INVALID_KEY');
                self::need(is_array($key->components) && count($key->components) === count($keys), 'INVALID_KEY');
                $position = [];
                foreach ($keys as $i => $column) {
                    $component = self::shape($key->components[$i], ['field','type','value'], 'INVALID_KEY');
                    self::exact($component, ['field','type','value'], 'INVALID_KEY');
                    self::need(
                        $component->field === $column['name'] && $component->type === $column['type'],
                        'INVALID_KEY'
                    );
                    $decoded = self::scalar($column['type'], $component->value, $column['constraints']);
                    self::need($decoded['valid'], 'INVALID_KEY');
                    $position[] = $decoded['normalizedValue'];
                }
            }
        } else {
            if (property_exists($inputs, 'limit')) {
                $n = $inputs->limit;
                self::need(is_int($n) || $n instanceof ExactInteger, 'INPUT_SHAPE');
                self::need(
                    Number::compare((string)$n, '1') >= 0 && Number::compare((string)$n, '100') <= 0,
                    'INPUT_SHAPE'
                );
                $limit = min((int)(string)$n, $registration['maxLimit']);
            }
            if (property_exists($inputs, 'continuation')) {
                self::need(is_string($inputs->continuation), 'INVALID_CURSOR');
                $decoded = $this->open($inputs->continuation, $binding, 'LIST', $generation, $keys);
                self::need($decoded !== null && $decoded['limit'] === $limit, 'INVALID_CURSOR');
                $position = $decoded['position'];
            }
        }
        $check = function () use (
            $target,
            $operation,
            $context,
            $fields,
            $registration,
            $initial,
            $epoch,
            $snapshot,
            $generation
        ): void {
            self::need(($this->active[$target] ?? null) === $registration, 'GENERATION_CHANGED');
            self::need(
                serialize($this->access($target, $operation, $context, $fields)) === serialize($initial),
                'UNAVAILABLE'
            );
            self::need($this->epoch === $epoch && $this->catalog->current() === $snapshot
                && $this->catalog->generation() === $generation, 'GENERATION_CHANGED');
        };
        $current = function () use ($check): bool {
            $check();
            return true;
        };
        $attempt = null;
        try {
            if (!$absent) {
                $attempt = $this->reader->open(new ReadPlan($prepared, $position, $get, $limit), $current);
            }
            $rows = $attempt?->rows() ?? [];
            foreach ($rows as $row) {
                foreach ($keys as $i => $column) {
                    self::need(
                        isset($row['key'][$i])
                        && self::scalar($column['type'], $row['key'][$i], $column['constraints'])['valid'],
                        'ROW_IDENTITY_INVALID'
                    );
                }
            }
            $check();
            $response = $this->catalog->publishRead(
                $snapshot,
                function () use ($rows, $get, $keys, $opaque, $binding, $generation, $limit, $attempt, $check): array {
                    if ($get) {
                        self::need(count($rows) <= 1, 'ROW_IDENTITY_INVALID');
                        $output = !$rows ? ['found' => false] : ['found' => true,'record' =>
                            $this->record($rows[0], $keys, $opaque, $binding, $generation)];
                    } else {
                        $more = count($rows) > $limit;
                        $visible = array_slice($rows, 0, $limit);
                        $records = array_map(
                            fn($row) => $this->record($row, $keys, $opaque, $binding, $generation),
                            $visible
                        );
                        $output = ['records' => $records,'page' => ['limit' => $limit,'hasMore' => $more,
                            'next' => $more ? $this->seal(
                                $binding,
                                'LIST',
                                $generation,
                                $visible[count($visible) - 1]['key'],
                                $limit
                            ) : null]];
                    }
                    $attempt?->complete();
                    $check();
                    return ['status' => 'success','diagnostics' => [],'output' => $output];
                }
            );
        } finally {
            // A close failure overrides even a privately completed success. Never return from this block.
            $attempt?->close();
        }
        $check();
        return $response;
    }

    private function record(array $row, array $keys, bool $opaque, array $binding, int $generation): array
    {
        if ($opaque) {
            $key = ['reference' => $this->seal($binding, 'GET', $generation, $row['key'], 0)];
        } else {
            $components = [];
            foreach ($keys as $i => $column) {
                $components[] = ['field' => $column['name'],
                'type' => $column['type'],'value' => $row['key'][$i]];
            }
            $key = ['components' => $components];
        }
        return ['key' => $key,'values' => $row['values']];
    }
    private function seal(array $binding, string $purpose, int $generation, array $position, int $limit): string
    {
        $payload = pack('J', $generation) . chr($limit);
        foreach ($position as $component) {
            $payload .= Integer::packKey($component);
        }
        $nonce = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt(
            $payload,
            'aes-256-gcm',
            $this->secret,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            json_encode([$binding,$purpose], JSON_THROW_ON_ERROR),
            16
        );
        self::need(is_string($encrypted), 'READ_FAILED');
        return rtrim(strtr(base64_encode($nonce . $tag . $encrypted), '+/', '-_'), '=');
    }
    private function open(string $token, array $binding, string $purpose, int $generation, array $keys): ?array
    {
        if (strlen($token) > 128 || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $token)) {
            return null;
        }
        $bytes = base64_decode(strtr($token, '-_', '+/'), true);
        if ($bytes === false || strlen($bytes) < 45 || (strlen($bytes) - 37) % 8 !== 0) {
            return null;
        }
        $payload = openssl_decrypt(
            substr($bytes, 28),
            'aes-256-gcm',
            $this->secret,
            OPENSSL_RAW_DATA,
            substr($bytes, 0, 12),
            substr($bytes, 12, 16),
            json_encode([$binding,$purpose], JSON_THROW_ON_ERROR)
        );
        if ($payload === false) {
            return null;
        }
        self::need(unpack('J', substr($payload, 0, 8))[1] === $generation, 'GENERATION_EXPIRED');
        if (intdiv(strlen($payload) - 9, 8) !== count($keys)) {
            return null;
        }
        $position = [];
        for ($i = 9; $i < strlen($payload); $i += 8) {
            $column = $keys[intdiv($i - 9, 8)];
            $capacity = Integer::derive($column['constraints']);
            $number = Integer::unpackKey(substr($payload, $i, 8), $capacity['signed']);
            if (!self::scalar($column['type'], $number, $column['constraints'])['valid']) {
                return null;
            }
            $position[] = $number;
        }
        return ['limit' => ord($payload[8]),'position' => $position];
    }
    private static function failure(string $code): array
    {
        $failed = in_array(
            $code,
            ['READ_FAILED','ROW_DECODE_FAILED','ROW_IDENTITY_INVALID','GENERATION_CHANGED'],
            true
        );
        $family = $failed ? 'execution-failure' : match ($code) {
            'INPUT_SHAPE','INVALID_KEY','INVALID_CURSOR' => 'invalid-data',
            'INVALID_DEFINITION' => 'definition-invalid', 'INCOMPATIBLE_STORAGE' => 'physical-incompatible',
            default => 'operation-not-allowed'
        };
        return ['status' => $failed ? 'failed' : 'rejected','diagnostics' => [
            ['code' => 'CDS_LISTGET_' . $code,'family' => $family]]];
    }
}
