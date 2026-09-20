<?php

declare(strict_types=1);

namespace DiscordAutomation\Riot;

final class Analytics
{
    public static function rankEntry(array $entries, string $queueType): ?array
    {
        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['queueType'] ?? null) === $queueType) {
                return [
                    'queue' => $queueType,
                    'tier' => strtoupper((string) ($entry['tier'] ?? '')),
                    'rank' => strtoupper((string) ($entry['rank'] ?? '')),
                    'lp' => (int) ($entry['leaguePoints'] ?? 0),
                    'wins' => (int) ($entry['wins'] ?? 0),
                    'losses' => (int) ($entry['losses'] ?? 0),
                ];
            }
        }

        return null;
    }

    public static function rankLabel(?array $rank): string
    {
        if ($rank === null || ($rank['tier'] ?? '') === '') {
            return 'Unranked';
        }

        $tier = ucfirst(strtolower((string) $rank['tier']));
        $division = trim((string) ($rank['rank'] ?? ''));
        $lp = (int) ($rank['lp'] ?? 0);

        return trim($tier . ' ' . $division) . ' • ' . $lp . ' LP';
    }

    public static function compactLolMatch(array $match, string $puuid): ?array
    {
        $info = is_array($match['info'] ?? null) ? $match['info'] : [];
        $queueId = (int) ($info['queueId'] ?? 0);

        if (!in_array($queueId, [420, 440], true)) {
            return null;
        }

        $participants = is_array($info['participants'] ?? null)
            ? $info['participants']
            : [];

        $participant = null;

        foreach ($participants as $candidate) {
            if (is_array($candidate) && ($candidate['puuid'] ?? null) === $puuid) {
                $participant = $candidate;
                break;
            }
        }

        if ($participant === null) {
            return null;
        }

        $teamId = (int) ($participant['teamId'] ?? 0);
        $teamKills = 0;

        foreach ($participants as $candidate) {
            if (is_array($candidate) && (int) ($candidate['teamId'] ?? 0) === $teamId) {
                $teamKills += (int) ($candidate['kills'] ?? 0);
            }
        }

        $kills = (int) ($participant['kills'] ?? 0);
        $deaths = (int) ($participant['deaths'] ?? 0);
        $assists = (int) ($participant['assists'] ?? 0);
        $duration = max(1, (int) ($info['gameDuration'] ?? 1));
        $minutes = $duration / 60;

        $items = [];
        for ($i = 0; $i <= 6; $i++) {
            $itemId = (int) ($participant['item' . $i] ?? 0);
            if ($itemId > 0) {
                $items[] = $itemId;
            }
        }

        return [
            'id' => (string) ($match['metadata']['matchId'] ?? ''),
            'timestamp' => (int) ($info['gameEndTimestamp'] ?? $info['gameCreation'] ?? 0),
            'queue_id' => $queueId,
            'win' => (bool) ($participant['win'] ?? false),
            'champion' => (string) ($participant['championName'] ?? 'Unknown'),
            'role' => (string) (
                $participant['teamPosition']
                ?? $participant['individualPosition']
                ?? 'UNKNOWN'
            ),
            'kills' => $kills,
            'deaths' => $deaths,
            'assists' => $assists,
            'kda' => ($kills + $assists) / max(1, $deaths),
            'cs_per_min' => (
                (int) ($participant['totalMinionsKilled'] ?? 0)
                + (int) ($participant['neutralMinionsKilled'] ?? 0)
            ) / $minutes,
            'damage_per_min' => (int) ($participant['totalDamageDealtToChampions'] ?? 0) / $minutes,
            'gold_per_min' => (int) ($participant['goldEarned'] ?? 0) / $minutes,
            'vision_per_min' => (int) ($participant['visionScore'] ?? 0) / $minutes,
            'kill_participation' => $teamKills > 0
                ? (($kills + $assists) / $teamKills)
                : 0.0,
            'items' => array_values(array_unique($items)),
        ];
    }

    public static function compactTftMatch(array $match, string $puuid): ?array
    {
        $info = is_array($match['info'] ?? null) ? $match['info'] : [];
        $queueId = (int) ($info['queue_id'] ?? 0);

        if ($queueId !== 1100) {
            return null;
        }

        $participants = is_array($info['participants'] ?? null)
            ? $info['participants']
            : [];

        $participant = null;

        foreach ($participants as $candidate) {
            if (is_array($candidate) && ($candidate['puuid'] ?? null) === $puuid) {
                $participant = $candidate;
                break;
            }
        }

        if ($participant === null) {
            return null;
        }

        $traits = [];

        foreach (($participant['traits'] ?? []) as $trait) {
            if (
                !is_array($trait)
                || (int) ($trait['tier_current'] ?? 0) <= 0
            ) {
                continue;
            }

            $traits[] = [
                'name' => self::prettyTftId((string) ($trait['name'] ?? '')),
                'tier' => (int) ($trait['tier_current'] ?? 0),
                'units' => (int) ($trait['num_units'] ?? 0),
            ];
        }

        usort(
            $traits,
            static fn (array $a, array $b): int
                => [$b['tier'], $b['units']] <=> [$a['tier'], $a['units']]
        );

        $units = [];
        $items = [];

        foreach (($participant['units'] ?? []) as $unit) {
            if (!is_array($unit)) {
                continue;
            }

            $unitItems = [];
            foreach (($unit['itemNames'] ?? []) as $itemName) {
                $pretty = self::prettyTftId((string) $itemName);
                if ($pretty !== '') {
                    $unitItems[] = $pretty;
                    $items[] = $pretty;
                }
            }

            $units[] = [
                'name' => self::prettyTftId((string) ($unit['character_id'] ?? '')),
                'tier' => (int) ($unit['tier'] ?? 1),
                'items' => array_values(array_unique($unitItems)),
            ];
        }

        $signature = array_values(array_filter(array_map(
            static fn (array $trait): string
                => $trait['name'] . ' ' . $trait['units'],
            array_slice($traits, 0, 3)
        )));

        if ($signature === []) {
            $signature = array_values(array_filter(array_map(
                static fn (array $unit): string => $unit['name'],
                array_slice($units, 0, 3)
            )));
        }

        return [
            'id' => (string) ($match['metadata']['match_id'] ?? ''),
            'timestamp' => (int) ($info['game_datetime'] ?? 0),
            'queue_id' => $queueId,
            'placement' => (int) ($participant['placement'] ?? 8),
            'level' => (int) ($participant['level'] ?? 0),
            'players_eliminated' => (int) ($participant['players_eliminated'] ?? 0),
            'gold_left' => (int) ($participant['gold_left'] ?? 0),
            'last_round' => (int) ($participant['last_round'] ?? 0),
            'comp' => implode(' + ', $signature),
            'traits' => $traits,
            'units' => $units,
            'items' => array_values(array_unique($items)),
        ];
    }

    public static function lolSummary(array $matches, int $limit = 20): array
    {
        $matches = array_slice($matches, 0, $limit);
        $games = count($matches);

        if ($games === 0) {
            return ['games' => 0];
        }

        $wins = count(array_filter($matches, static fn (array $m): bool => (bool) ($m['win'] ?? false)));

        $sum = static function (string $field) use ($matches): float {
            return array_sum(array_map(
                static fn (array $m): float => (float) ($m[$field] ?? 0),
                $matches
            ));
        };

        $champions = [];

        foreach ($matches as $match) {
            $champion = (string) ($match['champion'] ?? 'Unknown');
            $champions[$champion] ??= ['games' => 0, 'wins' => 0, 'kda' => 0.0];
            $champions[$champion]['games']++;
            $champions[$champion]['wins'] += (bool) ($match['win'] ?? false) ? 1 : 0;
            $champions[$champion]['kda'] += (float) ($match['kda'] ?? 0);
        }

        foreach ($champions as &$champion) {
            $champion['winrate'] = $champion['games'] > 0
                ? $champion['wins'] / $champion['games']
                : 0;
            $champion['avg_kda'] = $champion['games'] > 0
                ? $champion['kda'] / $champion['games']
                : 0;
        }
        unset($champion);

        uasort(
            $champions,
            static fn (array $a, array $b): int
                => [$b['games'], $b['winrate']] <=> [$a['games'], $a['winrate']]
        );

        return [
            'games' => $games,
            'wins' => $wins,
            'losses' => $games - $wins,
            'winrate' => $wins / $games,
            'avg_kda' => $sum('kda') / $games,
            'cs_per_min' => $sum('cs_per_min') / $games,
            'damage_per_min' => $sum('damage_per_min') / $games,
            'gold_per_min' => $sum('gold_per_min') / $games,
            'vision_per_min' => $sum('vision_per_min') / $games,
            'kill_participation' => $sum('kill_participation') / $games,
            'champions' => $champions,
        ];
    }

    public static function tftSummary(array $matches, int $limit = 20): array
    {
        $matches = array_slice($matches, 0, $limit);
        $games = count($matches);

        if ($games === 0) {
            return ['games' => 0];
        }

        $top4 = 0;
        $first = 0;
        $placementSum = 0;
        $comps = [];
        $items = [];

        foreach ($matches as $match) {
            $placement = (int) ($match['placement'] ?? 8);
            $isTop4 = $placement <= 4;
            $isFirst = $placement === 1;

            $top4 += $isTop4 ? 1 : 0;
            $first += $isFirst ? 1 : 0;
            $placementSum += $placement;

            $comp = trim((string) ($match['comp'] ?? ''));
            if ($comp !== '') {
                $comps[$comp] ??= ['games' => 0, 'top4' => 0, 'first' => 0, 'placement' => 0];
                $comps[$comp]['games']++;
                $comps[$comp]['top4'] += $isTop4 ? 1 : 0;
                $comps[$comp]['first'] += $isFirst ? 1 : 0;
                $comps[$comp]['placement'] += $placement;
            }

            foreach (array_values(array_unique($match['items'] ?? [])) as $item) {
                $item = (string) $item;
                if ($item === '') {
                    continue;
                }

                $items[$item] ??= ['games' => 0, 'top4' => 0, 'first' => 0, 'placement' => 0];
                $items[$item]['games']++;
                $items[$item]['top4'] += $isTop4 ? 1 : 0;
                $items[$item]['first'] += $isFirst ? 1 : 0;
                $items[$item]['placement'] += $placement;
            }
        }

        $finish = static function (array &$rows): void {
            foreach ($rows as &$row) {
                $row['top4_rate'] = $row['games'] > 0 ? $row['top4'] / $row['games'] : 0;
                $row['first_rate'] = $row['games'] > 0 ? $row['first'] / $row['games'] : 0;
                $row['avg_placement'] = $row['games'] > 0 ? $row['placement'] / $row['games'] : 0;
            }
            unset($row);

            uasort(
                $rows,
                static fn (array $a, array $b): int
                    => [$b['games'], $b['top4_rate']] <=> [$a['games'], $a['top4_rate']]
            );
        };

        $finish($comps);
        $finish($items);

        return [
            'games' => $games,
            'top4' => $top4,
            'first' => $first,
            'top4_rate' => $top4 / $games,
            'first_rate' => $first / $games,
            'avg_placement' => $placementSum / $games,
            'comps' => $comps,
            'items' => $items,
        ];
    }

    public static function rankDirection(?array $before, ?array $after): string
    {
        if ($before === null || $after === null) {
            return '•';
        }

        $a = self::rankScore($before);
        $b = self::rankScore($after);

        return match (true) {
            $b > $a => '↑',
            $b < $a => '↓',
            default => '→',
        };
    }

    private static function rankScore(array $rank): int
    {
        $tiers = [
            'IRON' => 0,
            'BRONZE' => 1,
            'SILVER' => 2,
            'GOLD' => 3,
            'PLATINUM' => 4,
            'EMERALD' => 5,
            'DIAMOND' => 6,
            'MASTER' => 7,
            'GRANDMASTER' => 8,
            'CHALLENGER' => 9,
        ];

        $divisions = ['IV' => 0, 'III' => 1, 'II' => 2, 'I' => 3];

        return (($tiers[(string) ($rank['tier'] ?? '')] ?? 0) * 400)
            + (($divisions[(string) ($rank['rank'] ?? '')] ?? 0) * 100)
            + (int) ($rank['lp'] ?? 0);
    }

    private static function prettyTftId(string $value): string
    {
        $value = preg_replace('/^TFT\d*_?/i', '', $value) ?? $value;
        $value = preg_replace('/^Set\d+_/i', '', $value) ?? $value;
        $value = preg_replace('/^(Item_|Trait_|Augment_)/i', '', $value) ?? $value;
        $value = str_replace('_', ' ', $value);
        $value = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $value) ?? $value;

        return trim($value);
    }
}
