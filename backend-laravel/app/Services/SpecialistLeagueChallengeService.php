<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Research-only League admission and bounded challenge coevolution contracts. */
class SpecialistLeagueChallengeService
{
    public const PROTOCOL = 'specialist_league_challenge_v1';
    public const LEAGUE_ROLES = ['main_specialist', 'historical_specialist', 'weakness_exploiter', 'novelty_challenger', 'drift_challenger', 'skill_distiller'];
    public const CHALLENGE_STATES = ['too_easy', 'learnable_now', 'too_hard', 'unlearnable_with_current_primitives', 'solved_by_transfer', 'reveals_new_failure'];

    /** @return array<string,mixed> */
    public function enroll(array $member, array $challenge): array
    {
        if (! Schema::hasTable('research_specialist_league_entries')) return $this->unavailable();
        $role = (string) ($member['role'] ?? '');
        if (! in_array($role, self::LEAGUE_ROLES, true)) return $this->blocked('EXPLICIT_LEAGUE_ROLE_REQUIRED');
        $symbol = strtoupper((string) ($member['symbol'] ?? 'XAUUSD')); $timeframe = strtoupper((string) ($member['timeframe'] ?? 'H1'));
        $specialty = (string) ($member['specialty_key'] ?? 'unbounded');
        $key = hash('sha256', implode('|', [self::PROTOCOL, $member['model_version_id'] ?? 'none', $symbol, $timeframe, $role, $specialty, $this->hash($challenge)]));
        DB::table('research_specialist_league_entries')->updateOrInsert(['league_key'=>$key],[
            'model_version_id'=>$member['model_version_id'] ?? null,'symbol'=>$symbol,'timeframe'=>$timeframe,'role'=>$role,'status'=>'challenge_planned',
            'specialty_key'=>$specialty,'challenge_contract'=>json_encode(['protocol'=>self::PROTOCOL,...$challenge,'research_only'=>true,'promotion_evidence'=>false]),
            'evidence'=>json_encode(['member'=>$member,'profit_objective_for_exploiter'=>$role!=='weakness_exploiter','promotion_evidence'=>false]),
            'updated_at'=>now(),'created_at'=>now(),
        ]);
        return ['protocol'=>self::PROTOCOL,'status'=>'challenge_planned','league_key'=>$key,'promotion_evidence'=>false];
    }

    /** @return array<string,mixed> */
    public function settleLeague(string $leagueKey, array $evidence): array
    {
        if (! Schema::hasTable('research_specialist_league_entries')) return $this->unavailable();
        $entry=DB::table('research_specialist_league_entries')->where('league_key',$leagueKey)->first();
        if (! $entry) return $this->blocked('LEAGUE_ENTRY_NOT_FOUND');
        $role=(string)$entry->role;
        $passed = $role === 'weakness_exploiter'
            ? (bool)($evidence['weakness_search_completed'] ?? false)
            : (bool)($evidence['independently_viable'] ?? false);
        $status=$passed?'challenge_settled':'challenge_failed';
        DB::table('research_specialist_league_entries')->where('id',$entry->id)->update(['status'=>$status,
            'evidence'=>json_encode([...((array)json_decode((string)$entry->evidence,true)),'settlement'=>$evidence,'promotion_evidence'=>false]),'updated_at'=>now()]);
        return ['protocol'=>self::PROTOCOL,'status'=>$status,'promotion_evidence'=>false];
    }

    /** Council requires independent viability, complementarity, low correlated failure, exploiter evidence and LOO value. */
    public function councilCandidate(string $leagueKey, array $assessment): array
    {
        if (! Schema::hasTable('research_specialist_league_entries')) return $this->unavailable();
        $entry=DB::table('research_specialist_league_entries')->where('league_key',$leagueKey)->first();
        if (! $entry) return $this->blocked('LEAGUE_ENTRY_NOT_FOUND');
        $requirements=['independently_viable','complementary','low_correlated_failure','exploiter_challenge_passed','member_removal_value'];
        $missing=collect($requirements)->filter(fn(string $key):bool=>($assessment[$key]??false)!==true)->values()->all();
        $status=$missing===[]?'council_candidate':'league_retained';
        DB::table('research_specialist_league_entries')->where('id',$entry->id)->update(['status'=>$status,
            'evidence'=>json_encode([...((array)json_decode((string)$entry->evidence,true)),'council_assessment'=>$assessment,'promotion_evidence'=>false]),'updated_at'=>now()]);
        return ['protocol'=>self::PROTOCOL,'status'=>$status,'missing_gates'=>$missing,'promotion_evidence'=>false];
    }

    /** @return array<string,mixed> */
    public function planChallenge(array $challenge, array $gates): array
    {
        if (! Schema::hasTable('research_challenge_archive')) return $this->unavailable();
        if ((int)($gates['confirmed_cartridges']??0)<1 || (int)($gates['successful_transfers']??0)<1) return $this->blocked('ACADEMY_SKILL_AND_TRANSFER_GATE_REQUIRED');
        $status=(string)($challenge['status']??'learnable_now');
        if (!in_array($status,self::CHALLENGE_STATES,true)) return $this->blocked('UNKNOWN_CHALLENGE_STATE');
        $symbol=strtoupper((string)($challenge['symbol']??'XAUUSD')); $timeframe=strtoupper((string)($challenge['timeframe']??'H1'));
        if (($challenge['pre_2026_only']??false)!==true || !filled($challenge['snapshot_hash']??null)) return $this->blocked('SEALED_PRE2026_CHALLENGE_REQUIRED');
        $niche=(string)($challenge['niche_key']??'general'); $key=$this->hash([self::PROTOCOL,$symbol,$timeframe,$niche,$challenge['snapshot_hash'],$status]);
        DB::table('research_challenge_archive')->updateOrInsert(['challenge_key'=>$key],[
            'symbol'=>$symbol,'timeframe'=>$timeframe,'status'=>$status,'niche_key'=>$niche,'prerequisites'=>json_encode($gates),
            'contract'=>json_encode(['protocol'=>self::PROTOCOL,...$challenge,'promotion_evidence'=>false]),'evidence'=>null,'updated_at'=>now(),'created_at'=>now(),
        ]);
        return ['protocol'=>self::PROTOCOL,'status'=>'planned','challenge_key'=>$key,'promotion_evidence'=>false];
    }

    /** Deterministic state transition; never creates a challenge outside sealed historical data. */
    public function settleChallenge(string $challengeKey, array $outcome): array
    {
        if (! Schema::hasTable('research_challenge_archive')) return $this->unavailable();
        $row=DB::table('research_challenge_archive')->where('challenge_key',$challengeKey)->first();
        if (!$row) return $this->blocked('CHALLENGE_NOT_FOUND');
        $next=match(true) {
            (bool)($outcome['all_agents_solved']??false) => 'too_easy',
            (bool)($outcome['none_solved']??false) && (bool)($outcome['prerequisite_split_possible']??false) => 'too_hard',
            (bool)($outcome['current_primitives_insufficient']??false) => 'unlearnable_with_current_primitives',
            (bool)($outcome['transfer_solved']??false) => 'solved_by_transfer',
            (bool)($outcome['new_failure']??false) => 'reveals_new_failure',
            default => 'learnable_now',
        };
        DB::table('research_challenge_archive')->where('id',$row->id)->update(['status'=>$next,'evidence'=>json_encode(['protocol'=>self::PROTOCOL,'outcome'=>$outcome,'promotion_evidence'=>false]),'updated_at'=>now()]);
        return ['protocol'=>self::PROTOCOL,'status'=>$next,'next_action'=>match($next){'too_easy'=>'increase_complexity','too_hard'=>'split_prerequisites','solved_by_transfer'=>'open_transfer_receipt','reveals_new_failure'=>'open_instrument_synthesis_task',default=>'retain_challenge'},'promotion_evidence'=>false];
    }

    private function hash(mixed $value):string { return hash('sha256',json_encode($this->canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)); }
    private function canonicalize(mixed $value):mixed { if(!is_array($value))return $value; if(!array_is_list($value))ksort($value); foreach($value as $key=>$item)$value[$key]=$this->canonicalize($item); return $value; }
    private function unavailable():array{return ['protocol'=>self::PROTOCOL,'status'=>'migration_pending','promotion_evidence'=>false];}
    private function blocked(string $reason):array{return ['protocol'=>self::PROTOCOL,'status'=>'blocked','reason'=>$reason,'promotion_evidence'=>false];}
}
