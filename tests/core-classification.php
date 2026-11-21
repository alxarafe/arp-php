<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/Contract.php';
require __DIR__ . '/Support/DeterministicProvider.php';
require __DIR__ . '/Support/CoreSuite.php';

use Arp\Tests\{Contract,CoreSuite,DeterministicProvider};
use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Cds\{CrudHost,ReadResolver};
use Alxarafe\Arp\Dictionary\Internal\Projection;

$contract = new Contract();
$suite = new CoreSuite($contract);
$dictionary = $contract->load('examples/demo/cds/dictionary.yaml');
$bundle = (object)['physical' => (object)['demo' => $contract->load('fixtures/cds/v1/observations/mariadb.yaml')],
    'logical' => (object)['demo' => $contract->load('examples/demo/cds/logical.yaml')],
    'projections' => (object)['demo' => $contract->load('examples/demo/cds/public.yaml')]];
$catalog = $suite->catalog($bundle, $dictionary, true);
$store = new DeterministicProvider();
$store->rows = [['id' => '7','code' => 'A-007','rating' => '5']];
$fields = ['id','code','rating'];
$core = new CrudHost($catalog, $store, $store, ['demo.items' => [
    'projection' => 'demo.items.public','fields' => $fields,'operations' => ['GET','DELETE','CREATE'],
    'defaultLimit' => 10,'maxLimit' => 100
]], static fn() => ['allowed' => true,'fields' => $fields,'writableFields' => $fields,
    'equalities' => new stdClass()]);
$context = ['identity' => 'classification-test','policyRevision' => 'p1'];
$key = (object)['components' => [(object)['field' => 'id','type' => 'INTEGER','value' => '7']]];
$proof = $core->readForDelete('demo.items', $key, $context)['output']['precondition'];
$checks = 0;
$assertDelete = static function (mixed $inputs, string $code) use ($core, $context, $store, &$checks): void {
    $before = $store->rows;
    $store->events = [];
    $response = $core->invoke(Projection::encode((object)[
        'target' => 'demo.items','operation' => 'DELETE','inputs' => $inputs
    ]), $context);
    Contract::same($response, ['status' => 'rejected','diagnostics' => [
        ['family' => 'invalid-data','code' => $code]
    ]], 'DELETE input classification');
    Contract::same($store->rows, $before, 'invalid DELETE preserves rows');
    Contract::same($store->events, [], 'invalid DELETE never reaches transaction');
    ++$checks;
};
foreach ([null,7,'untrusted',[],['arbitrary' => true],(object)['code' => 'A-007','rating' => '5']] as $baseline) {
    $assertDelete((object)['key' => $key,'baseline' => $baseline], 'PRECONDITION_REQUIRED');
}
$assertDelete((object)['key' => $key,'baseline' => new stdClass(),'precondition' => null], 'PRECONDITION_REQUIRED');
$assertDelete(null, 'INPUT_SHAPE');
$assertDelete((object)['baseline' => new stdClass()], 'INPUT_SHAPE');
$assertDelete((object)['key' => 7,'baseline' => new stdClass()], 'INPUT_SHAPE');
$assertDelete((object)['key' => $key,'baseline' => new stdClass(),'extra' => true], 'INPUT_SHAPE');
$assertDelete((object)['key' => $key,'baseline' => new stdClass(),'precondition' => $proof], 'INPUT_SHAPE');
$assertDelete((object)['key' => $key,'baseline' => new stdClass(),'precondition' => 'malformed'], 'INPUT_SHAPE');
$badKey = Contract::copy($key);
$badKey->components[0]->value = 7;
$assertDelete((object)['key' => $badKey,'baseline' => new stdClass()], 'INVALID_KEY');

$assertRange = static function (
    array $physical,
    array $logical,
    ?array $intrinsic,
    bool $writes,
    ?string $code,
    ?string $family
) use (
    $contract,
    $bundle,
    $dictionary,
    &$checks
): void {
    $input = Contract::copy($bundle);
    $input->physical->demo->columns[2]->integerRange = (object)$physical;
    $input->logical->demo->fields->rating->constraints = (object)$logical;
    $elements = Contract::copy($dictionary);
    if ($intrinsic !== null) {
        $elements->elements[] = (object)['id' => 'test.rating','type' => 'INTEGER','constraints' => (object)$intrinsic];
        $input->logical->demo->fields->rating->dictionary = 'test.rating';
    }
    $built = (new CatalogBuilder())->buildCatalog([new Source(
        'tests',
        'dictionary',
        'json',
        Projection::encode($elements)
    )]);
    $result = (new ReadResolver($writes))->resolve($input, $built->catalog)->projection();
    Contract::same($result->valid, $code === null, 'range validity');
    if ($code !== null) {
        Contract::same($result->issues[0]->code, 'CDS_READ_' . $code, 'range error code');
        Contract::same($result->issues[0]->family, $family, 'range error family');
    }
    ++$checks;
};
$small = ['minimum' => '0','maximum' => '4'];
foreach (
    [['minimum' => '0','maximum' => '10'],['minimum' => '-1','maximum' => '4'],
    ['minimum' => '5'],['maximum' => '-1']] as $logical
) {
    $assertRange($small, $logical, null, true, 'DOMAIN_NOT_REPRESENTABLE', 'physical-incompatible');
}
$assertRange(
    $small,
    ['minimum' => '10','maximum' => '1'],
    null,
    true,
    'CONSTRAINT_CONTRADICTION',
    'definition-invalid'
);
$assertRange($small, ['minimum' => 'x','maximum' => '10'], null, true, 'SCHEMA_INVALID', 'definition-invalid');
foreach (
    [['minimum' => '0','maximum' => '18446744073709551616'],
    ['minimum' => '-9223372036854775809'],
    ['minimum' => '-1','maximum' => '18446744073709551615']] as $impossible
) {
    $assertRange($small, $impossible, null, true, 'CONSTRAINT_CONTRADICTION', 'definition-invalid');
}

$assertRange(['minimum' => '4','maximum' => '0'], $small, null, true, 'PHYSICAL_INVALID', 'definition-invalid');
$assertRange(
    ['minimum' => '0','maximum' => '20'],
    ['minimum' => '0','maximum' => '10'],
    ['bits' => 16,'signed' => true,'minimum' => '0','maximum' => '4'],
    true,
    'CONSTRAINT_WEAKENING',
    'definition-invalid'
);
$assertRange(
    $small,
    ['minimum' => '0','maximum' => '10'],
    ['bits' => 16,'signed' => true,'minimum' => '0','maximum' => '10'],
    true,
    'DOMAIN_NOT_REPRESENTABLE',
    'physical-incompatible'
);
$assertRange($small, ['minimum' => '0','maximum' => '3'], null, true, null, null);
$assertRange(
    ['minimum' => '0','maximum' => '10'],
    ['minimum' => '-1'],
    null,
    false,
    'CONSTRAINT_WEAKENING',
    'definition-invalid'
);

$vectors = $contract->load('fixtures/crud/write/v1/cases.json');
foreach ($vectors->cases as $case) {
    if ($case->id === 'create-physical-range-incompatible') {
        $input = Contract::copy($case);
        // The candidate fits the observed storage but the declared logical domain still does not fit.
        $input->request->inputs->values->rating = '3';
        $result = $suite->write($input, $vectors->defaults);
        Contract::same($result['response'], $case->expected->response, 'intersection never enables incompatible write');
        Contract::same($result['writes'], 0, 'incompatible domain zero writes');
        Contract::same($result['events'], [], 'incompatible domain rejected before transaction');
        ++$checks;
    }
}
echo "PASS $checks targeted core classification regressions\n";
