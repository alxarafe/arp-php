<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use Alxarafe\Arp\Dictionary\{CatalogBuilder,Source};
use Alxarafe\Arp\Dictionary\Internal\{Pattern,Number,Failure};
$count=0;
function test(string $name,callable $run): void {global $count; $run(); ++$count; echo "PASS $name\n";}
function expect(bool $ok): void {if(!$ok) throw new RuntimeException('Assertion failed');}
function source(array $elements): Source {return new Source('p','s','json',json_encode(['apiVersion'=>'arp.dictionary/v1','elements'=>$elements],JSON_THROW_ON_ERROR));}
function reject(string $bytes,string $code='DICTIONARY_DOCUMENT_INVALID',string $encoding='yaml'): void {
    $r=(new CatalogBuilder())->buildCatalog([new Source('p','s',$encoding,$bytes)]);
    expect(!$r->isValid() && $r->catalog===null && $r->issues[0]['code']===$code);
}
$builder=new CatalogBuilder();
$definition=['id'=>'test.x','type'=>'ENUM','enumValues'=>['a','b'],'constraints'=>(object)['minimumLength'=>1]];
$src=source([$definition]); $built=$builder->buildCatalog([$src]); $catalog=$built->catalog;
test('detached descriptor and nested objects',function() use($catalog) {
    $d=$catalog->resolve('test.x')['descriptor']; $d->constraints->minimumLength=99; $d->enumValues[0]='z'; $d->type='BOOLEAN';
    expect($catalog->validate('test.x','a')['valid']); expect($catalog->resolve('test.x')['descriptor']->type==='ENUM');
});
test('readonly source and detached provenance',function() use($src,$catalog) {
    try {$src->content=''; throw new RuntimeException('Mutable source');} catch(Error) {}
    $p=$catalog->provenance('test.x'); $p['providerId']='changed'; expect($catalog->provenance('test.x')['providerId']==='p');
});
test('atomic failed rebuild preserves original snapshot',function() use($builder,$src,$catalog) {
    $failed=$builder->buildCatalog([$src,source([['id'=>'test.new','type'=>'STRING']]),$src]);
    expect(!$failed->isValid() && $failed->catalog===null);
    expect(!$catalog->resolve('test.new')['valid'] && $catalog->validate('test.x','a')['valid']);
});
test('independent contexts with identical identity',function() use($builder,$catalog) {
    $other=$builder->buildCatalog([source([['id'=>'test.x','type'=>'BOOLEAN']])])->catalog;
    expect($other->validate('test.x',true)['valid'] && !$catalog->validate('test.x',true)['valid']);
});
test('ASCII enumeration and empty catalog',function() use($builder) {
    $r=$builder->buildCatalog([source([['id'=>'test.z','type'=>'STRING'],['id'=>'test.A','type'=>'STRING'],['id'=>'test.a','type'=>'STRING']])]);
    expect($r->catalog->ids()===['test.A','test.a','test.z']); expect($builder->buildCatalog([])->catalog->ids()===[]);
});
test('1 MiB source accepted and overflow rejected',function() use($builder) {
    $base=['id'=>'test.x','type'=>'STRING','label'=>'x']; $n=1048576-strlen(source([$base])->content)+1; $base['label']=str_repeat('x',$n);
    $src=source([$base]); expect(strlen($src->content)===1048576 && $builder->buildCatalog([$src])->isValid());
    reject($src->content.' ','DICTIONARY_LIMIT_EXCEEDED','json');
});
test('2048 elements accepted and overflow rejected',function() use($builder) {
    $elements=[]; for($i=0;$i<2048;++$i) $elements[]=['id'=>'test.x'.$i,'type'=>'STRING'];
    expect($builder->buildCatalog([source($elements)])->isValid()); $elements[]=['id'=>'test.extra','type'=>'STRING'];
    expect($builder->buildCatalog([source($elements)])->issues[0]['code']==='DICTIONARY_LIMIT_EXCEEDED');
});
test('nested and escaped JSON duplicate keys',function() {
    reject('{"apiVersion":"arp.dictionary/v1","elements":[{"id":"a.b","type":"STRING","constraints":{"pattern":"a","patte\\u0072n":"b"}}]}','DICTIONARY_DOCUMENT_INVALID','json');
});
test('YAML duplicate keys including previous null',function() {
    foreach(["a: 1\na: 2\n","a: null\na: 2\n",'{a: null, a: 2}',"a:\n  b: null\n  b: 1\n"] as $bytes) reject($bytes);
});
test('YAML JSON model equivalence with constraints',function() use($builder) {
    $yaml="apiVersion: arp.dictionary/v1\nelements:\n  - id: test.x\n    type: DECIMAL\n    constraints: {minimum: '0.00', multipleOf: '0.1', precision: 5, scale: 2, exclusiveMinimum: false}\n";
    $c=$builder->buildCatalog([new Source('p','s','yaml',$yaml)])->catalog;
    expect($c!==null && $c->validate('test.x','0.3000')['normalizedValue']==='0.3');
    expect($c->resolve('test.x')['descriptor']->constraints->minimum==='0.00');
});
test('YAML literal forbidden symbols stay literal',function() use($builder) {
    $yaml="apiVersion: arp.dictionary/v1\nelements:\n  - id: test.x\n    type: STRING\n    label: '!*& <<: % ---'\n    description: \"!php/object a\"\n";
    expect($builder->buildCatalog([new Source('p','s','yaml',$yaml)])->isValid());
});
test('YAML forbidden constructs rejected',function() {
    foreach(["a: &a b\n",'a: !php/object x','a: !!str x',"a: 1\n---\nb: 2",'%YAML 1.2', 'a: {<<: {b: 1}}'] as $bytes) reject($bytes);
});
test('YAML 1.1 boolean words are strings',function() use($builder) {
    $r=$builder->buildCatalog([new Source('p','s','yaml',"apiVersion: arp.dictionary/v1\nelements: [{id: test.x, type: ENUM, enumValues: [yes, no, on, off]}]")]);
    expect($r->isValid() && $r->catalog->validate('test.x','yes')['valid']);
});
test('YAML UTF-8 and surrogate rejection',function() {reject("a: \xff"); reject('a: "\\uD800"');});
test('all ASCII controls rejected in portable patterns',function() {
    foreach(array_merge(range(0,31),[127]) as $n) foreach([chr($n),'['.chr($n).']','\\'.chr($n)] as $p) {
        try {new Pattern($p); throw new RuntimeException('Control accepted');} catch(Failure $e) {expect($e->errorCode==='DICTIONARY_ELEMENT_INVALID');}
    }
});
test('ambiguous repetitions bounded at input ceiling',function() {
    expect((new Pattern(str_repeat('a*',100)))->matches(str_repeat('a',4096)));
    expect(!(new Pattern('a*'))->matches("a\n"));
});
test('large exact decimal remainder and signed equality',function() {
    $digits=str_repeat('9',4096); expect(Number::multiple($digits,'9'));
    expect(!Number::multiple($digits,'10')); expect(Number::compare('-0.00','0')===0);
    expect(Number::compare('9223372036854775808','9223372036854775807')===1);
});
test('large integer metadata and exact descriptor export',function() use($builder) {
    $large='922337203685477580812345';
    foreach(['json','yaml'] as $encoding) {
        $bytes=$encoding==='json' ? '{"apiVersion":"arp.dictionary/v1","elements":[{"id":"test.large","type":"STRING","constraints":{"maximumLength":'.$large.'}}]}' : "apiVersion: arp.dictionary/v1\nelements: [{id: test.large, type: STRING, constraints: {maximumLength: ".$large."}}]";
        $r=$builder->buildCatalog([new Source('p','s',$encoding,$bytes)]);
        expect($r->isValid() && $r->catalog->validate('test.large','a')['valid']);
        expect(str_contains($r->catalog->descriptorJson('test.large'),'"maximumLength":'.$large));
        expect((string)$r->catalog->resolve('test.large')['descriptor']->constraints->maximumLength===$large);
    }
});
test('integral JSON number metadata without float rounding',function() use($builder) {
    foreach(['1.00','1e0','0.1e1'] as $number) {
        $bytes='{"apiVersion":"arp.dictionary/v1","elements":[{"id":"test.x","type":"STRING","constraints":{"minimumLength":'.$number.'}}]}';
        $r=$builder->buildCatalog([new Source('p','s','json',$bytes)]);
        expect($r->isValid() && !$r->catalog->validate('test.x','')['valid'] && $r->catalog->validate('test.x','a')['valid']);
    }
});
test('value validation without filesystem or network wrappers',function() use($catalog) {
    $wrappers=stream_get_wrappers(); foreach($wrappers as $w) stream_wrapper_unregister($w);
    try {expect($catalog->validate('test.x','a')['valid']); expect(!$catalog->validate('test.x','other')['valid']);}
    finally {foreach($wrappers as $w) stream_wrapper_restore($w);}
});

test('portable numeric source boundaries and exact fractions',function() use($builder) {
    foreach(['json','yaml'] as $encoding) foreach(['0e129','0e-129','0e0000000129','1e128','-1e128','1.'.str_repeat('0',255)] as $number) {
        $bytes=$encoding==='json'?'{"n":'.$number.'}':'n: '.$number;
        $r=$builder->buildCatalog([new Source('p','s',$encoding,$bytes)]);
        expect(!$r->isValid() && $r->issues[0]['code']==='DICTIONARY_LIMIT_EXCEEDED');
    }
    foreach(['0e128','0e-128','0e0000000128','9.9e127','1e-128'] as $number) {
        $value=\Alxarafe\Arp\Dictionary\Internal\Parser::parse('{"n":'.$number.'}','json')->n;
        expect(!is_float($value));
    }
});
test('portable YAML strings and block chomping',function() {
    $parser=\Alxarafe\Arp\Dictionary\Internal\Parser::class;
    foreach(['012','+1','2026-11-14','True','~','0xFF','1_000'] as $text) expect($parser::parse('a: '.$text,'yaml')->a===$text);
    foreach(["|-"=>"first", "|"=>"first\n", "|+"=>"first\n\n", ">-"=>"first second"] as $header=>$expected) {
        $body=$header==='>-'?"  first\n  second\n":"  first\n\n";
        expect($parser::parse("a: ".$header."\n".$body,'yaml')->a===$expected);
    }
    foreach(['a: "\\x41"','a: "\\U0001F600"',"a: \"first\n second\"", "a: first\n  second", "a: |2\n  x"] as $bytes) reject($bytes);
});
test('interleaved independent catalog construction and validation',function() {
    $fibers=[];
    foreach(range(1,8) as $n) $fibers[]=new Fiber(function() use($n) {
        $r=(new CatalogBuilder())->buildCatalog([new Source('p','s','json',json_encode(['apiVersion'=>'arp.dictionary/v1','elements'=>[['id'=>'test.fiber','type'=>'ENUM','enumValues'=>[(string)$n]]]]))]);
        Fiber::suspend();
        expect($r->catalog->validate('test.fiber',(string)$n)['valid']);
        expect(!$r->catalog->validate('test.fiber',(string)($n+1))['valid']);
    });
    foreach($fibers as $fiber) $fiber->start();
    foreach(array_reverse($fibers) as $fiber) $fiber->resume();
});
test('YAML string keys block plain punctuation and preparse depth',function() {
    $parser=\Alxarafe\Arp\Dictionary\Internal\Parser::class;
    foreach(['true','false','null','1','1.00'] as $key) reject($key.': text');
    reject("description: contains [brackets]\n  continuation");
    expect($parser::parse("elements:\n-    id: x\n     type: STRING",'yaml')->elements[0]->id==='x');
    expect($parser::parse('description: contains [brackets], commas {braces} ! & *','yaml')->description==='contains [brackets], commas {braces} ! & *');
    foreach([127,128] as $depth) {
        $yaml='';for($i=0;$i<$depth;$i++) $yaml.=str_repeat(' ',$i).'a:'."\n";
        $yaml.=str_repeat(' ',$depth).'leaf: value';
        if($depth===127) expect(is_object($parser::parse($yaml,'yaml')));
        else {try {$parser::parse($yaml,'yaml');throw new RuntimeException('Depth accepted');} catch(Failure $e) {expect($e->errorCode==='DICTIONARY_LIMIT_EXCEEDED');}}
    }
});
echo "$count runtime tests passed, 0 failed\n";
