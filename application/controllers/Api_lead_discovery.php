<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'libraries/LeadDiscovery/LeadDiscoveryProvider.php';
require_once APPPATH . 'libraries/LeadDiscovery/ProviderException.php';
require_once APPPATH . 'libraries/LeadDiscovery/GooglePlacesProvider.php';
require_once APPPATH . 'libraries/LeadDiscovery/ApolloProvider.php';
require_once APPPATH . 'libraries/LeadDiscovery/ProviderRegistry.php';
require_once APPPATH . 'libraries/LeadDiscovery/Deduplicator.php';

/** Organization-scoped Lead Discovery API with pluggable, normalized providers. */
class Api_lead_discovery extends Api_controller
{
    private array $user; private string $org;
    /** Resolve the workspace only from the authenticated user's memberships; no client supplied org ID is trusted. */
    private function guard(): bool {
        $u=$this->requirePermission('system.authenticated'); if (!$u) return false; $this->user=$u;
        $requested=(string)($this->input->get_request_header('X-Lead-Organization') ?: '');
        $memberships=$this->db->where('user_id',(int)$u['id'])->order_by('created_at','ASC')->get('lead_organization_members')->result_array();
        // First authenticated use receives a private workspace. Teams can later add explicit memberships.
        if (!$memberships) { $id='org-'.(int)$u['id']; $now=$this->now(); $this->db->replace('lead_organizations',['id'=>$id,'name'=>($u['email'] ?? 'My').' workspace','created_at'=>$now]); $this->db->replace('lead_organization_members',['organization_id'=>$id,'user_id'=>(int)$u['id'],'role'=>'owner','created_at'=>$now]); $memberships=[['organization_id'=>$id,'role'=>'owner']]; }
        $chosen=$requested ?: $memberships[0]['organization_id'];
        foreach($memberships as $m) if(hash_equals((string)$m['organization_id'],$chosen)) { $this->org=$chosen; return true; }
        $this->jsonError('forbidden organization workspace',403); return false;
    }
    public function workspaces(){ if(!$this->guard())return; $rows=$this->db->select('o.id,o.name,m.role')->from('lead_organizations o')->join('lead_organization_members m','m.organization_id=o.id')->where('m.user_id',(int)$this->user['id'])->get()->result_array(); $this->json(['workspaces'=>$rows,'activeOrganizationId'=>$this->org]); }
    public function providers(){ if(!$this->guard())return; $this->json(['providers'=>(new \LeadDiscovery\ProviderRegistry([new \LeadDiscovery\GooglePlacesProvider(), new \LeadDiscovery\ApolloProvider()]))->health()]); }
    public function modes(){ if(!$this->guard())return; $this->json(['modes'=>[
        ['id'=>'business','label'=>'Business Mode','description'=>'Search B2B/business contacts by keyword + country/city. Works with both Google Places and Apollo.io.'],
        ['id'=>'person','label'=>'Person Mode','description'=>'Search for individuals by first-name list + country/city; results are filtered to people whose email is on a free webmail provider (gmail.com, yahoo.com, outlook.com, icloud.com, hotmail.com, aol.com, proton.me, live.com). Apollo.io required.'],
    ]]); }
    private function id(): string { return bin2hex(random_bytes(16)); }
    private function now(): string { return gmdate('c'); }
    private function recordActivity(?string $lead, string $type, array $detail=[]): void { $this->db->insert('lead_activities',['id'=>$this->id(),'lead_id'=>$lead,'organization_id'=>$this->org,'actor_id'=>$this->user['id'],'type'=>$type,'detail'=>json_encode($detail),'created_at'=>$this->now()]); }
    private function lead(string $id): ?array { return $this->db->get_where('leads',['id'=>$id,'organization_id'=>$this->org],1)->row_array() ?: null; }
    private function safeCsv(string $v): string { return preg_match('/^[=+\-@]/', $v) ? "'".$v : $v; }

    public function search() {
        if(!$this->guard())return; $b=$this->jsonBody();
        $mode=(string)($b['mode']??'business');
        if(!in_array($mode,['business','person'],true)) return $this->jsonError('invalid mode');
        $q=trim((string)($b['query']??''));
        $providerName=(string)($b['provider']??($mode==='person'?'apollo_io':'google_places'));
        $country=trim((string)($b['country']??''));
        $city=trim((string)($b['city']??''));
        $keywords=is_array($b['keywords']??null)?array_values(array_filter(array_map('trim',$b['keywords']),fn($s)=>$s!=='')):[];
        $names=is_array($b['names']??null)?array_values(array_filter(array_map('trim',$b['names']),fn($s)=>$s!=='')):[];
        $seniorities=is_array($b['seniorities']??null)?array_values(array_filter(array_map('trim',$b['seniorities']))):[];
        $titles=is_array($b['titles']??null)?array_values(array_filter(array_map('trim',$b['titles']))):[];
        $parts=[];
        if($q!=='') $parts[]=$q;
        if($mode==='business'){
            if($keywords===[]) $parts=array_values($parts);
            else foreach($keywords as $k) $parts[]=$k;
        } else {
            if($names===[]&&$parts===[]) return $this->jsonError('Enter one or more first names to search');
            foreach($names as $n) $parts[]=$n;
        }
        if($city!=='') $parts[]=$city;
        if($country!=='') $parts[]=$country;
        $finalQuery=trim(implode(' ',$parts));
        if(strlen($finalQuery)<3||strlen($finalQuery)>300) return $this->jsonError('query must be 3–300 characters');
        $location=trim(implode(', ',array_filter([$city,$country])));
        $started=microtime(true); $error=null; $raw=[]; $providerStatus='DISABLED';
        $providerInput=['query'=>$finalQuery,'limit'=>(int)($b['limit']??20)];
        if($mode==='person'||$providerName==='apollo_io'){
            $providerInput['person_locations']=$location!==''?[$location]:null;
            $providerInput['locations']=$providerInput['person_locations'];
            if($seniorities) $providerInput['seniorities']=$seniorities;
            if($titles) $providerInput['person_titles']=$titles;
            if($names) $providerInput['first_names']=$names;
        }
        try { $registry=new \LeadDiscovery\ProviderRegistry([new \LeadDiscovery\GooglePlacesProvider(), new \LeadDiscovery\ApolloProvider()]); $provider=$registry->get($providerName); $providerStatus=$provider->healthCheck()['status']; $raw=$provider->searchBusinesses($providerInput); }
        catch(\LeadDiscovery\ProviderException $e) { $error=\AIWorkforce\ApiProviders::publicError($e->getMessage()); $providerStatus=$e->httpStatus===422?'PLANNED':'DISABLED'; }
        $freeDomains=['gmail.com','yahoo.com','outlook.com','icloud.com','hotmail.com','aol.com','proton.me','live.com','me.com','mail.com','gmx.com','yandex.com'];
        if($mode==='person'){
            $raw=array_values(array_filter($raw,function($p)use($names,$freeDomains){
                $meta=is_array($p['metadata']??null)?$p['metadata']:[];
                $email=strtolower((string)($meta['email']??($p['email']??'')));
                if($email==='') return false;
                $domain=strtolower((string)substr($email, (int)strrpos($email,'@')+1));
                if(!in_array($domain,$freeDomains,true)) return false;
                if($names===[]) return true;
                $pname=strtolower($p['name']??'');
                foreach($names as $n){ if($n!==''&&strpos($pname, strtolower($n))===0) return true; }
                return false;
            }));
        }
        $new=0;$dupes=0;$candidateCount=0;$results=[];
        foreach($raw as $p){
            $sid=(string)$p['sourceId'];
            $meta=is_array($p['metadata']??null)?$p['metadata']:[];
            $email=(string)($meta['email']??($p['email']??''));
            $leadKind=$mode==='person'?'person':'business';
            $verificationStatus='provider_enriched';
            if($providerName==='apollo_io'){
                $es=$meta['email_status']??null;
                $verified=false;
                if(is_array($es)){ foreach($es as $c){ if(is_array($c)&&($c['verified']??false)){$verified=true;break;} } }
                elseif(is_string($es)&&stripos($es,'verified')!==false) $verified=true;
                if($verified) $verificationStatus='verified';
                if(!empty($p['phone'])&&!$verified) $verificationStatus='partial_verified';
            } elseif($providerName==='google_places'){
                $verificationStatus='business_listing';
            }
            $meta['verification_status']=$verificationStatus;
            $meta['mode']=$mode;
            $meta['country']=$country?:null;
            $meta['city']=$city?:null;
            $meta['keyword']=$keywords?:($q?:null);
            $existing=$this->db->get_where('leads',['organization_id'=>$this->org,'source'=>$providerName,'source_id'=>$sid],1)->row_array();
            $record=['organization_id'=>$this->org,'source'=>$providerName,'source_id'=>$sid,'name'=>$p['name'],'category'=>$p['category'],'address'=>$p['address'],
                'phone'=>$p['phone'],'website'=>$p['website'],'latitude'=>$p['latitude'],'longitude'=>$p['longitude'],
                'email'=>$email?:null,'job_title'=>($meta['title']??null),'company_name'=>($meta['company']??null),
                'linkedin_url'=>($meta['linkedin_url']??null),'lead_kind'=>$leadKind,
                'metadata'=>json_encode($meta),'updated_at'=>$this->now()];
            if($existing){$this->db->where('id',$existing['id'])->where('organization_id',$this->org)->update('leads',$record);$record['id']=$existing['id'];$dupes++;$this->recordActivity($existing['id'],'DUPLICATE_DETECTED',['rule'=>'provider_source_id']);}
            else {$record+=['id'=>$this->id(),'status'=>'new','owner_id'=>null,'created_at'=>$this->now()];$this->db->insert('leads',$record);$new++;$this->recordActivity($record['id'],'LEAD_DISCOVERED',['query'=>$finalQuery,'provider'=>$providerName,'mode'=>$mode]);}
            $candidateCount+=(new \LeadDiscovery\Deduplicator($this->db))->detect($record,$this->org);
            $record['metadata']=$meta;
            $record['verification_status']=$verificationStatus;
            $results[]=$record;
        }
        $this->db->insert('search_history',['id'=>$this->id(),'organization_id'=>$this->org,'user_id'=>(int)$this->user['id'],'query'=>$finalQuery,'provider'=>$providerName,'filters'=>json_encode(['mode'=>$mode,'limit'=>$b['limit']??20,'country'=>$country,'city'=>$city,'keywords'=>$keywords,'names'=>$names,'seniorities'=>$seniorities,'titles'=>$titles]),'results_returned'=>count($raw),'new_leads_created'=>$new,'duplicates_detected'=>$dupes+$candidateCount,'errors'=>$error,'duration_ms'=>(int)((microtime(true)-$started)*1000),'created_at'=>$this->now()]);
        if($error)return $this->jsonError($error,503,['providerStatus'=>$providerStatus]);
        $this->json(['mode'=>$mode,'provider'=>$providerName,'providerStatus'=>$providerStatus,'results'=>$results,'newLeadsCreated'=>$new,'duplicatesDetected'=>$dupes+$candidateCount,'duplicateCandidatesCreated'=>$candidateCount,'freeEmailCount'=>($mode==='person'?count($results):null)]);
    }
    public function leads($id=null){if(!$this->guard())return;if($id){$x=$this->lead($id);return $x?$this->json($x):$this->jsonError('lead not found',404);} $this->db->where('organization_id',$this->org);if($s=$this->input->get('status'))$this->db->where('status',$s);$this->json(['leads'=>$this->db->order_by('updated_at','DESC')->limit(250)->get('leads')->result_array()]);}
    public function collections($id=null){
        if(!$this->guard())return;$method=$this->input->method(true);
        if($id&&$method==='PATCH'){$b=$this->jsonBody();$name=trim((string)($b['name']??''));if(!$name||strlen($name)>150)return $this->jsonError('name must be 1–150 characters');$this->db->where(['id'=>$id,'organization_id'=>$this->org])->update('collections',['name'=>$name,'updated_at'=>$this->now()]);return $this->json(['ok'=>true]);}
        if($id&&$method==='DELETE'){$this->db->where('collection_id',$id)->delete('collection_leads');$this->db->where(['id'=>$id,'organization_id'=>$this->org])->delete('collections');return $this->json(['ok'=>true]);}
        if($method==='POST'){$name=trim((string)($this->jsonBody()['name']??''));if(!$name||strlen($name)>150)return $this->jsonError('name must be 1–150 characters');$x=['id'=>$this->id(),'organization_id'=>$this->org,'name'=>$name,'created_at'=>$this->now(),'updated_at'=>$this->now()];$this->db->insert('collections',$x);return $this->json($x,201);}
        $rows=$this->db->select('c.*, COUNT(cl.lead_id) leadCount')->from('collections c')->join('collection_leads cl','cl.collection_id=c.id','left')->where('c.organization_id',$this->org)->group_by('c.id')->order_by('c.name')->get()->result_array();$this->json(['collections'=>$rows]);
    }
    public function collection_leads($collection,$lead=null){
        if(!$this->guard())return;if(!$this->db->get_where('collections',['id'=>$collection,'organization_id'=>$this->org],1)->row_array())return $this->jsonError('collection not found',404);
        if($this->input->method(true)==='GET')return $this->json(['leads'=>$this->db->select('l.*')->from('leads l')->join('collection_leads cl','cl.lead_id=l.id')->where(['cl.collection_id'=>$collection,'l.organization_id'=>$this->org])->get()->result_array()]);
        $ids=$lead?[$lead]:($this->jsonBody()['leadIds']??[]);if(!is_array($ids)||!$ids||count($ids)>200)return $this->jsonError('leadIds must contain 1–200 leads');$added=0;$removed=0;
        foreach(array_unique($ids) as $leadId){if(!$this->lead((string)$leadId))return $this->jsonError('lead not found',404,['leadId'=>$leadId]);if($this->input->method(true)==='DELETE'){$this->db->where(['collection_id'=>$collection,'lead_id'=>$leadId])->delete('collection_leads');$removed+=$this->db->affected_rows();$this->recordActivity($leadId,'LEAD_REMOVED_FROM_COLLECTION',['collectionId'=>$collection]);}else{$exists=$this->db->where(['collection_id'=>$collection,'lead_id'=>$leadId])->count_all_results('collection_leads');if(!$exists){$this->db->insert('collection_leads',['collection_id'=>$collection,'lead_id'=>$leadId]);$added++;$this->recordActivity($leadId,'LEAD_ADDED_TO_COLLECTION',['collectionId'=>$collection]);}}}
        $this->json(['ok'=>true,'added'=>$added,'removed'=>$removed]);
    }
    public function outreach($id){if(!$this->guard())return;
        $lead=$this->lead($id);if(!$lead)return $this->jsonError('lead not found',404);
        if($this->input->method(true)==='GET'){
            $rows=$this->db->where(['organization_id'=>$this->org,'lead_id'=>$id])->order_by('created_at','DESC')->limit(100)->get('lead_outreach')->result_array();
            foreach($rows as &$r){$r['detail']=json_decode($r['detail']??'{}',true)?:[];} unset($r);
            return $this->json(['outreach'=>$rows]);
        }
        $b=$this->jsonBody();
        $channel=trim((string)($b['channel']??'email'));
        if(!in_array($channel,['email','linkedin','note','call'],true)) return $this->jsonError('invalid channel (email|linkedin|note|call)');
        $subject=trim((string)($b['subject']??''));
        $body=trim((string)($b['body']??''));
        if($body===''||strlen($body)>8000) return $this->jsonError('body must be 1–8000 characters');
        if($channel==='email'&&$subject==='') return $this->jsonError('subject is required for email');
        $meta=json_decode($lead['metadata']??'{}',true)?:[];
        $toEmail=(string)($lead['email']??$meta['email']??'');
        if($channel==='email'&&$toEmail==='') return $this->jsonError('this lead has no email address on file',422);
        $rec=['id'=>$this->id(),'organization_id'=>$this->org,'lead_id'=>$id,'actor_id'=>(int)$this->user['id'],'channel'=>$channel,'subject'=>$subject?:null,'body'=>$body,
            'status'=>'draft','detail'=>json_encode(['to'=>$toEmail?:null,'sent_at'=>null,'delivered'=>null,'bounced'=>null,'lead_name'=>$lead['name']]),'created_at'=>$this->now()];
        // If email sending is configured (SMTP/SES/Resend/Postmark), actually deliver; otherwise store as a draft/queued message.
        $delivered=false; $transport=null; $errorDetail=null;
        if($channel==='email'){
            $delivered=$this->deliverEmail($toEmail,$subject,$body,$lead,$transport,$errorDetail);
            $rec['status']=$delivered?'sent':'draft';
            $rec['detail']=json_encode(['sent_at'=>$delivered?$this->now():null,'delivered'=>$delivered,'bounced'=>false,'transport'=>$transport,'error'=>$errorDetail,'lead_name'=>$lead['name']]);
        } else {
            $rec['status']='queued';
        }
        $this->db->insert('lead_outreach',$rec);
        $this->db->where('id',$id)->where('organization_id',$this->org)->update('leads',['status'=>'contacted','updated_at'=>$this->now()]);
        $this->recordActivity($id,'OUTREACH_SENT',['channel'=>$channel,'subject'=>$subject,'to'=>$toEmail,'delivered'=>$delivered,'outreachId'=>$rec['id'],'transport'=>$transport]);
        $rec['detail']=json_decode($rec['detail'],true); $rec['delivered']=$delivered; $rec['transport']=$transport; $rec['error']=$errorDetail; $rec['to']=$toEmail?:null;
        $this->json(['ok'=>true,'outreach'=>$rec], $delivered?201:202);
    }
    /** Attempt to deliver an email through any configured transport; returns false if no transport is configured (stored as draft). */
    private function deliverEmail(string $to,string $subject,string $body,array $lead,?string &$transport=null,?string &$error=null): bool {
        $fromEmail=(string)(getenv('OUTREACH_FROM_EMAIL')?:getenv('MAIL_FROM_EMAIL')?:'');
        $fromName=(string)(getenv('OUTREACH_FROM_NAME')?:getenv('MAIL_FROM_NAME')?:'AI Workforce Outreach');
        if($fromEmail===''){$error='no outgoing email transport configured (set OUTREACH_FROM_EMAIL + MAIL_DSN or SMTP_*) — message saved as draft';return false;}
        // Try Resend / Postmark / SES / SMTP via simple env-driven transports. Prefer Resend (RESEND_API_KEY), then Postmark (POSTMARK_SERVER_TOKEN), then SMTP.
        if(($key=(string)(getenv('RESEND_API_KEY')?:''))!==''){ $transport='resend'; return $this->postJsonEmail('https://api.resend.com/emails',$key,'Bearer',[
            'from'=>$fromName!==''?($fromName.' <'.$fromEmail.'>'):$fromEmail,
            'to'=>[$to],
            'subject'=>$subject,
            'text'=>$body,
        ],$error);}
        if(($key=(string)(getenv('POSTMARK_SERVER_TOKEN')?:''))!==''){ $transport='postmark'; return $this->postJsonEmail('https://api.postmarkapp.com/email',$key,'X-Postmark-Server-Token',[
            'From'=>$fromEmail,'To'=>$to,'Subject'=>$subject,'TextBody'=>$body,
        ],$error);}
        if(($smtpHost=(string)(getenv('SMTP_HOST')?:getenv('MAIL_HOST')?:''))!==''){ $transport='smtp'; return $this->smtpSend($smtpHost,(int)(getenv('SMTP_PORT')?:getenv('MAIL_PORT')?:587),(string)(getenv('SMTP_USER')?:getenv('MAIL_USERNAME')?:''),(string)(getenv('SMTP_PASS')?:getenv('MAIL_PASSWORD')?:''),$fromEmail,$fromName,$to,$subject,$body,$error); }
        $error='no outgoing email transport configured — message saved as draft'; return false;
    }
    private function postJsonEmail(string $url,string $key,string $authHeader,array $payload,?string &$error): bool {
        $headers = "Content-Type: application/json\r\nAccept: application/json\r\n".($authHeader==='Bearer'?"Authorization: Bearer $key\r\n":"$authHeader: $key\r\n");
        $ctx=stream_context_create(['http'=>['method'=>'POST','timeout'=>15,'ignore_errors'=>true,'header'=>$headers,'content'=>json_encode($payload)],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $body=@file_get_contents($url,false,$ctx); $status=0;
        foreach(($http_response_header??[]) as $line){if(preg_match('#HTTP/\S+\s+(\d+)#',$line,$m)){$status=(int)$m[1];break;}}
        if($status>=200&&$status<300)return true;
        $decoded=json_decode((string)$body,true); $error=($decoded['message']??$decoded['Message']??'HTTP '.(string)$status); return false;
    }
    private function smtpSend(string $host,int $port,string $user,string $pass,string $from,string $fromName,string $to,string $subject,string $body,?string &$error): bool {
        if(!function_exists('fsockopen')){$error='fsockopen disabled — cannot send SMTP';return false;}
        $f=@fsockopen(($port===465?'ssl://':'').$host,$port,$eno,$estr,10); if(!$f){$error='SMTP connect failed: '.$estr;return false;}
        stream_set_timeout($f,10);
        $get=function()use($f){$d='';while(($l=fgets($f,512))!==false){$d.=$l;if(isset($l[3])&&$l[3]===' ')break;}return $d;};
        $cmd=function($c)use($f){fwrite($f,$c."\r\n");};
        $get(); // banner
        $cmd('EHLO ai-workforce'); $get();
        if($port===587){$cmd('STARTTLS'); $resp=$get(); if(!str_starts_with($resp,'220')){fclose($f);$error='STARTTLS rejected';return false;} stream_socket_enable_crypto($f,true,STREAM_CRYPTO_METHOD_TLS_CLIENT); $cmd('EHLO ai-workforce'); $get();}
        if($user!==''){$cmd('AUTH LOGIN'); $get(); $cmd(base64_encode($user)); $get(); $cmd(base64_encode($pass)); $resp=$get(); if(!str_starts_with($resp,'235')){fclose($f);$error='SMTP AUTH failed';return false;}}
        $cmd('MAIL FROM:<'.$from.'>'); $get(); $cmd('RCPT TO:<'.$to.'>'); $get(); $cmd('DATA'); $get();
        $fromHeader=$fromName!==''?$fromName.' <'.$from.'>':$from;
        $cmd('From: '.$fromHeader); $cmd('To: '.$to); $cmd('Subject: =?UTF-8?B?'.base64_encode($subject).'?='); $cmd('MIME-Version: 1.0'); $cmd('Content-Type: text/plain; charset=UTF-8'); $cmd('Content-Transfer-Encoding: 8bit'); $cmd(''); $cmd($body); $cmd('.'); $resp=$get(); $cmd('QUIT'); fclose($f);
        return str_starts_with($resp,'250');
    }
    public function status($id){if(!$this->guard())return;$x=$this->lead($id);$v=$this->jsonBody()['status']??'';if(!$x)return $this->jsonError('lead not found',404);if(!in_array($v,['new','contacted','qualified','disqualified','converted'],true))return $this->jsonError('invalid status');$this->db->where('id',$id)->update('leads',['status'=>$v,'updated_at'=>$this->now()]);$this->recordActivity($id,'STATUS_CHANGED',['from'=>$x['status'],'to'=>$v]);$this->json(['ok'=>true]);}
    public function owner($id){if(!$this->guard())return;if(!$this->lead($id))return $this->jsonError('lead not found',404);$owner=$this->jsonBody()['ownerId']??null;if($owner!==null&&!ctype_digit((string)$owner))return $this->jsonError('ownerId must be an integer or null');$this->db->where('id',$id)->update('leads',['owner_id'=>$owner,'updated_at'=>$this->now()]);$this->recordActivity($id,$owner===null?'OWNER_REMOVED':'OWNER_ASSIGNED',['ownerId'=>$owner]);$this->json(['ok'=>true]);}
    public function notes($id){if(!$this->guard())return;if(!$this->lead($id))return $this->jsonError('lead not found',404);if($this->input->method(true)==='POST'){$body=trim((string)($this->jsonBody()['body']??''));if(!$body||strlen($body)>4000)return $this->jsonError('note must be 1–4000 characters');$n=['id'=>$this->id(),'lead_id'=>$id,'organization_id'=>$this->org,'author_id'=>$this->user['id'],'body'=>$body,'created_at'=>$this->now()];$this->db->insert('lead_notes',$n);$this->recordActivity($id,'NOTE_ADDED',[]);return $this->json($n,201);}$this->json(['notes'=>$this->db->where(['lead_id'=>$id,'organization_id'=>$this->org])->order_by('created_at','DESC')->get('lead_notes')->result_array()]);}
    public function activity($id){if(!$this->guard())return;if(!$this->lead($id))return $this->jsonError('lead not found',404);$this->json(['activity'=>$this->db->where(['lead_id'=>$id,'organization_id'=>$this->org])->order_by('created_at','DESC')->get('lead_activities')->result_array()]);}
    public function duplicates($resolve=false){
        if(!$this->guard())return;
        if(!$resolve){$rows=$this->db->select('d.*, a.name leadAName, b.name leadBName')->from('duplicate_candidates d')->join('leads a','a.id=d.lead_a_id')->join('leads b','b.id=d.lead_b_id')->where(['d.organization_id'=>$this->org,'d.status'=>'open'])->order_by('d.created_at','DESC')->get()->result_array();return $this->json(['duplicates'=>$rows]);}
        $b=$this->jsonBody();$id=(string)($b['candidateId']??'');$action=(string)($b['action']??'');
        if(!in_array($action,['keep_a','keep_b','merge','ignore'],true))return $this->jsonError('invalid duplicate resolution');
        $c=$this->db->get_where('duplicate_candidates',['id'=>$id,'organization_id'=>$this->org],1)->row_array();if(!$c)return $this->jsonError('duplicate candidate not found',404);
        $a=$this->lead($c['lead_a_id']);$bLead=$this->lead($c['lead_b_id']);if(!$a||!$bLead)return $this->jsonError('candidate leads no longer exist',409);
        if($action==='merge') { $canonical=$a;$duplicate=$bLead; foreach(['category','address','city','region','country','phone','website','latitude','longitude'] as $field) if(empty($canonical[$field])&&!empty($duplicate[$field]))$canonical[$field]=$duplicate[$field]; $canonical['updated_at']=$this->now();$this->db->where('id',$canonical['id'])->update('leads',$canonical);$this->archiveDuplicate($duplicate,$canonical['id'],'merged');$this->recordActivity($canonical['id'],'DUPLICATE_RESOLVED',['candidateId'=>$id,'action'=>'merge','mergedLeadId'=>$duplicate['id']]); }
        elseif($action==='keep_a'||$action==='keep_b') { $keep=$action==='keep_a'?$a:$bLead;$discard=$action==='keep_a'?$bLead:$a;$this->archiveDuplicate($discard,$keep['id'],'discarded');$this->recordActivity($keep['id'],'DUPLICATE_RESOLVED',['candidateId'=>$id,'action'=>$action,'discardedLeadId'=>$discard['id']]); }
        else $this->recordActivity($a['id'],'DUPLICATE_RESOLVED',['candidateId'=>$id,'action'=>'ignore']);
        $this->db->where('id',$id)->update('duplicate_candidates',['status'=>'resolved']);$this->db->insert('duplicate_resolutions',['id'=>$this->id(),'candidate_id'=>$id,'organization_id'=>$this->org,'resolver_id'=>$this->user['id'],'action'=>$action,'created_at'=>$this->now()]);$this->json(['ok'=>true]);
    }
    private function archiveDuplicate(array $lead,string $keptId,string $reason):void { $metadata=json_decode($lead['metadata']??'{}',true)?:[];$metadata['duplicateResolution']=['keptLeadId'=>$keptId,'reason'=>$reason,'at'=>$this->now()];$this->db->where('id',$lead['id'])->update('leads',['status'=>'disqualified','metadata'=>json_encode($metadata),'updated_at'=>$this->now()]);$this->recordActivity($lead['id'],'DUPLICATE_RESOLVED',['keptLeadId'=>$keptId,'action'=>$reason]); }
    public function coverage(){if(!$this->guard())return;$all=$this->db->where('organization_id',$this->org)->get('leads')->result_array();$n=count($all);$fields=['name'=>'Business Name','address'=>'Address','category'=>'Category','phone'=>'Phone','website'=>'Website'];$out=[];foreach($fields as $f=>$label){$filled=count(array_filter($all,fn($x)=>!empty($x[$f])));$out[]=['key'=>$f,'field'=>$label,'coverage'=>$n?round(100*$filled/$n,1):0,'missing'=>$n-$filled];}$missing=(string)$this->input->get('missing');if($missing!==''&&!isset($fields[$missing]))return $this->jsonError('invalid missing field');$missingLeads=$missing===''?[]:array_values(array_filter($all,fn($x)=>empty($x[$missing])));$this->json(['leadCount'=>$n,'fields'=>$out,'missingField'=>$missing?:null,'missingLeads'=>$missingLeads]);}
    public function history(){if(!$this->guard())return;$this->json(['history'=>$this->db->where('organization_id',$this->org)->order_by('created_at','DESC')->limit(100)->get('search_history')->result_array()]);}
    public function summary(){if(!$this->guard())return;$rows=$this->db->select('status, COUNT(*) total')->where('organization_id',$this->org)->group_by('status')->get('leads')->result_array();$this->json(['pipeline'=>$rows]);}
    public function pipeline(){if(!$this->guard())return;$statuses=['new','contacted','qualified','disqualified','converted'];$columns=array_fill_keys($statuses,[]);foreach($this->db->where('organization_id',$this->org)->order_by('updated_at','DESC')->get('leads')->result_array() as $lead)$columns[$lead['status']][]=$lead;$this->json(['statuses'=>$statuses,'columns'=>$columns]);}
    public function export($mode='json'){if(!$this->guard())return;$b=$this->jsonBody();$this->db->where('l.organization_id',$this->org)->from('leads l');if(!empty($b['collectionId']))$this->db->join('collection_leads cl','cl.lead_id=l.id')->where('cl.collection_id',$b['collectionId']);foreach(['status','owner_id','country','category'] as $f)if(isset($b[$f])&&$b[$f]!=='')$this->db->where('l.'.$f,$b[$f]);if(!empty($b['from']))$this->db->where('l.created_at >=',$b['from']);if(!empty($b['to']))$this->db->where('l.created_at <=',$b['to']);$leads=$this->db->get()->result_array();if($mode==='preview'){foreach($leads as &$row)foreach($row as $k=>$v)$row[$k]=is_string($v)?$this->safeCsv($v):$v;return $this->json(['rows'=>array_slice($leads,0,25),'count'=>count($leads),'csvSafe'=>true]);}$format=$mode==='csv'?'csv':($b['format']??'json');$this->db->insert('export_history',['id'=>$this->id(),'organization_id'=>$this->org,'user_id'=>$this->user['id'],'format'=>$format,'filters'=>json_encode($b),'lead_count'=>count($leads),'created_at'=>$this->now()]);foreach($leads as $x)$this->recordActivity($x['id'],'LEAD_EXPORTED',['format'=>$format]);if($format==='csv'){$keys=['name','category','address','city','country','phone','website','status'];$lines=[implode(',',$keys)];foreach($leads as $l){$lines[]=implode(',',array_map(fn($k)=>'"'.str_replace('"','""',$this->safeCsv((string)($l[$k]??''))).'"',$keys));}$this->output->set_content_type('text/csv')->set_header('Content-Disposition: attachment; filename="leads.csv"')->set_output(implode("\r\n",$lines));return;}$this->json(['leads'=>$leads,'count'=>count($leads)]);}
}
