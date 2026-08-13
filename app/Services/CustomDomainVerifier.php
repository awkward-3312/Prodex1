<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Verifies that a user-supplied custom domain is correctly pointed at the
 * central app host. Accepts a CNAME pointing at the central host, or A/AAAA
 * records matching the central host's resolved IPs. TXT verification on a
 * `_saas-verify.<domain>` record is supported as a fallback for cases where
 * CNAME cannot be used on the apex (e.g. some DNS providers).
 */
class CustomDomainVerifier
{
    public const RESULT_OK          = 'ok';
    public const RESULT_MISMATCH    = 'mismatch';
    public const RESULT_NO_RECORDS  = 'no_records';
    public const RESULT_LOOKUP_FAIL = 'lookup_failed';

    public function __construct(private readonly ?string $centralHost = null)
    {
    }

    /**
     * Returns ['ok' => bool, 'reason' => string, 'records' => array].
     * $token is the domain's verification token. If a matching TXT record is
     * found, verification passes even if CNAME/A are still propagating.
     */
    public function verify(string $domain, string $token): array
    {
        $domain  = strtolower(trim($domain));
        $central = strtolower($this->centralHost ?: $this->resolveCentralHost());

        if ($domain === '' || $central === '') {
            return ['ok' => false, 'reason' => self::RESULT_LOOKUP_FAIL, 'records' => []];
        }

        // 1) TXT verification on _saas-verify.<domain>
        $txt = $this->safeDnsRecord('_saas-verify.' . $domain, DNS_TXT);
        $txtValues = array_map(static fn ($r) => $r['txt'] ?? '', $txt);
        if (in_array($token, $txtValues, true)) {
            return ['ok' => true, 'reason' => self::RESULT_OK, 'records' => ['txt' => $txtValues]];
        }

        // 2) CNAME pointing at central host
        $cname = $this->safeDnsRecord($domain, DNS_CNAME);
        foreach ($cname as $r) {
            $target = strtolower(rtrim($r['target'] ?? '', '.'));
            if ($target === $central) {
                return ['ok' => true, 'reason' => self::RESULT_OK, 'records' => ['cname' => $target]];
            }
        }

        // 3) A/AAAA match against central's resolved IPs
        $centralIps = $this->resolveIps($central);
        $aRecords   = $this->safeDnsRecord($domain, DNS_A + DNS_AAAA);
        $domainIps  = [];
        foreach ($aRecords as $r) {
            if (! empty($r['ip']))   { $domainIps[] = $r['ip']; }
            if (! empty($r['ipv6'])) { $domainIps[] = $r['ipv6']; }
        }

        if ($domainIps && array_intersect($domainIps, $centralIps)) {
            return ['ok' => true, 'reason' => self::RESULT_OK, 'records' => ['a' => $domainIps]];
        }

        if (! $cname && ! $domainIps && ! $txtValues) {
            return ['ok' => false, 'reason' => self::RESULT_NO_RECORDS, 'records' => []];
        }

        return [
            'ok'      => false,
            'reason'  => self::RESULT_MISMATCH,
            'records' => [
                'cname' => array_map(static fn ($r) => $r['target'] ?? '', $cname),
                'a'     => $domainIps,
                'txt'   => $txtValues,
            ],
        ];
    }

    public function centralHost(): string
    {
        return $this->centralHost ?: $this->resolveCentralHost();
    }

    public function generateToken(): string
    {
        return 'saas-verify-' . bin2hex(random_bytes(16));
    }

    private function resolveCentralHost(): string
    {
        $host = parse_url(config('app.url', ''), PHP_URL_HOST);
        return $host ? strtolower($host) : '';
    }

    /** @return array<int, array<string, mixed>> */
    private function safeDnsRecord(string $host, int $type): array
    {
        try {
            $res = @dns_get_record($host, $type);
            return is_array($res) ? $res : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    private function resolveIps(string $host): array
    {
        try {
            $ips = @gethostbynamel($host);
            return is_array($ips) ? $ips : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
