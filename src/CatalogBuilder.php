<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Dictionary;
use Alxarafe\Arp\Dictionary\Internal\{Parser,Definition,Failure};
final readonly class CatalogBuilder
{
    public function __construct(public int $maxDocumentBytes=1048576,public int $maxElementsPerSource=2048)
    {
        if($maxDocumentBytes<1 || $maxElementsPerSource<1) throw new \InvalidArgumentException('Positive limits required');
    }
    /** @param iterable<Source> $activeSources */
    public function buildCatalog(iterable $activeSources): BuildResult
    {
        $elements=[]; $provenance=[];
        foreach($activeSources as $source) {
            if(!$source instanceof Source) throw new \InvalidArgumentException('Expected Source snapshots');
            $path=''; $id=null;
            try {
                if(strlen($source->content)>$this->maxDocumentBytes) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
                $doc=Parser::parse($source->content,$source->encoding);
                if(!$doc instanceof \stdClass) throw new Failure('DICTIONARY_DOCUMENT_INVALID');
                if(($doc->apiVersion??null)!=='arp.dictionary/v1') throw new Failure('DICTIONARY_FORMAT_UNSUPPORTED');
                if(array_diff(array_keys((array)$doc),['apiVersion','elements']) || !isset($doc->elements) || !is_array($doc->elements) || !$doc->elements) throw new Failure('DICTIONARY_DOCUMENT_INVALID');
                if(count($doc->elements)>$this->maxElementsPerSource) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
                foreach($doc->elements as $index=>$node) {
                    $path='/elements/'.$index; $id=null;
                    if(!$node instanceof \stdClass) throw new Failure('DICTIONARY_DOCUMENT_INVALID');
                    $id=is_string($node->id??null)?$node->id:null;
                    $e=Definition::check($node); $id=$e['id'];
                    if(isset($elements[$id])) throw new Failure('DICTIONARY_DUPLICATE_ELEMENT');
                    $elements[$id]=$e; $provenance[$id]=['providerId'=>$source->providerId,'sourceId'=>$source->sourceId,'path'=>$path];
                }
            } catch(Failure $f) {
                $issue=['code'=>$f->errorCode,'providerId'=>$source->providerId,'sourceId'=>$source->sourceId,'path'=>$path];
                if($id!==null) $issue['elementId']=$id;
                return new BuildResult(null,[$issue]);
            }
        }
        return new BuildResult(Catalog::fromValidated($elements,$provenance),[]);
    }
}
