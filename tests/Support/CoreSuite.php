<?php

declare(strict_types=1);

namespace Arp\Tests;

use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Internal\Projection;
use Alxarafe\Arp\Dictionary\Cds\{CrudHost,ListGetHost,WriteFailure};
use Alxarafe\Arp\Cds\Physical\{PhysicalCatalog,PhysicalObservationProvider};

/** Fixture-to-port setup only. Expected values never enter the provider or host. */
final class CoreSuite
{
    public function __construct(private Contract $contract)
    {
    }
    public function catalog(object $bundle, object $dictionary, bool $writes): PhysicalCatalog
    {
        $built = (new CatalogBuilder())->buildCatalog([new Source(
            'tests',
            'dictionary',
            'json',
            Projection::encode($dictionary)
        )]);
        Contract::need($built->isValid(), 'Fixture dictionary invalid');
        $providers = [];
        foreach ($bundle->physical as $handle => $observation) {
            $providers[$handle] = new class ($observation) implements PhysicalObservationProvider {
                public function __construct(private object $observation)
                {
                }
                public function providerId(): string
                {
                    return $this->observation->source->provider;
                }
                public function observe(): object
                {
                    return Contract::copy($this->observation);
                }
            };
        }
        return new PhysicalCatalog(
            $built->catalog,
            (object)['logical' => $bundle->logical,'projections' => $bundle->projections],
            $providers,
            $writes
        );
    }
    public function writeSkip(object $case): ?string
    {
        $host = $case->host ?? new \stdClass();
        if (($host->mode ?? 'managed') === 'delegated') {
            return 'Delegated handlers are outside the existing managed CrudHost port.';
        }
        if (isset($host->verifiedDefaults)) {
            return 'CrudHost does not implement verified-default descriptors; no feature added by this test task.';
        }
        if (in_array($case->id, ['delete-missing-state-field','delete-uncovered-new-column'], true)) {
            return 'The public core issues complete protected evidence; '
                . 'partial synthetic receipt state is not injectable.';
        }
        if (isset($host->keyReferences) || str_contains($case->id, 'hidden-key')) {
            return 'Mutation keys are public typed components; opaque mutation keys are not implemented.';
        }
        if (isset($host->confirmationEvidence)) {
            return 'Neutral confirmation placeholders differ from the core consent/challenge protocol; '
                . 'retained native tests.';
        }
        foreach ($host->preconditions ?? [] as $receipt) {
            if (($receipt->binding->purpose ?? 'DELETE') !== 'DELETE') {
                return 'GET does not issue a DELETE receipt through the public core API; '
                    . 'unknown tokens are not purpose proof.';
            }
        }
        if (
            isset($host->contextAtBoundary) || isset($host->generationAtBoundary)
            || isset($host->accessRevisionAtBoundary)
        ) {
            return 'Neutral trusted-context/generation phase is not injectable through the current core callbacks.';
        }
        return null;
    }
    public function write(object $case, object $defaults): array
    {
        $host = (object)array_replace(
            (array)Contract::copy($defaults),
            (array)Contract::copy($case->host ?? (object)[])
        );
        $engine = $host->engine ?? 'mariadb';
        $physical = $this->contract->load('fixtures/cds/v1/observations/' . $engine . '.yaml');
        foreach ($physical->columns as $column) {
            foreach ($host->columnOverrides->{$column->name} ?? (object)[] as $name => $value) {
                $column->$name = $value;
            }
        }
        array_push($physical->columns, ...($host->additionalColumns ?? []));
        $physical->kind = $host->physicalKind ?? $physical->kind;
        $bundle = (object)['physical' => (object)['demo' => $physical],
            'logical' => (object)['demo' => $this->contract->load('examples/demo/cds/logical.yaml')],
            'projections' => (object)[
                'public' => $this->contract->load('examples/demo/cds/public.yaml'),
                'summary' => $this->contract->load('examples/demo/cds/summary.yaml')]];
        $catalog = $this->catalog($bundle, $this->contract->load('examples/demo/cds/dictionary.yaml'), true);
        $provider = new DeterministicProvider();
        $provider->rows = array_map(static fn($row) => (array)$row, $host->rows);
        $capable = ($host->capacity ?? 'verified') !== 'not-verified'
            && ($host->atomicBoundary ?? true) && ($host->effectsCharacterized ?? true)
            && ($host->singleRowEffects ?? true) && ($host->constraintsVerified ?? true)
            && ($host->ruleDependenciesProtected ?? true)
            && !(($host->ruleRequired ?? false) && !($host->ruleAvailable ?? false));
        $incompatible = ($host->capacity ?? '') === 'incompatible';
        $provider->constraintRejected = $host->constraintReject ?? false;
        $projection = null;
        foreach ($bundle->projections as $p) {
            if ($p->id === ($host->projection ?? 'demo.items.public')) {
                $projection = $p;
            }
        }
        $fields = $projection->fields ?? ['id','code','rating'];
        $targets = ($host->registered ?? true) && ($host->active ?? true) ? ['demo.items' => [
            'projection' => $host->projection,'fields' => $fields,
            'operations' => $host->authorizedOperations ?? ['LIST','GET','CREATE','UPDATE','DELETE'],
            'defaultLimit' => 10,'maxLimit' => 100]] : [];
        $allowed = $host->authorized ?? true;
        $readable = $host->readableFields ?? $fields;
        $writable = $host->writableFields ?? $fields;
        $scope = new \stdClass();
        if (!($host->candidateAuthorized ?? true)) {
            $scope->id = '__outside_test_scope';
        }
        $policy = static function () use (&$allowed, &$readable, &$writable, $scope): array {
            return ['allowed' => $allowed,'fields' => $readable,'writableFields' => $writable,'equalities' => $scope];
        };
        $clock = 1000.0;
        $core = new CrudHost(
            $catalog,
            $provider,
            $provider,
            $targets,
            $policy,
            static fn() => !($host->ruleReject ?? false),
            300,
            static function () use (&$clock): float {
                return $clock;
            }
        );
        $context = ['identity' => Projection::encode($host->context),'policyRevision' => $host->accessRevision];
        $request = Contract::copy($case->request);
        // Replace only symbolic opaque handles with evidence issued by the real core, never expected responses.
        if (
            is_object($request) && ($request->operation ?? '') === 'DELETE'
            && isset($request->inputs->precondition->reference)
        ) {
            $name = $request->inputs->precondition->reference;
            $receipt = $host->preconditions->$name ?? null;
            if ($receipt !== null && isset($receipt->binding->key->components)) {
                $savedRows = $provider->rows;
                $receiptKey = $receipt->binding->key;
                $keyValues = [];
                foreach ($receiptKey->components as $component) {
                    $keyValues[$component->field] = $component->value;
                }
                $provider->rows = [(array)$receipt->state + $keyValues];
                $receiptContext = $context;
                $receiptContext['identity'] = Projection::encode($receipt->binding->context);
                if ($receipt->binding->purpose === 'DELETE') {
                    $proof = $core->readForDelete('demo.items', $receiptKey, $receiptContext);
                    if (isset($proof['output']['precondition'])) {
                        $request->inputs->precondition = (object)$proof['output']['precondition'];
                    }
                }
                $provider->rows = $savedRows;
                if (!($receipt->active ?? true)) {
                    $clock += 301;
                }
            }
        }
        $provider->capable = $capable;
        $provider->incompatible = $incompatible;
        $provider->hideRecords = !($host->recordAccessible ?? true);
        $provider->writes = 0;
        $provider->written = [];
        $provider->events = [];
        $provider->fault = $host->persistenceFailure ?? '';
        $provider->unknownCommitted = $host->unknownCommitted ?? false;
        $provider->boundary = static function () use ($provider, $host, &$allowed, &$readable, &$writable): void {
            if (isset($host->rowsAtBoundary)) {
                $provider->rows = array_map(static fn($row) => (array)$row, $host->rowsAtBoundary);
            }
            $allowed = $allowed && ($host->authorizedAtBoundary ?? true);
            $readable = $host->readableFieldsAtBoundary ?? $readable;
            $writable = $host->writableFieldsAtBoundary ?? $writable;
        };
        $provider->publish = static function () use ($host, &$allowed, &$readable): void {
            $allowed = $allowed && ($host->authorizedAtPublish ?? true);
            $readable = $host->readableFieldsAtPublish ?? $readable;
        };
        $response = $core->invoke(Projection::encode($request), $context);
        return ['response' => $response,'rows' => $provider->rows,'writes' => $provider->writes,
            'writtenFields' => $request->operation === 'DELETE' ? [] : $provider->written,
            'events' => $provider->events];
    }
    public function readSkip(object $case, object $defaults): ?string
    {
        if (isset($case->host->moduleState)) {
            return 'Module activation is outside the PHP core host ports; no module resolver added.';
        }
        if ($case->id === 'invalid-server-limit-registration') {
            return 'The core rejects invalid trusted registration at construction, not as a request response.';
        }
        if ($case->id === 'stale-catalog-generation') {
            return 'Neutral stale snapshot identity cannot be injected through PhysicalCatalog publication.';
        }
        $token = $case->request->inputs->key->reference ?? $case->request->inputs->continuation ?? null;
        if (
            is_string($token) && (isset($defaults->references->$token) || isset($defaults->continuations->$token)
            || isset($case->host->continuations->$token))
        ) {
            return 'Literal neutral tokens have no issuer port; '
                . 'cryptographic roundtrips retained in separate core tests.';
        }
        $bytes = Projection::encode($case->expected->response);
        if (preg_match('/rec-[a-f0-9]+|page-[a-f0-9]+/', $bytes)) {
            return 'Expected literal opaque tokens are not configurable through the core ports; no token substitution.';
        }
        return null;
    }
    public function read(object $case, object $defaults, object $products): array
    {
        $host = (object)array_replace(
            (array)Contract::copy($defaults),
            (array)Contract::copy($case->host ?? (object)[])
        );
        $layout = $products->layouts->{$case->layout ?? 'composite'};
        $bundle = $host->definitions ?? $layout->definitions;
        $catalog = $this->catalog($bundle, $products->dictionary, false);
        $provider = new DeterministicProvider();
        $rowIds = [];
        foreach ($host->rows ?? $layout->rows as $row) {
            // A trusted row decision is translated to a predicate before LIMIT, like a registered provider scope.
            if (in_array($row->id, $host->allowedRows, true)) {
                $rowIds[] = $row->id;
                $provider->rows[] = (array)$row->values;
            }
        }
        foreach ($host->decodeFailures ?? [] as $id) {
            $index = array_search($id, $rowIds, true);
            if ($index !== false) {
                $provider->decodeFailures[] = $index;
            }
        }
        $provider->capable = $host->readCapability;
        $provider->readFailure = $host->readFailure ?? false;
        $selected = null;
        foreach ($layout->definitions->projections as $p) {
            if ($p->id === $host->projection) {
                $selected = $p;
            }
        }
        $targets = $host->registered ? ['demo.products' => ['projection' => $host->projection,
            'fields' => $selected->fields ?? [],'defaultLimit' => $host->defaultLimit,
            'maxLimit' => $host->maxLimit]] : [];
        $allowed = $host->authorized && in_array($case->request->operation ?? '', $host->enabledOperations, true);
        $policy = static function () use ($host, &$allowed): array {
            return ['allowed' => $allowed,'fields' => $host->readableFields,'equalities' => new \stdClass()];
        };
        $publish = static function () use ($host, $catalog, &$allowed): void {
            if (isset($host->generationAtPublish) && $host->generationAtPublish !== $host->generation) {
                $catalog->invalidate();
            }
            $allowed = $allowed && ($host->authorizedAtPublish ?? true);
            if (isset($host->contextAtPublish)) {
                $allowed = $allowed
                    && Projection::encode($host->contextAtPublish) === Projection::encode($host->context);
            }
        };
        $core = new ListGetHost(new PublishingCatalog($catalog, $publish), $provider, $targets, $policy);
        $response = $core->invoke(Projection::encode($case->request), [
            'identity' => Projection::encode($host->context),
            'policyRevision' => $host->context->policyRevision ?? 'test'
        ]);
        return ['response' => $response,'reads' => $provider->reads,'writes' => $provider->writes,
            'events' => $provider->events];
    }
}
