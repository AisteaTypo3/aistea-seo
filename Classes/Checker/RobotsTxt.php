<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker;

/**
 * Minimal robots.txt parser following RFC 9309: user-agent groups,
 * Allow/Disallow with "*" and "$" wildcards, longest match wins, Allow wins ties.
 */
final class RobotsTxt
{
    /** @var array<string, list<array{allow: bool, pattern: string}>> */
    private array $groups = [];

    /** @var list<string> */
    private array $sitemaps = [];

    public function __construct(string $content)
    {
        $agents = [];
        $lastWasRule = false;

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                if ($value !== '') {
                    $this->sitemaps[] = $value;
                }
                continue;
            }

            if ($field === 'user-agent') {
                if ($lastWasRule) {
                    $agents = [];
                }
                $agent = strtolower($value);
                $agents[] = $agent;
                $this->groups[$agent] ??= [];
                $lastWasRule = false;
                continue;
            }

            if (in_array($field, ['allow', 'disallow'], true)) {
                $lastWasRule = true;
                if ($value === '' && $field === 'disallow') {
                    continue; // "Disallow:" means allow everything
                }
                foreach ($agents as $agent) {
                    $this->groups[$agent][] = ['allow' => $field === 'allow', 'pattern' => $value];
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    public function getSitemaps(): array
    {
        return array_values(array_unique($this->sitemaps));
    }

    public function hasGroups(): bool
    {
        return $this->groups !== [];
    }

    public function isAllowed(string $url, string $userAgent = '*'): bool
    {
        $rules = $this->rulesFor($userAgent);
        if ($rules === []) {
            return true;
        }

        $path = UrlTools::pathWithQuery($url);
        $bestLength = -1;
        $allowed = true;
        foreach ($rules as $rule) {
            if (!$this->matches($rule['pattern'], $path)) {
                continue;
            }
            $length = strlen($rule['pattern']);
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    /**
     * True when the agent's group explicitly disallows the whole site.
     */
    public function blocksEverything(string $userAgent): bool
    {
        return !$this->isAllowed('https://example.org/', $userAgent)
            && !$this->isAllowed('https://example.org/some-page', $userAgent);
    }

    /**
     * True when a dedicated group (not the "*" fallback) exists for the agent.
     */
    public function hasExplicitGroup(string $userAgent): bool
    {
        return isset($this->groups[strtolower($userAgent)]);
    }

    /**
     * @return list<array{allow: bool, pattern: string}>
     */
    private function rulesFor(string $userAgent): array
    {
        $userAgent = strtolower($userAgent);
        foreach ($this->groups as $agent => $rules) {
            if ($agent !== '*' && $agent !== '' && str_contains($userAgent, $agent)) {
                return $rules;
            }
        }

        return $this->groups['*'] ?? [];
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = rtrim($pattern, '$');
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';

        return preg_match($regex, $path) === 1;
    }
}
