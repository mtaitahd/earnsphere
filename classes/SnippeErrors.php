<?php
/** Public, fixed wording: never echo arbitrary gateway messages or credentials. */
final class SnippeErrors {
    public static function reason(string $reason): string {
        $reason=strtolower($reason);
        if (str_contains($reason,'insufficient') && (str_contains($reason,'balance') || str_contains($reason,'fund'))) return 'insufficient_balance';
        if (str_contains($reason,'scope') || str_contains($reason,'permission')) return 'insufficient_scope';
        if (str_contains($reason,'timeout') || str_contains($reason,'timed out')) return 'provider_timeout';
        if (str_contains($reason,'invalid') && (str_contains($reason,'phone') || str_contains($reason,'recipient'))) return 'invalid_recipient';
        if (str_contains($reason,'limit')) return 'transfer_limit';
        return 'provider_failed';
    }
    public static function code(int $http,array $data): string {
        $code=is_string($data['error_code']??null)?$data['error_code']:'';
        if ($http===401) return 'unauthorized';
        if ($http===403) return $code==='insufficient_scope'?'insufficient_scope':'forbidden';
        if ($code==='payment_failed' && is_string($data['message']??null)) return self::reason($data['message']);
        return in_array($code,['unauthorized','insufficient_scope','validation_error','not_found','rate_limit_exceeded'],true)?$code:'snippe_error';
    }
    public static function message(string $code): string {
        return match($code) {
            'insufficient_scope'=>'Snippe haijawezeshwa kutuma payout. Wasiliana na support ili ruhusa za withdrawal zirekebishwe.',
            'forbidden'=>'Snippe imekataa ruhusa ya huduma ya malipo. Administrator ahakiki ruhusa za API kwenye Snippe.',
            'unauthorized'=>'Snippe imekataa uthibitisho wa huduma ya malipo. Wasiliana na support.',
            'insufficient_balance'=>'Balance ya kampuni kwenye Snippe haitoshi kwa kiasi pamoja na ada. Wasiliana na support.',
            'invalid_recipient'=>'Snippe imekataa namba ya mpokeaji. Hakiki namba na mtandao kupitia support.',
            'transfer_limit'=>'Kiasi kimezidi kikomo cha huduma ya kutuma pesa. Wasiliana na support.',
            'validation_error'=>'Snippe imekataa taarifa za ombi la payout. Wasiliana na support.',
            'provider_timeout'=>'Mtandao wa malipo haukujibu kwa wakati. Angalia status kabla ya kutuma ombi jingine.',
            'rate_limit_exceeded'=>'Snippe imepokea maombi mengi. Subiri na uangalie status kabla ya kujaribu tena.',
            'provider_failed'=>'Snippe au mtandao wa simu umekataa payout. Support iangalie sababu ya muamala kwenye Snippe.',
            default=>'Sababu halisi haikuhifadhiwa kwa ombi hili. Wasiliana na support na utaje muda na kiasi cha withdrawal.'
        };
    }
}
