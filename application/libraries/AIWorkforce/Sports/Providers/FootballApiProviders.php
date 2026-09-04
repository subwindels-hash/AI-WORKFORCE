<?php
namespace AIWorkforce\Sports\Providers;

/** Native adapters for the public football APIs. They expose the same safe,
 * provider-neutral contract as HttpSportsProvider; secrets stay server-side. */
class FootballApiProvider implements SportsDataProvider
{
    public function __construct(private string $id, private string $base, private string $token, private string $kind, private int $timeout = 10, ?callable $transport = null)
    {
        $this->transport = $transport ?? function (string $url, array $headers): array {
            $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>$this->timeout,'header'=>implode("\r\n",$headers),'ignore_errors'=>true]]);
            $body = @file_get_contents($url, false, $ctx); $status=0;
            if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#',$http_response_header[0],$m)) $status=(int)$m[1];
            return ['status'=>$status,'body'=>(string)$body];
        };
    }
    private $transport;
    public function id(): string { return $this->id; }
    public function health(): array { try { $this->request($this->kind==='api-football' ? '/status' : ($this->kind==='thesportsdb' ? '/eventsday.php?d='.gmdate('Y-m-d').'&s=Soccer' : '/leagues')); return ['status'=>'ONLINE','reliability'=>1.0,'errorRate'=>0.0]; } catch (ProviderException $e) { return ['status'=>$e->status,'reliability'=>0.0,'errorRate'=>1.0,'detail'=>$e->getMessage()]; } }
    public function fixtures(array $query): array {
        $from=$query['from']??gmdate('Y-m-d'); $to=$query['to']??$from;
        if ($this->kind==='api-football') $raw=$this->request('/fixtures?from='.rawurlencode($from).'&to='.rawurlencode($to));
        elseif ($this->kind==='thesportsdb') $raw=$this->request('/eventsday.php?d='.rawurlencode($from).'&s=Soccer');
        else $raw=$this->request('/fixtures?filter[starts_between]='.$from.','.$to.'&include=participants;scores;league');
        return $this->mapFixtures($this->list($raw));
    }
    public function odds(string $id): array {
        if ($this->kind !== 'api-football') return []; // TheSportsDB has no odds API; SportMonks odds require a paid odds add-on.
        $rows=$this->list($this->request('/odds?fixture='.rawurlencode($id))); $out=[];
        foreach($rows as $r) foreach((array)($r['bookmakers']??[]) as $b) foreach((array)($b['bets']??[]) as $bet) foreach((array)($bet['values']??[]) as $v) if(isset($v['odd'])) $out[]=['market'=>(string)($bet['name']??'UNKNOWN'),'selection'=>(string)($v['value']??''),'decimalOdds'=>(float)$v['odd'],'observedAt'=>gmdate('c'),'bookmaker'=>(string)($b['name']??'')];
        return $out;
    }
    public function results(string $id): array {
        if ($this->kind==='api-football') $raw=$this->request('/fixtures?id='.rawurlencode($id));
        elseif ($this->kind==='thesportsdb') $raw=$this->request('/lookupevent.php?id='.rawurlencode($id));
        else $raw=$this->request('/fixtures/'.rawurlencode($id).'?include=scores');
        return $this->mapResults($this->list($raw));
    }
    private function request(string $path): array {
        $headers=['Accept: application/json']; $url=rtrim($this->base,'/').$path;
        if ($this->kind==='thesportsdb') $url=rtrim($this->base,'/').'/'.rawurlencode($this->token).'/'.ltrim($path,'/');
        if ($this->kind==='api-football') $headers[]='x-apisports-key: '.$this->token;
        elseif ($this->kind==='sportmonks') $url .= (str_contains($url,'?')?'&':'?').'api_token='.rawurlencode($this->token);
        else $url=rtrim($this->base,'/').'/'.ltrim($path,'/');
        $r=($this->transport)($url,$headers); $s=(int)($r['status']??0);
        if ($s===401||$s===403) throw new ProviderException('authentication rejected',ProviderException::AUTHENTICATION_ERROR);
        if ($s===429) throw new ProviderException('rate limited',ProviderException::RATE_LIMITED);
        if ($s<200||$s>=300) throw new ProviderException('upstream HTTP '.$s,ProviderException::DATA_ERROR);
        return $r;
    }
    private function list(array $r): array { $d=json_decode($r['body']??'',true); if (!is_array($d)) throw new ProviderException('invalid JSON',ProviderException::DATA_ERROR); foreach(['response','events','data'] as $k) if(isset($d[$k])&&is_array($d[$k])) return $d[$k]; return array_is_list($d)?$d:[]; }
    private function mapFixtures(array $rows): array { $o=[]; foreach($rows as $r){$home=$r['teams']['home']['name']??$r['strHomeTeam']??$this->participant($r,'home');$away=$r['teams']['away']['name']??$r['strAwayTeam']??$this->participant($r,'away');$time=$r['fixture']['date']??$r['dateEvent']??$r['starting_at']??null;if($home&&$away&&$time)$o[]=['externalId'=>(string)($r['fixture']['id']??$r['idEvent']??$r['id']??''),'homeTeam'=>$home,'awayTeam'=>$away,'competition'=>$r['league']['name']??$r['strLeague']??($r['league']['name']??'Football'),'kickoff'=>$time,'status'=>'SCHEDULED','sport'=>'football','sourceTimestamp'=>gmdate('c')];} return $o; }
    private function participant(array $r,string $side): ?string { foreach((array)($r['participants']??[]) as $p) if(($p['meta']['location']??'')===$side) return $p['name']??null; return null; }
    private function mapResults(array $rows): array { $o=[]; foreach($rows as $r){$o[]=['externalId'=>(string)($r['fixture']['id']??$r['idEvent']??$r['id']??''),'status'=>'FINISHED','homeScore'=>$r['goals']['home']??$r['intHomeScore']??null,'awayScore'=>$r['goals']['away']??$r['intAwayScore']??null,'sourceTimestamp'=>gmdate('c')];} return $o; }
}
