<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Dictionary\Internal;
use Symfony\Component\Yaml\Yaml;
use Alxarafe\Arp\Dictionary\ExactInteger;
use Symfony\Component\Yaml\Exception\ParseException;
final class Parser
{
    public static function parse(string $bytes,string $encoding): mixed
    {
        if(preg_match('//u',$bytes)!==1) self::invalid();
        try {
            if($encoding==='json') {
                // Linear lexical preflight avoids regex stack limits on 1 MiB strings.
                $depth=0; $syntax='';
                for($p=0,$length=strlen($bytes);$p<$length;) {
                    $c=$bytes[$p];
                    if($c==='"') {
                        $start=$p++;
                        while($p<$length && $bytes[$p]!=='"') {if($bytes[$p]==='\\') ++$p; ++$p;}
                        if($p>=$length) self::invalid();
                        ++$p; $syntax.=substr($bytes,$start,$p-$start); continue;
                    }
                    if($c==='-' || ($c>='0' && $c<='9')) {
                        $start=$p; $p+=strcspn($bytes," \t\r\n,]}:",$p);
                        $raw=substr($bytes,$start,$p-$start);
                        if(strlen($raw)>256) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
                        self::jsonScalar($raw); $syntax.='"0"'; continue;
                    }
                    if($c==='[' || $c==='{') {if(++$depth>128) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');}
                    elseif($c===']' || $c==='}') --$depth;
                    $syntax.=$c; ++$p;
                }
                json_decode($syntax,false,512,JSON_THROW_ON_ERROR);
                // A linear second pass detects mapping keys before any can be overwritten.
                $i=0; $value=self::jsonNode($bytes,$i);
            } elseif($encoding==='yaml') {
                [$bytes,$integerMarkers]=self::yamlGuard($bytes);
                // Symfony 7 emits a deprecation (rather than throwing) for duplicates
                // whose previous value is null. Turn that specific intake defect into rejection.
                set_error_handler(static function(int $severity,string $message): bool {
                    if($severity===E_USER_DEPRECATED && str_contains($message,'Duplicate key')) self::invalid();
                    return false;
                },E_USER_DEPRECATED);
                try { $value=Yaml::parse($bytes,Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE|Yaml::PARSE_OBJECT_FOR_MAP); }
                finally { restore_error_handler(); }
                $value=self::restoreIntegers($value,$integerMarkers);
            } else self::invalid();
        } catch(\JsonException|ParseException $e) { self::invalid(); }
        self::unicode($value);
        return $value;
    }
    private static function space(string $s,int &$i): void
    {
        $i+=strspn($s," \t\r\n",$i);
    }
    private static function jsonString(string $s,int &$i): string
    {
        $start=$i++;
        while($s[$i]!=='"') {if($s[$i]==='\\') ++$i; ++$i;}
        ++$i; return substr($s,$start,$i-$start);
    }
    private static function jsonNode(string $s,int &$i): mixed
    {
        self::space($s,$i); $token=$s[$i++];
        if($token==='{') {
            $seen=[]; $object=new \stdClass(); self::space($s,$i);
            while($s[$i]!=='}') {
                $key=json_decode(self::jsonString($s,$i),true,512,JSON_THROW_ON_ERROR);
                if(isset($seen[$key])) self::invalid(); $seen[$key]=true;
                self::space($s,$i); ++$i; $object->$key=self::jsonNode($s,$i); self::space($s,$i);
                if($s[$i]===',') {++$i; self::space($s,$i);} else break;
            }
            ++$i; return $object;
        } elseif($token==='[') {
            $array=[]; self::space($s,$i);
            while($s[$i]!==']') {
                $array[]=self::jsonNode($s,$i); self::space($s,$i);
                if($s[$i]===',') {++$i; self::space($s,$i);} else break;
            }
            ++$i; return $array;
        } elseif($token==='"') {--$i; return json_decode(self::jsonString($s,$i),false,512,JSON_THROW_ON_ERROR);}
        else {
            $start=$i-1; $i+=strcspn($s," \t\r\n,]}",$i); $raw=substr($s,$start,$i-$start);
            return self::jsonScalar($raw);

        }
    }
    private static function yamlGuard(string $bytes): array
    {
        $bytes=str_replace("\r\n","\n",$bytes);
        if(str_contains($bytes,"\r") || preg_match('/[\x{0085}\x{2028}\x{2029}]/u',$bytes)) self::invalid();
        $markers=[]; $prefix='__arp_scalar_'; while(str_contains($bytes,$prefix)) $prefix.='_';
        $out=[]; $blockIndent=null; $plainPrevious=false; $flowDepth=0; $blocks=[];
        foreach(explode("\n",$bytes) as $line) {
            preg_match('/^ */',$line,$lead); $indent=strlen($lead[0]);
            if(($line[$indent]??'')==="\t") self::invalid();
            if($blockIndent!==null) {
                if(trim($line)==='' || $indent>$blockIndent) {$out[]=$line; continue;}
                $blockIndent=null;
            }
            $trim=trim($line);
            if($trim==='' || str_starts_with($trim,'#')) {$out[]=$line; continue;}
            if($trim==='---' || $trim==='...') {$out[]=$line; $plainPrevious=false; continue;}
            if($trim[0]==='%') self::invalid();
            if($plainPrevious && !preg_match('/^(?:-\s|[^:]+:(?:\s|$)|[\]}])/',$trim)) self::invalid();
            if($flowDepth===0) {
                $sequence=preg_match('/^-($|\s)/',$trim)===1;
                $mapping=preg_match('/^[^{}\[\],#]+:(?:\s|$)/',$trim)===1;
                if($sequence || $mapping) {
                    while($blocks && $blocks[array_key_last($blocks)]['indent']>$indent) array_pop($blocks);
                    $kind=$sequence?'sequence':'mapping';
                    if($blocks && $blocks[array_key_last($blocks)]['indent']===$indent && $blocks[array_key_last($blocks)]['kind']!==$kind) {
                        if(!$sequence) array_pop($blocks);
                    }
                    $top=$blocks? $blocks[array_key_last($blocks)]:null;
                    if($top===null || $top['indent']!==$indent || $top['kind']!==$kind) $blocks[]=['indent'=>$indent,'kind'=>$kind];
                    if($sequence && preg_match('/^-(\s+)[^{}\[\],#]+:(?:\s|$)/',$trim,$item)) $blocks[]=['indent'=>$indent+1+strlen($item[1]),'kind'=>'mapping'];
                    if(count($blocks)>128) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
                }
            }
            $result=''; $lastScalar=false;
            for($i=0,$n=strlen($line);$i<$n;) {
                $c=$line[$i];
                if($c==='[' || $c==='{') {if(++$flowDepth+count($blocks)>128) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');}
                elseif($c===']' || $c==='}') {--$flowDepth; $lastScalar=false;}
                if(ctype_space($c) || str_contains('[]{},',$c) || ($c==='-' && (($line[$i+1]??'')===' ')) || $c===':') {$result.=$c; ++$i; continue;}
                if($c==='#') {$result.=substr($line,$i); break;}
                if(str_contains('&*!%@`',$c) || ($c==='?' && (($line[$i+1]??' ')===' '))) self::invalid();
                if($c==='|' || $c==='>') {
                    if(!preg_match('/\A[|>][+-]?(?:\s+#.*)?\s*\z/',substr($line,$i))) self::invalid();
                    $result.=substr($line,$i); $blockIndent=$indent; $lastScalar=false; break;
                }
                $start=$i;
                if($c==='"' || $c==="'") {
                    $q=$c; ++$i;
                    while($i<$n) {
                        if($line[$i]===$q) {if($q==="'" && ($line[$i+1]??'')==="'") {$i+=2;continue;} ++$i; break;}
                        if($q==='"' && $line[$i]==='\\') ++$i;
                        ++$i;
                    }
                    if($i>$n || $i-$start<2 || ($line[$i-1]??'')!==$q) self::invalid();
                    $raw=substr($line,$start,$i-$start);
                    if($i<$n && !ctype_space($line[$i]) && !str_contains(':,]}#',$line[$i])) self::invalid();
                    $value=$q==='"'?json_decode($raw,false,512,JSON_THROW_ON_ERROR):str_replace("''","'",substr($raw,1,-1));
                } else {
                    while($i<$n && !($flowDepth>0 && str_contains('[]{},',$line[$i])) && !($line[$i]===':' && (($line[$i+1]??' ')===' ')) && !($line[$i]==='#' && ($i===$start || ctype_space($line[$i-1])))) ++$i;
                    $raw=rtrim(substr($line,$start,$i-$start));
                    if($raw==='<<' || $raw==='') self::invalid();
                    $value=match($raw) {'null'=>null,'true'=>true,'false'=>false,default=>preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/D',$raw)?self::jsonScalar($raw):$raw};
                }
                $j=$i; while(($line[$j]??'')===' ') ++$j;
                $key=($line[$j]??'')===':';
                if($key) {if(!is_string($value)) self::invalid(); $result.=json_encode($value,JSON_THROW_ON_ERROR); $lastScalar=false;}
                else {$marker=$prefix.count($markers);$markers[$marker]=$value;$result.="'".$marker."'";$lastScalar=true;}
                if($c!=='"' && $c!=="'") $result.=substr(substr($line,$start,$i-$start),strlen($raw));
            }
            $out[]=$result; $plainPrevious=$lastScalar && $flowDepth===0;
        }
        return [implode("\n",$out),$markers];
    }
    private static function jsonScalar(string $raw): mixed
    {
        if(!preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?\z/D',$raw,$m)) return json_decode($raw,false,512,JSON_THROW_ON_ERROR);
        $fraction=$m[3]??''; $exponent=$m[4]??'0';
        if(strlen($raw)>256 || strlen(ltrim($exponent,'+-0'))>3 || abs((int)$exponent)>128) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
        $scale=strlen($fraction)-(int)$exponent; $digits=$m[2].$fraction;
        $significant=ltrim($digits,'0');
        if($significant!=='' && strlen($significant)-$scale>128) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
        if($scale>0) {
            if($scale>strlen($digits)) {
                if(trim($digits,'0')!=='') return new ExactNumber($raw);
                $digits='0';
            } else {
                if(trim(substr($digits,-$scale),'0')!=='') return new ExactNumber($raw);
                $digits=substr($digits,0,strlen($digits)-$scale);
            }
        } elseif($scale<0) $digits.=str_repeat('0',-$scale);
        $digits=ltrim($digits,'0') ?: '0'; $digits=($m[1]==='-' && $digits!=='0'?'-':'').$digits;
        if(Number::compare($digits,(string)PHP_INT_MIN)<0 || Number::compare($digits,(string)PHP_INT_MAX)>0) return new ExactInteger($digits);
        return (int)$digits;
    }
    private static function restoreIntegers(mixed $value,array $markers): mixed
    {
        if(is_string($value) && array_key_exists($value,$markers)) return $markers[$value];
        if(is_array($value)) foreach($value as $k=>$v) $value[$k]=self::restoreIntegers($v,$markers);
        elseif($value instanceof \stdClass) foreach($value as $k=>$v) $value->$k=self::restoreIntegers($v,$markers);
        return $value;
    }
    private static function unicode(mixed $value,int $depth=0): void
    {
        if(is_array($value) || $value instanceof \stdClass) {++$depth; if($depth>128) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');}
        self::unicodeNode($value,$depth);
    }
    private static function unicodeNode(mixed $value,int $depth): void
    {
        if(is_string($value) && preg_match('//u',$value)!==1) self::invalid();
        if(is_float($value) && !is_finite($value)) self::invalid();
        if(is_object($value) && !$value instanceof \stdClass && !$value instanceof ExactInteger && !$value instanceof ExactNumber) self::invalid();
        if(is_array($value) || is_object($value)) foreach($value as $key=>$v) { self::unicode((string)$key); self::unicode($v,$depth); }
    }
    private static function invalid(): never { throw new Failure('DICTIONARY_DOCUMENT_INVALID'); }
}
