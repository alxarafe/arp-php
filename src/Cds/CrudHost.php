<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Cds;

use Alxarafe\Arp\Dictionary\Internal\{Parser,Scalar};

/** Local synchronous host. All application policy mutations must be serialized through changeAccess(). */
final class CrudHost
{
    private ListGetHost $reads;
    private int $epoch = 0;
    private array $evidence = [];
    public function __construct(
        private readonly ReadCatalog $catalog,
        RecordReader $reader,
        private readonly RecordWriter $writer,
        private array $active,
        private readonly \Closure $authorizer,
        private readonly ?\Closure $validator = null,
        private readonly int $evidenceSeconds = 300,
        private readonly ?\Closure $clock = null
    ) {
        if ($evidenceSeconds < 1 || $evidenceSeconds > 3600) {
            throw new \InvalidArgumentException('Evidence lifetime outside supported bounds');
        }
        $this->reads = new ListGetHost($catalog, $reader, $active, function ($target, $operation, $context): array {
            if (!in_array($operation, $this->active[$target]['operations'] ?? [], true)) {
                return ['allowed' => false];
            }
            return ($this->authorizer)($target, $operation, $context);
        });
    }
    public function changeAccess(array $replacement, \Closure $mutation): void
    {
        ++$this->epoch;
        $this->active = $replacement;
        $this->evidence = [];
        $this->reads->changeAccess($replacement, $mutation);
    }
    private static function shape(mixed $value, array $allowed, array $required): object
    {
        WriteFailure::need($value instanceof \stdClass
            && !array_diff(array_keys((array)$value), $allowed)
            && !array_diff($required, array_keys((array)$value)), 'INPUT_SHAPE', 'invalid-data');
        return $value;
    }
    private function access(string $target, string $operation, array $context): array
    {
        $decision = ($this->authorizer)($target, $operation, $context);
        WriteFailure::need(is_array($decision) && ($decision['allowed'] ?? false) === true
            && is_array($decision['fields'] ?? null), 'UNAVAILABLE');
        WriteFailure::need(is_array($decision['writableFields'] ?? $decision['fields']), 'UNAVAILABLE');
        foreach ([...$decision['fields'], ...($decision['writableFields'] ?? $decision['fields'])] as $field) {
            WriteFailure::need(is_string($field), 'UNAVAILABLE');
        }
        $fields = array_values(array_unique($decision['fields']));
        $writable = array_values(array_unique($decision['writableFields'] ?? $fields));
        sort($fields, SORT_STRING);
        sort($writable, SORT_STRING);
        WriteFailure::need(($decision['equalities'] ?? null) instanceof \stdClass, 'CAPABILITY_UNAVAILABLE');
        $scope = (array)$decision['equalities'];
        ksort($scope);
        return ['fields' => $fields,'writableFields' => $writable,'equalities' => (object)$scope];
    }
    private function time(): float
    {
        return $this->clock === null ? (hrtime(true) / 1e9) : ($this->clock)();
    }
    private function receipt(string $purpose, string $binding): array
    {
        $handle = bin2hex(random_bytes(32));
        foreach ($this->evidence as $key => $item) {
            if ($item['expires'] <= $this->time()) {
                unset($this->evidence[$key]);
            }
        }
        if (count($this->evidence) >= 1024) {
            array_shift($this->evidence);
        }
        $this->evidence[$handle] = ['purpose' => $purpose,'binding' => $binding,
            'expires' => $this->time() + $this->evidenceSeconds];
        return [$handle, ['reference' => $handle]];
    }
    private function open(object $reference, string $purpose, string $binding): array
    {
        self::shape($reference, ['reference'], ['reference']);
        WriteFailure::need(is_string($reference->reference), 'UNAVAILABLE');
        $item = $this->evidence[$reference->reference] ?? null;
        WriteFailure::need(
            $item !== null && $item['purpose'] === $purpose && $item['binding'] === $binding
            && $item['expires'] > $this->time(),
            str_starts_with($purpose, 'UPDATE') ? 'INVALID_CONFIRMATION' : 'UNAVAILABLE',
            str_starts_with($purpose, 'UPDATE') ? 'invalid-data' : 'operation-not-allowed'
        );
        return $item;
    }
    public function invoke(string $request, array $context): array
    {
        try {
            WriteFailure::need(strlen($request) <= 1048576, 'INPUT_SHAPE', 'invalid-data');
            try {
                $decoded = Parser::parse($request, 'json');
            } catch (\Throwable) {
                throw new WriteFailure('INPUT_SHAPE', 'invalid-data');
            }
            if (in_array($decoded->operation ?? null, ['LIST','GET'], true)) {
                return $this->reads->invoke($request, $context);
            }
            return $this->execute($decoded, $context);
        } catch (WriteFailure $e) {
            return $e->response();
        } catch (\Throwable) {
            return (new WriteFailure('PERSISTENCE_FAILED', 'execution-failure', 'failed'))->response();
        }
    }
    /** Separate capability: returns a fresh authorized record plus protected DELETE evidence, never changes GET. */
    public function readForDelete(string $target, object $key, array $context): array
    {
        try {
            return $this->execute((object)['target' => $target,'operation' => 'DELETE',
                'inputs' => (object)['key' => $key]], $context, 'read-delete');
        } catch (WriteFailure $e) {
            return $e->response();
        } catch (\Throwable) {
            return (new WriteFailure('PERSISTENCE_FAILED', 'execution-failure', 'failed'))->response();
        }
    }
    /** Returns the state to display before consent; does not authorize an overwrite. */
    public function readForUpdateConfirmation(string $request, array $context): array
    {
        return $this->confirmationStep($request, $context, 'confirm-read');
    }
    /** Trusted application entry point: activate only the displayed challenge after explicit consent. */
    public function confirmUpdate(string $request, array $context, object $challenge): array
    {
        return $this->confirmationStep($request, $context, 'confirm-update', $challenge);
    }
    private function confirmationStep(
        string $request,
        array $context,
        string $purpose,
        ?object $challenge = null
    ): array {
        try {
            WriteFailure::need(strlen($request) <= 1048576, 'INPUT_SHAPE', 'invalid-data');
            $decoded = Parser::parse($request, 'json');
            WriteFailure::need(($decoded->operation ?? null) === 'UPDATE', 'INPUT_SHAPE', 'invalid-data');
            return $this->execute($decoded, $context, $purpose, $challenge);
        } catch (WriteFailure $e) {
            return $e->response();
        } catch (\Throwable) {
            return (new WriteFailure('INPUT_SHAPE', 'invalid-data'))->response();
        }
    }
    private static function normalize(object $field, mixed $value, bool $historical = false): mixed
    {
        if ($value === null) {
            WriteFailure::need(($field->physical->nullable ?? false) === true, 'INVALID_VALUE', 'invalid-data');
            return null;
        }
        $element = ['id' => $field->name,'type' => $field->type,
            'constraints' => $historical && $field->type === 'INTEGER'
                ? \Alxarafe\Arp\Dictionary\Internal\Integer::capacity($field->constraints)
                : ($historical ? new \stdClass() : $field->constraints)];
        $decoded = Scalar::validate($element, $value);
        WriteFailure::need($decoded['valid'], 'INVALID_VALUE', 'invalid-data');
        return $decoded['normalizedValue'];
    }
    private function rule(string $operation, array $candidate, array $context, array $changed): void
    {
        if ($this->validator !== null) {
            WriteFailure::need(
                ($this->validator)($operation, (object)$candidate, $context, $changed) === true,
                'BUSINESS_REJECTED',
                'invalid-data'
            );
        }
    }
    private static function inScope(array $row, object $equalities): bool
    {
        foreach ($equalities as $name => $value) {
            if (!array_key_exists($name, $row) || $row[$name] !== $value) {
                return false;
            }
        }
        return true;
    }
    private static function scope(array $row, object $equalities): void
    {
        WriteFailure::need(self::inScope($row, $equalities), 'UNAVAILABLE');
    }
    private function execute(
        mixed $request,
        array $context,
        string $purpose = 'invoke',
        ?object $challenge = null
    ): array {
        WriteFailure::need($request instanceof \stdClass, 'INPUT_SHAPE', 'invalid-data');
        $target = is_string($request->target ?? null) ? $request->target : '';
        $op = is_string($request->operation ?? null) ? $request->operation : '';
        $registration = $this->active[$target] ?? null;
        WriteFailure::need($registration !== null && in_array($op, ['CREATE','UPDATE','DELETE'], true)
            && in_array($op, $registration['operations'] ?? [], true), 'UNAVAILABLE');
        $access = $this->access($target, $op, $context);
        self::shape($request, ['target','operation','inputs'], ['target','operation','inputs']);
        WriteFailure::need(is_string($context['identity'] ?? null)
            && is_string($context['policyRevision'] ?? null), 'UNAVAILABLE');
        $snapshot = $this->catalog->resolve();
        if (!$snapshot->valid) {
            $issue = $snapshot->projection()->issues[0];
            if ($issue->code === 'CDS_READ_PROFILE_UNSUPPORTED') {
                throw new WriteFailure('CAPABILITY_UNAVAILABLE');
            }
            $family = $issue->family;
            throw new WriteFailure(
                $family === 'physical-incompatible' ? 'INCOMPATIBLE_STORAGE' : 'INVALID_DEFINITION',
                $family === 'physical-incompatible' ? $family : 'definition-invalid'
            );
        }
        $projection = ListGetHost::find($snapshot->projection()->projections, $registration['projection']);
        WriteFailure::need(in_array($op, $projection->operations, true), 'UNAVAILABLE');
        $model = ListGetHost::find($snapshot->projection()->models, $projection->entity);
        $fields = [];
        foreach ($model->fields as $field) {
            $fields[$field->name] = $field;
        }
        $visible = array_column($projection->fields, 'name');
        WriteFailure::need(!array_diff($visible, $registration['fields'])
            && !array_diff($registration['fields'], $visible), 'INVALID_DEFINITION', 'definition-invalid');
        $generation = $this->catalog->generation();
        $epoch = $this->epoch;
        $current = function () use ($target, $op, $context, $snapshot, $generation, $epoch, $access): void {
            WriteFailure::need($this->epoch === $epoch && $this->catalog->current() === $snapshot
                && $this->catalog->generation() === $generation, 'GENERATION_EXPIRED');
            WriteFailure::need($this->access($target, $op, $context) == $access, 'UNAVAILABLE');
        };
        $key = [];
        $publicKey = null;
        $inputs = self::shape(
            $request->inputs,
            $op === 'CREATE' ? ['values']
            : ($op === 'UPDATE' ? ['key','changes','baseline','confirmation'] : ['key','precondition']),
            $op === 'CREATE' ? ['values'] : ['key']
        );
        if ($op !== 'CREATE') {
            $k = self::shape($inputs->key, ['components'], ['components']);
            WriteFailure::need(is_array($k->components)
                && count($k->components) === count($model->physical->primaryKey), 'INVALID_KEY', 'invalid-data');
            $components = [];
            foreach ($model->physical->primaryKey as $i => $name) {
                WriteFailure::need(
                    in_array($name, $visible, true) && in_array($name, $access['fields'], true),
                    'CAPABILITY_UNAVAILABLE'
                );
                $component = self::shape($k->components[$i], ['field','type','value'], ['field','type','value']);
                WriteFailure::need(
                    $component->field === $name && $component->type === $fields[$name]->type,
                    'INVALID_KEY',
                    'invalid-data'
                );
                $decoded = ListGetHost::scalar($fields[$name]->type, $component->value, $fields[$name]->constraints);
                WriteFailure::need($decoded['valid'], 'INVALID_KEY', 'invalid-data');
                $key[] = $decoded['normalizedValue'];
                $components[] = ['field' => $name,'type' => $fields[$name]->type,'value' => end($key)];
            }
            $publicKey = ['components' => $components];
        }
        $binding = serialize([$target,$registration['projection'],$publicKey,$context,$generation,$epoch,$access]);
        $readAccess = null;
        if ($purpose !== 'invoke' || $op === 'DELETE' || isset($inputs->confirmation)) {
            WriteFailure::need(in_array('GET', $projection->operations, true)
                && in_array('GET', $registration['operations'], true), 'UNAVAILABLE');
            $readAccess = $this->access($target, 'GET', $context);
        }
        if ($readAccess !== null) {
            $writeCurrent = $current;
            $current = function () use ($writeCurrent, $target, $context, $readAccess): void {
                $writeCurrent();
                WriteFailure::need($this->access($target, 'GET', $context) == $readAccess, 'UNAVAILABLE');
            };
        }
        $receipt = null;
        if ($op === 'DELETE' && $purpose === 'invoke') {
            WriteFailure::need(
                isset($inputs->precondition) && $inputs->precondition instanceof \stdClass,
                'PRECONDITION_REQUIRED',
                'invalid-data'
            );
            $receipt = $this->open($inputs->precondition, 'DELETE', $binding);
            WriteFailure::need($readAccess == $receipt['readAccess'], 'UNAVAILABLE');
        }
        $confirmBinding = '';
        if ($op === 'UPDATE') {
            self::shape($inputs, ['key','changes','baseline','confirmation'], ['key','changes','baseline']);
            WriteFailure::need(
                $inputs->changes instanceof \stdClass && $inputs->baseline instanceof \stdClass,
                'INPUT_SHAPE',
                'invalid-data'
            );
            $confirmBinding = $binding . serialize([$inputs->changes,$inputs->baseline]);
            if (isset($inputs->confirmation)) {
                WriteFailure::need(is_string($inputs->confirmation), 'INVALID_CONFIRMATION', 'invalid-data');
                $receipt = $this->open((object)['reference' => $inputs->confirmation], 'UPDATE', $confirmBinding);
                WriteFailure::need($receipt['readAccess'] == $readAccess, 'UNAVAILABLE');
            }
        }
        if ($purpose === 'confirm-update') {
            WriteFailure::need($challenge !== null, 'INVALID_CONFIRMATION', 'invalid-data');
            $receipt = $this->open($challenge, 'UPDATE_PENDING', $confirmBinding);
            WriteFailure::need($receipt['readAccess'] == $readAccess, 'UNAVAILABLE');
            $current();
            $this->evidence[$challenge->reference]['purpose'] = 'UPDATE';
            return ['status' => 'success','diagnostics' => [],
                'output' => ['confirmation' => $challenge->reference]];
        }
        $conflictAccess = null;
        try {
            $result = $this->writer->transaction(
                $snapshot,
                $registration['projection'],
                $access['equalities'],
                function (WriteTransaction $tx) use (
                    $op,
                    $inputs,
                    $fields,
                    $visible,
                    $access,
                    $key,
                    $model,
                    $publicKey,
                    $receipt,
                    $purpose,
                    $readAccess,
                    $context,
                    $target,
                    $current,
                    &$conflictAccess
                ): array {
                    $current();
                    if ($op === 'CREATE') {
                        WriteFailure::need($inputs->values instanceof \stdClass, 'INPUT_SHAPE', 'invalid-data');
                        $candidate = [];
                        foreach ($inputs->values as $name => $value) {
                            WriteFailure::need(in_array($name, $visible, true)
                                && in_array($name, $access['writableFields'], true), 'UNAVAILABLE');
                        }
                        foreach ($fields as $name => $field) {
                            $provided = property_exists($inputs->values, $name);
                            WriteFailure::need(
                                $provided || (($field->physical->nullable ?? false) === true
                                && !in_array($name, $model->physical->primaryKey, true)),
                                'REQUIRED_VALUE',
                                'invalid-data'
                            );
                            $candidate[$name] = self::normalize(
                                $field,
                                $provided ? $inputs->values->$name : null
                            );
                        }
                        self::scope($candidate, $access['equalities']);
                        $this->rule($op, $candidate, $context, array_keys($candidate));
                        $current();
                        $tx->insert($candidate);
                        $components = [];
                        foreach ($model->physical->primaryKey as $name) {
                            WriteFailure::need(in_array($name, $access['fields'], true), 'UNAVAILABLE');
                            $components[] = ['field' => $name,'type' => $fields[$name]->type,
                                'value' => $candidate[$name]];
                        }
                        return ['created' => true,'key' => ['components' => $components]];
                    }
                    $row = $tx->read($key);
                    if ($purpose !== 'invoke') {
                        WriteFailure::need(!array_diff($visible, $readAccess['fields']), 'UNAVAILABLE');
                        if ($row !== null) {
                            self::scope($row, $readAccess['equalities']);
                        }
                        return ['row' => $row];
                    }
                    if ($op === 'DELETE') {
                        if ($row === null || !self::inScope($row, $readAccess['equalities'])) {
                            return ['deleted' => false];
                        }
                        if ($row !== $receipt['state']) {
                            throw new WriteFailure('CONCURRENT_CHANGE', 'invalid-data', 'conflict');
                        }
                        $this->rule($op, $row, $context, []);
                        $current();
                        $tx->delete($key);
                        return ['deleted' => true];
                    }
                    WriteFailure::need($row !== null, 'UNAVAILABLE');
                    $candidate = $row;
                    $changes = [];
                    $conflicts = [];
                    foreach ($inputs->changes as $name => $proposed) {
                        WriteFailure::need(in_array($name, $visible, true)
                            && in_array($name, $access['writableFields'], true)
                            && !in_array($name, $model->physical->primaryKey, true), 'UNAVAILABLE');
                        WriteFailure::need(
                            property_exists($inputs->baseline, $name),
                            'PRECONDITION_REQUIRED',
                            'invalid-data'
                        );
                        $candidate[$name] = self::normalize($fields[$name], $proposed);
                        $original = self::normalize($fields[$name], $inputs->baseline->$name, true);
                        $expected = $receipt !== null && array_key_exists($name, $receipt['state'])
                            ? $receipt['state'][$name] : $original;
                        if ($row[$name] !== $expected) {
                            $read = $this->access($target, 'GET', $context);
                            self::scope($row, $read['equalities']);
                            $conflictAccess = $read;
                            WriteFailure::need(in_array($name, $read['fields'], true), 'UNAVAILABLE');
                            $conflicts[] = ['field' => $name,'original' => $original,'actual' => $row[$name],
                                'proposed' => $candidate[$name]];
                        }
                        if ($candidate[$name] !== $row[$name]) {
                            $changes[$name] = $candidate[$name];
                        }
                    }
                    if ($conflicts) {
                        usort($conflicts, static fn($a, $b) => strcmp($a['field'], $b['field']));
                        throw new WriteFailure(
                            'CONCURRENT_CHANGE',
                            'invalid-data',
                            'conflict',
                            ['conflicts' => $conflicts]
                        );
                    }
                    self::scope($candidate, $access['equalities']);
                    $this->rule($op, $candidate, $context, array_keys($changes));
                    $current();
                    $tx->update($key, $changes);
                    return ['changed' => (bool)$changes];
                },
                $current
            );
        } catch (WriteFailure $error) {
            if ($error->status === 'conflict') {
                $current();
                $read = $this->access($target, 'GET', $context);
                WriteFailure::need($conflictAccess === null || $conflictAccess == $read, 'UNAVAILABLE');
                foreach ($error->details['conflicts'] ?? [] as $conflict) {
                    WriteFailure::need(in_array($conflict['field'], $read['fields'], true), 'UNAVAILABLE');
                }
            }
            throw $error;
        }
        if ($purpose !== 'invoke') {
            WriteFailure::need($this->access($target, 'GET', $context) == $readAccess, 'UNAVAILABLE');
            if ($result['row'] === null) {
                return ['status' => 'success','diagnostics' => [],'output' => ['found' => false]];
            }
            [$handle,$reference] = $this->receipt(
                $op === 'DELETE' ? 'DELETE' : 'UPDATE_PENDING',
                $op === 'DELETE' ? $binding : $confirmBinding
            );
            $this->evidence[$handle]['state'] = $result['row'];
            $this->evidence[$handle]['readAccess'] = $readAccess;
            $bytes = 0;
            foreach ($this->evidence as $item) {
                $bytes += strlen(serialize($item));
            }
            while ($bytes > 8388608 && count($this->evidence) > 1) {
                $bytes -= strlen(serialize(array_shift($this->evidence)));
            }
            $values = array_intersect_key($result['row'], array_flip($visible));
            return ['status' => 'success','diagnostics' => [],'output' => $op === 'DELETE'
                ? ['record' => ['key' => $publicKey,'values' => (object)$values],'precondition' => $reference]
                : ['record' => ['key' => $publicKey,'values' => (object)$values],'challenge' => $reference]];
        }
        return ['status' => 'success','diagnostics' => [],'output' => $result];
    }
}
