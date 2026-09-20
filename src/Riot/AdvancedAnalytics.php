<?php

declare(strict_types=1);

namespace DiscordAutomation\Riot;

final class AdvancedAnalytics
{
    public static function lolTimelineSnapshot(
        array $match,
        array $timeline,
        string $puuid,
        array $itemNames
    ): array {
        $info = is_array($match['info'] ?? null) ? $match['info'] : [];
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
            return [];
        }

        $participantId = (int) ($participant['participantId'] ?? 0);
        $teamId = (int) ($participant['teamId'] ?? 0);
        $role = (string) (
            $participant['teamPosition']
            ?? $participant['individualPosition']
            ?? 'UNKNOWN'
        );

        $opponentId = 0;

        if ($role !== '' && $role !== 'UNKNOWN') {
            foreach ($participants as $candidate) {
                if (
                    !is_array($candidate)
                    || (int) ($candidate['teamId'] ?? 0) === $teamId
                ) {
                    continue;
                }

                $candidateRole = (string) (
                    $candidate['teamPosition']
                    ?? $candidate['individualPosition']
                    ?? 'UNKNOWN'
                );

                if ($candidateRole === $role) {
                    $opponentId = (int) ($candidate['participantId'] ?? 0);
                    break;
                }
            }
        }

        $frames = is_array($timeline['info']['frames'] ?? null)
            ? $timeline['info']['frames']
            : [];

        $checkpoints = [];

        foreach ([10, 15, 20] as $minute) {
            $frame = self::nearestFrame($frames, $minute * 60 * 1000);

            if ($frame === null) {
                continue;
            }

            $participantFrames = is_array($frame['participantFrames'] ?? null)
                ? $frame['participantFrames']
                : [];

            $own = is_array($participantFrames[(string) $participantId] ?? null)
                ? $participantFrames[(string) $participantId]
                : null;

            if ($own === null) {
                continue;
            }

            $enemy = $opponentId > 0
                && is_array($participantFrames[(string) $opponentId] ?? null)
                    ? $participantFrames[(string) $opponentId]
                    : null;

            $ownCs = (int) ($own['minionsKilled'] ?? 0)
                + (int) ($own['jungleMinionsKilled'] ?? 0);

            $checkpoint = [
                'gold' => (int) ($own['totalGold'] ?? 0),
                'cs' => $ownCs,
                'xp' => (int) ($own['xp'] ?? 0),
            ];

            if ($enemy !== null) {
                $enemyCs = (int) ($enemy['minionsKilled'] ?? 0)
                    + (int) ($enemy['jungleMinionsKilled'] ?? 0);

                $checkpoint['gold_diff'] = (int) ($own['totalGold'] ?? 0)
                    - (int) ($enemy['totalGold'] ?? 0);
                $checkpoint['cs_diff'] = $ownCs - $enemyCs;
                $checkpoint['xp_diff'] = (int) ($own['xp'] ?? 0)
                    - (int) ($enemy['xp'] ?? 0);
            }

            $checkpoints[(string) $minute] = $checkpoint;
        }

        $kills10 = 0;
        $deaths10 = 0;
        $assists10 = 0;
        $objectives = [
            'dragon' => 0,
            'baron' => 0,
            'herald' => 0,
        ];

        $finalItemIds = [];
        for ($slot = 0; $slot <= 6; $slot++) {
            $itemId = (int) ($participant['item' . $slot] ?? 0);
            if ($itemId > 0) {
                $finalItemIds[] = $itemId;
            }
        }
        $finalItemIds = array_values(array_unique($finalItemIds));

        $itemPurchasedAt = [];

        foreach ($frames as $frame) {
            if (!is_array($frame)) {
                continue;
            }

            foreach (($frame['events'] ?? []) as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $type = (string) ($event['type'] ?? '');
                $timestamp = (int) ($event['timestamp'] ?? 0);

                if ($type === 'CHAMPION_KILL' && $timestamp <= 600000) {
                    if ((int) ($event['killerId'] ?? 0) === $participantId) {
                        $kills10++;
                    }

                    if ((int) ($event['victimId'] ?? 0) === $participantId) {
                        $deaths10++;
                    }

                    $assists = is_array($event['assistingParticipantIds'] ?? null)
                        ? $event['assistingParticipantIds']
                        : [];

                    if (in_array($participantId, $assists, true)) {
                        $assists10++;
                    }
                }

                if ($type === 'ELITE_MONSTER_KILL') {
                    $assists = is_array($event['assistingParticipantIds'] ?? null)
                        ? $event['assistingParticipantIds']
                        : [];

                    $participated = (int) ($event['killerId'] ?? 0) === $participantId
                        || in_array($participantId, $assists, true);

                    if ($participated) {
                        $monster = strtoupper((string) ($event['monsterType'] ?? ''));

                        if ($monster === 'DRAGON') {
                            $objectives['dragon']++;
                        } elseif ($monster === 'BARON_NASHOR') {
                            $objectives['baron']++;
                        } elseif ($monster === 'RIFTHERALD') {
                            $objectives['herald']++;
                        }
                    }
                }

                if (
                    $type === 'ITEM_PURCHASED'
                    && (int) ($event['participantId'] ?? 0) === $participantId
                ) {
                    $itemId = (int) ($event['itemId'] ?? 0);

                    if (
                        in_array($itemId, $finalItemIds, true)
                        && !isset($itemPurchasedAt[$itemId])
                    ) {
                        $itemPurchasedAt[$itemId] = $timestamp;
                    }
                }
            }
        }

        $finalItems = [];

        foreach ($finalItemIds as $itemId) {
            $row = [
                'id' => $itemId,
                'name' => (string) ($itemNames[$itemId] ?? ('Item ' . $itemId)),
            ];

            if (isset($itemPurchasedAt[$itemId])) {
                $row['minute'] = round($itemPurchasedAt[$itemId] / 60000, 1);
            }

            $finalItems[] = $row;
        }

        return [
            'side' => $teamId === 100 ? 'BLUE' : ($teamId === 200 ? 'RED' : 'UNKNOWN'),
            'duration_min' => round(max(1, (int) ($info['gameDuration'] ?? 1)) / 60, 1),
            'checkpoints' => $checkpoints,
            'early' => [
                'kills_10' => $kills10,
                'deaths_10' => $deaths10,
                'assists_10' => $assists10,
            ],
            'objectives' => $objectives,
            'final_items' => $finalItems,
        ];
    }

    public static function lolAdvancedSummary(array $matches, int $limit = 20): array
    {
        $matches = array_slice($matches, 0, $limit);

        $checkpointSums = [];
        $checkpointCounts = [];
        $roles = [];
        $sides = [];
        $items = [];
        $earlyDeaths = 0;
        $earlyKills = 0;
        $earlyAssists = 0;
        $timelineGames = 0;
        $objectives = ['dragon' => 0, 'baron' => 0, 'herald' => 0];
        $ahead15 = ['games' => 0, 'wins' => 0];
        $behind15 = ['games' => 0, 'wins' => 0];

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $advanced = is_array($match['advanced'] ?? null)
                ? $match['advanced']
                : [];

            $role = strtoupper((string) ($match['role'] ?? 'UNKNOWN'));
            if ($role !== '' && $role !== 'UNKNOWN' && $role !== 'ARAM') {
                $roles[$role] ??= ['games' => 0, 'wins' => 0, 'kda' => 0.0];
                $roles[$role]['games']++;
                $roles[$role]['wins'] += (bool) ($match['win'] ?? false) ? 1 : 0;
                $roles[$role]['kda'] += (float) ($match['kda'] ?? 0);
            }

            $side = strtoupper((string) ($advanced['side'] ?? 'UNKNOWN'));
            if (in_array($side, ['BLUE', 'RED'], true)) {
                $sides[$side] ??= ['games' => 0, 'wins' => 0];
                $sides[$side]['games']++;
                $sides[$side]['wins'] += (bool) ($match['win'] ?? false) ? 1 : 0;
            }

            $checkpoints = is_array($advanced['checkpoints'] ?? null)
                ? $advanced['checkpoints']
                : [];

            if ($checkpoints !== []) {
                $timelineGames++;
            }

            foreach ([10, 15, 20] as $minute) {
                $point = is_array($checkpoints[(string) $minute] ?? null)
                    ? $checkpoints[(string) $minute]
                    : [];

                foreach (['gold_diff', 'cs_diff', 'xp_diff'] as $metric) {
                    if (!array_key_exists($metric, $point)) {
                        continue;
                    }

                    $checkpointSums[$minute][$metric] =
                        ($checkpointSums[$minute][$metric] ?? 0)
                        + (float) $point[$metric];

                    $checkpointCounts[$minute][$metric] =
                        ($checkpointCounts[$minute][$metric] ?? 0) + 1;
                }
            }

            $gold15 = $checkpoints['15']['gold_diff'] ?? null;
            if (is_numeric($gold15)) {
                if ((float) $gold15 >= 0) {
                    $ahead15['games']++;
                    $ahead15['wins'] += (bool) ($match['win'] ?? false) ? 1 : 0;
                } else {
                    $behind15['games']++;
                    $behind15['wins'] += (bool) ($match['win'] ?? false) ? 1 : 0;
                }
            }

            $early = is_array($advanced['early'] ?? null)
                ? $advanced['early']
                : [];

            $earlyDeaths += (int) ($early['deaths_10'] ?? 0);
            $earlyKills += (int) ($early['kills_10'] ?? 0);
            $earlyAssists += (int) ($early['assists_10'] ?? 0);

            $matchObjectives = is_array($advanced['objectives'] ?? null)
                ? $advanced['objectives']
                : [];

            foreach (array_keys($objectives) as $objective) {
                $objectives[$objective] += (int) ($matchObjectives[$objective] ?? 0);
            }

            $seenItems = [];

            foreach (($advanced['final_items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '' || isset($seenItems[$name])) {
                    continue;
                }
                $seenItems[$name] = true;

                $items[$name] ??= [
                    'games' => 0,
                    'wins' => 0,
                    'timing_sum' => 0.0,
                    'timing_count' => 0,
                ];

                $items[$name]['games']++;
                $items[$name]['wins'] += (bool) ($match['win'] ?? false) ? 1 : 0;

                if (isset($item['minute']) && is_numeric($item['minute'])) {
                    $items[$name]['timing_sum'] += (float) $item['minute'];
                    $items[$name]['timing_count']++;
                }
            }
        }

        foreach ($roles as &$role) {
            $role['winrate'] = $role['games'] > 0 ? $role['wins'] / $role['games'] : 0;
            $role['avg_kda'] = $role['games'] > 0 ? $role['kda'] / $role['games'] : 0;
        }
        unset($role);

        uasort(
            $roles,
            static fn (array $a, array $b): int => $b['games'] <=> $a['games']
        );

        foreach ($sides as &$side) {
            $side['winrate'] = $side['games'] > 0 ? $side['wins'] / $side['games'] : 0;
        }
        unset($side);

        foreach ($items as &$item) {
            $item['winrate'] = $item['games'] > 0 ? $item['wins'] / $item['games'] : 0;
            $item['avg_timing'] = $item['timing_count'] > 0
                ? $item['timing_sum'] / $item['timing_count']
                : null;
        }
        unset($item);

        uasort(
            $items,
            static fn (array $a, array $b): int
                => [$b['games'], $b['wins']] <=> [$a['games'], $a['wins']]
        );

        $checkpoints = [];

        foreach ([10, 15, 20] as $minute) {
            foreach (['gold_diff', 'cs_diff', 'xp_diff'] as $metric) {
                $count = (int) ($checkpointCounts[$minute][$metric] ?? 0);

                if ($count > 0) {
                    $checkpoints[(string) $minute][$metric] =
                        ($checkpointSums[$minute][$metric] ?? 0) / $count;
                }
            }
        }

        $games = count($matches);

        return [
            'games' => $games,
            'timeline_games' => $timelineGames,
            'checkpoints' => $checkpoints,
            'early' => [
                'kills_10_per_game' => $timelineGames > 0 ? $earlyKills / $timelineGames : 0,
                'deaths_10_per_game' => $timelineGames > 0 ? $earlyDeaths / $timelineGames : 0,
                'assists_10_per_game' => $timelineGames > 0 ? $earlyAssists / $timelineGames : 0,
            ],
            'objectives' => [
                'dragon_per_game' => $timelineGames > 0 ? $objectives['dragon'] / $timelineGames : 0,
                'baron_per_game' => $timelineGames > 0 ? $objectives['baron'] / $timelineGames : 0,
                'herald_per_game' => $timelineGames > 0 ? $objectives['herald'] / $timelineGames : 0,
            ],
            'ahead_15' => [
                'games' => $ahead15['games'],
                'winrate' => $ahead15['games'] > 0 ? $ahead15['wins'] / $ahead15['games'] : null,
            ],
            'behind_15' => [
                'games' => $behind15['games'],
                'winrate' => $behind15['games'] > 0 ? $behind15['wins'] / $behind15['games'] : null,
            ],
            'roles' => $roles,
            'sides' => $sides,
            'items' => $items,
        ];
    }

    public static function tftAdvancedSummary(array $matches, int $limit = 50): array
    {
        $matches = array_slice($matches, 0, $limit);
        $carries = [];
        $pairs = [];

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $placement = (int) ($match['placement'] ?? 8);
            $top4 = $placement <= 4;
            $first = $placement === 1;
            $units = is_array($match['units'] ?? null) ? $match['units'] : [];

            $carry = null;

            foreach ($units as $unit) {
                if (!is_array($unit)) {
                    continue;
                }

                $items = array_values(array_filter(
                    is_array($unit['items'] ?? null) ? $unit['items'] : [],
                    static fn (mixed $item): bool => trim((string) $item) !== ''
                ));

                $candidate = [
                    'name' => trim((string) ($unit['name'] ?? '')),
                    'tier' => (int) ($unit['tier'] ?? 1),
                    'items' => $items,
                    'item_count' => count($items),
                ];

                if (
                    $candidate['name'] !== ''
                    && (
                        $carry === null
                        || [$candidate['item_count'], $candidate['tier']]
                            > [$carry['item_count'], $carry['tier']]
                    )
                ) {
                    $carry = $candidate;
                }

                foreach (array_values(array_unique($items)) as $item) {
                    $key = $candidate['name'] . ' + ' . $item;
                    $pairs[$key] ??= [
                        'games' => 0,
                        'top4' => 0,
                        'first' => 0,
                        'placement' => 0,
                    ];
                    $pairs[$key]['games']++;
                    $pairs[$key]['top4'] += $top4 ? 1 : 0;
                    $pairs[$key]['first'] += $first ? 1 : 0;
                    $pairs[$key]['placement'] += $placement;
                }
            }

            if ($carry !== null && $carry['item_count'] >= 2) {
                $name = $carry['name'];
                $carries[$name] ??= [
                    'games' => 0,
                    'top4' => 0,
                    'first' => 0,
                    'placement' => 0,
                    'three_star' => 0,
                ];
                $carries[$name]['games']++;
                $carries[$name]['top4'] += $top4 ? 1 : 0;
                $carries[$name]['first'] += $first ? 1 : 0;
                $carries[$name]['placement'] += $placement;
                $carries[$name]['three_star'] += $carry['tier'] >= 3 ? 1 : 0;
            }
        }

        $finish = static function (array &$rows): void {
            foreach ($rows as &$row) {
                $games = max(1, (int) ($row['games'] ?? 0));
                $row['top4_rate'] = (int) ($row['top4'] ?? 0) / $games;
                $row['first_rate'] = (int) ($row['first'] ?? 0) / $games;
                $row['avg_placement'] = (int) ($row['placement'] ?? 0) / $games;
            }
            unset($row);

            uasort(
                $rows,
                static fn (array $a, array $b): int
                    => [$b['games'], $b['top4_rate']] <=> [$a['games'], $a['top4_rate']]
            );
        };

        $finish($carries);
        $finish($pairs);

        return [
            'games' => count($matches),
            'carries' => $carries,
            'item_pairs' => $pairs,
        ];
    }

    private static function nearestFrame(array $frames, int $targetMs): ?array
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($frames as $frame) {
            if (!is_array($frame)) {
                continue;
            }

            $timestamp = (int) ($frame['timestamp'] ?? 0);
            $distance = abs($timestamp - $targetMs);

            if ($distance < $bestDistance) {
                $best = $frame;
                $bestDistance = $distance;
            }
        }

        return $bestDistance <= 90000 ? $best : null;
    }
}
