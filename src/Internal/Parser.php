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
                json_decode($bytes,false,512,JSON_THROW_ON_ERROR);
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
        // Restricted subset: block/flow collections and scalars, no block scalar syntax.
        // Scan outside quotes/comments so forbidden syntax inside literal strings is harmless.
        $quote=null; $plain=str_repeat(' ',strlen($bytes)); $lineStart=true; $flows=[];
        for($i=0,$n=strlen($bytes);$i<$n;++$i) {
            $c=$bytes[$i];
            if($quote!==null) {
                if($quote==='"' && $c==='\\') { ++$i; continue; }
                if($c===$quote) {
                    if($quote==="'" && ($bytes[$i+1]??'')==="'") {++$i; continue;}
                    $quote=null;
                }
                continue;
            }
            if($c==='#' && ($i===0 || ctype_space($bytes[$i-1]))) {
                while($i<$n && $bytes[$i]!=="\n") ++$i; $c="\n";
            }
            if(($c==='"' || $c==="'") && ($i===0 || str_contains(" \t\r\n:[{,-",$bytes[$i-1]))) { $quote=$c; continue; }
            // Flow maps need their own duplicate check: Symfony 7 silently overwrites
            // a previous null value here, without even emitting a deprecation.
            $top=count($flows)-1;
            if($c==='{' || $c==='[') $flows[]=['kind'=>$c,'start'=>$i+1,'key'=>true,'seen'=>[]];
            elseif($c==='}' || $c===']') array_pop($flows);
            elseif($top>=0 && $flows[$top]['kind']==='{') {
                if($c===',') {$flows[$top]['start']=$i+1; $flows[$top]['key']=true;}
                elseif($c===':' && $flows[$top]['key']) {
                    $raw=trim(substr($bytes,$flows[$top]['start'],$i-$flows[$top]['start']));
                    $key=Yaml::parse($raw,Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
                    if(!is_string($key) && !is_int($key)) self::invalid();
                    if(isset($flows[$top]['seen'][$key])) self::invalid();
                    $flows[$top]['seen'][$key]=true; $flows[$top]['key']=false;
                }
            }
            if(($c==='&' || $c==='*' || $c==='!') && ($i===0 || str_contains(" \t\r\n:[{,-",$bytes[$i-1]))) self::invalid();
            if($c==='%' && $lineStart) self::invalid();
            if(($c==='|' || $c==='>') && ($i===0 || str_contains(" \t:",$bytes[$i-1]))) self::invalid();
            if($i<$n) $plain[$i]=$c;
            if($c==="\n") $lineStart=true; elseif($c!==' ' && $c!=="\t" && $c!=="\r") $lineStart=false;
        }
        if(preg_match('/(?:^|[\s{,])<<\s*:/',$plain)) self::invalid();
        // Symfony's legacy implicit timestamp/octal conversions are outside this subset.
        // Require quotes for these scalars rather than silently changing their model kind.
        if(preg_match('/(?:^|[\s\[:,])(?:[0-9]{4}-[0-9]{2}-[0-9]{2}(?:[Tt ][^\s,}\]]+)?|[-+]?0[0-9]+|[-+]?0[oOxX][0-9a-fA-F]+)(?=\s|[,}\]]|$)/',$plain)) self::invalid();
        // Reject non-core integer spellings; preserve big integers before Symfony
        // can turn them into strings, keeping quoted and unquoted values distinct.
        if(preg_match('/(?:^|[\s\[:,])[-+]?[0-9][0-9_]*_[0-9_]*(?=\s|[,}\]]|$)/',$plain)) self::invalid();
        preg_match_all('/(?:^|[\s\[:,])([-+]?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)(?=\s|[,}\]]|$)/',$plain,$numbers,PREG_OFFSET_CAPTURE);
        $markers=[]; $prefix='__arp_integer_'; while(str_contains($bytes,$prefix)) $prefix.='_';
        for($i=count($numbers[1])-1;$i>=0;--$i) {
            [$raw,$position]=$numbers[1][$i]; $value=self::jsonScalar(ltrim($raw,'+'));
            if($value instanceof ExactInteger || (is_int($value) && strpbrk($raw,'.eE')!==false)) {
                $marker=$prefix.$i; $markers[$marker]=$value;
                $bytes=substr_replace($bytes,"'".$marker."'",$position,strlen($raw));
            }
        }
        return [$bytes,$markers];

    }
    private static function jsonScalar(string $raw): mixed
    {
        if(!preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?\z/D',$raw,$m)) return json_decode($raw,false,512,JSON_THROW_ON_ERROR);
        $fraction=$m[3]??''; $exponent=$m[4]??'0';
        if(strlen(ltrim($exponent,'+-0'))>4 || abs((int)$exponent)>4096) throw new Failure('DICTIONARY_LIMIT_EXCEEDED');
        $scale=strlen($fraction)-(int)$exponent; $digits=$m[2].$fraction;
        if($scale>0) {
            if($scale>strlen($digits)) {
                if(trim($digits,'0')!=='') return json_decode($raw,false,512,JSON_THROW_ON_ERROR);
                $digits='0';
            } else {
                if(trim(substr($digits,-$scale),'0')!=='') return json_decode($raw,false,512,JSON_THROW_ON_ERROR);
                $digits=substr($digits,0,strlen($digits)-$scale);
            }
        } elseif($scale<0) $digits.=str_repeat('0',-$scale);
        $digits=ltrim($digits,'0') ?: '0'; $digits=($m[1]==='-' && $digits!=='0'?'-':'').$digits;
        if(Number::compare($digits,(string)PHP_INT_MIN)<0 || Number::compare($digits,(string)PHP_INT_MAX)>0) return new ExactInteger($digits);
        return (int)$digits;
    }
    private static function restoreIntegers(mixed $value,array $markers): mixed
    {
        if(is_string($value) && isset($markers[$value])) return $markers[$value];
        if(is_array($value)) foreach($value as $k=>$v) $value[$k]=self::restoreIntegers($v,$markers);
        elseif($value instanceof \stdClass) foreach($value as $k=>$v) $value->$k=self::restoreIntegers($v,$markers);
        return $value;
    }
    private static function unicode(mixed $value): void
    {
        if(is_string($value) && preg_match('//u',$value)!==1) self::invalid();
        if(is_float($value) && !is_finite($value)) self::invalid();
        if(is_object($value) && !$value instanceof \stdClass && !$value instanceof ExactInteger) self::invalid();
        if(is_array($value) || is_object($value)) foreach($value as $key=>$v) { self::unicode((string)$key); self::unicode($v); }
    }
    private static function invalid(): never { throw new Failure('DICTIONARY_DOCUMENT_INVALID'); }
}
