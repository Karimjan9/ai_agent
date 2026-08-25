<?php

namespace App\Services;

/** Explicit session handoff and news re-entry contracts; unknown states fail closed. */
class SessionNewsStateMachineService
{
    public const PROTOCOL = 'session_news_state_machine_v1';

    /** @return array<string,mixed> */
    public function compile(array $context = []): array
    {
        $session = (string) ($context['session_state'] ?? 'unclassified');
        $news = (string) ($context['news_state'] ?? 'normal');
        $sessionStates = ['range_building', 'range_sealed', 'asia_sweep', 'false_break', 'accepted_break', 'expansion', 'continuation', 'reversal', 'target_delivery', 'late_session_decay', 'unclassified'];
        $newsStates = ['normal', 'pre_event_quarantine', 'event_spike', 'spread_dislocated', 'spread_normalizing', 'M5_structure_stabilized', 'reentry_allowed'];
        if (! in_array($session, $sessionStates, true)) $session = 'unclassified';
        if (! in_array($news, $newsStates, true)) $news = 'pre_event_quarantine';
        $reentry = $news === 'reentry_allowed'
            && (bool) ($context['spread_normal'] ?? false)
            && (bool) ($context['cooldown_elapsed'] ?? false)
            && (bool) ($context['m5_structure_stabilized'] ?? false)
            && (bool) ($context['execution_trigger'] ?? false)
            && (float) ($context['slippage_estimate'] ?? INF) <= (float) ($context['slippage_limit'] ?? 0);
        $quarantined = $news !== 'normal' && ! $reentry;
        return ['protocol' => self::PROTOCOL, 'session_handoff_state' => $session, 'news_state' => $news, 'news_quarantined' => $quarantined, 'reentry_allowed' => $reentry, 'required_reentry_conditions' => ['spread_normal', 'cooldown_elapsed', 'm5_structure_stabilized', 'execution_trigger', 'slippage_within_limit'], 'ordinary_compositions_cannot_trade_event_spike' => true, 'promotion_evidence' => false];
    }
}
