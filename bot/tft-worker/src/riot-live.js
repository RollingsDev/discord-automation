const COLORS = {
  blue: 0x3498db,
  green: 0x2ecc71,
  gold: 0xf1c40f,
  purple: 0x7c4dff,
  red: 0xe74c3c
};

export async function handleLolLive(subcommand, env) {
  if (!env.RIOT_API_KEY) {
    return errorResponse("RIOT_API_KEY não está configurada no Worker.");
  }

  const riotId = optionValue(subcommand, "riot_id");
  const account = await accountByRiotId(riotId, env.RIOT_API_KEY, env);

  if (subcommand?.name === "maestria") {
    return renderMastery(account, env);
  }

  if (subcommand?.name === "partida") {
    return renderLatestLolMatch(account, env);
  }

  return renderLolPlayer(account, env);
}

export async function handleTftLive(subcommand, env) {
  if (!env.RIOT_API_KEY) {
    return errorResponse("RIOT_API_KEY não está configurada para resolver o Riot ID.");
  }

  if (!env.RIOT_TFT_API_KEY) {
    return errorResponse(
      "A chave exclusiva de TFT ainda não foi configurada. Adicione RIOT_TFT_API_KEY no Worker."
    );
  }

  const riotId = optionValue(subcommand, "riot_id");
  const account = await accountByRiotId(riotId, env.RIOT_API_KEY, env);

  if (subcommand?.name === "partida") {
    return renderLatestTftMatch(account, env);
  }

  return renderTftPlayer(account, env);
}

async function renderLolPlayer(account, env) {
  const [ranks, matchIds, masteries, championNames] = await Promise.all([
    riotGet(platformUrl(env, `/lol/league/v4/entries/by-puuid/${encodeURIComponent(account.puuid)}`), env.RIOT_API_KEY),
    riotGet(regionalUrl(env, `/lol/match/v5/matches/by-puuid/${encodeURIComponent(account.puuid)}/ids?start=0&count=5&type=ranked`), env.RIOT_API_KEY),
    riotGet(platformUrl(env, `/lol/champion-mastery/v4/champion-masteries/by-puuid/${encodeURIComponent(account.puuid)}/top?count=5`), env.RIOT_API_KEY),
    championMap()
  ]);

  const matches = await Promise.all(
    (Array.isArray(matchIds) ? matchIds.slice(0, 5) : []).map(id =>
      riotGet(regionalUrl(env, `/lol/match/v5/matches/${encodeURIComponent(id)}`), env.RIOT_API_KEY)
    )
  );

  const solo = rankEntry(ranks, "RANKED_SOLO_5x5");
  const flex = rankEntry(ranks, "RANKED_FLEX_SR");
  const recent = summarizeLolMatches(matches, account.puuid);

  const fields = [
    {
      name: "🏆 Solo/Duo",
      value: rankLabel(solo),
      inline: true
    },
    {
      name: "🛡️ Flex",
      value: rankLabel(flex),
      inline: true
    },
    {
      name: "📊 Últimas ranqueadas",
      value: recent.games
        ? `${recent.wins}W / ${recent.losses}L • **${pct(recent.winRate)} WR**\nKDA médio **${num(recent.kda, 2)}** • ${recent.games} jogo(s)`
        : "Sem partidas ranqueadas recentes.",
      inline: false
    }
  ];

  if (recent.champions.length) {
    fields.push({
      name: "🎯 Campeões recentes",
      value: recent.champions.map(row => `**${row.name}** — ${row.games}j • ${row.wins}W`).join("\n"),
      inline: false
    });
  }

  if (Array.isArray(masteries) && masteries.length) {
    fields.push({
      name: "⭐ Maestrias",
      value: masteries.slice(0, 5).map(entry => {
        const name = championNames[String(entry.championId)] ?? `Champion ${entry.championId}`;
        return `**${name}** — Nv. ${entry.championLevel ?? "?"} • ${Number(entry.championPoints ?? 0).toLocaleString("pt-BR")} pts`;
      }).join("\n"),
      inline: false
    });
  }

  return embedResponse({
    title: `⚔️ ${riotId(account)}`,
    color: COLORS.blue,
    fields,
    footer: "Dados ao vivo: Riot API • League of Legends"
  });
}

async function renderLatestLolMatch(account, env) {
  const ids = await riotGet(
    regionalUrl(env, `/lol/match/v5/matches/by-puuid/${encodeURIComponent(account.puuid)}/ids?start=0&count=1&type=ranked`),
    env.RIOT_API_KEY
  );

  const id = Array.isArray(ids) ? ids[0] : null;
  if (!id) return errorResponse("Não encontrei uma partida ranqueada recente para esse Riot ID.");

  const match = await riotGet(
    regionalUrl(env, `/lol/match/v5/matches/${encodeURIComponent(id)}`),
    env.RIOT_API_KEY
  );
  const participant = participantByPuuid(match, account.puuid);

  if (!participant) return errorResponse("A Riot retornou a partida, mas não encontrei o jogador nela.");

  const durationMin = Number(match?.info?.gameDuration ?? 0) / 60;
  const cs = Number(participant.totalMinionsKilled ?? 0) + Number(participant.neutralMinionsKilled ?? 0);

  return embedResponse({
    title: `${participant.win ? "✅ Vitória" : "❌ Derrota"} • ${riotId(account)}`,
    color: participant.win ? COLORS.green : COLORS.red,
    description: `**${participant.championName ?? "Campeão"}** • ${participant.teamPosition || participant.individualPosition || "role não informada"}`,
    fields: [
      {
        name: "⚔️ KDA",
        value: `**${participant.kills ?? 0}/${participant.deaths ?? 0}/${participant.assists ?? 0}** • ${num(kda(participant), 2)}`,
        inline: true
      },
      {
        name: "💰 Farm",
        value: `${cs} CS • ${durationMin > 0 ? `${num(cs / durationMin, 1)} CS/min` : "—"}`,
        inline: true
      },
      {
        name: "💥 Dano",
        value: Number(participant.totalDamageDealtToChampions ?? 0).toLocaleString("pt-BR"),
        inline: true
      },
      {
        name: "👁️ Visão",
        value: `${participant.visionScore ?? 0} vision score`,
        inline: true
      },
      {
        name: "⏱️ Duração",
        value: `${num(durationMin, 1)} min`,
        inline: true
      },
      {
        name: "🆔 Match",
        value: String(id),
        inline: false
      }
    ],
    footer: "Partida ranqueada mais recente • Riot Match-V5"
  });
}

async function renderMastery(account, env) {
  const [masteries, names] = await Promise.all([
    riotGet(
      platformUrl(env, `/lol/champion-mastery/v4/champion-masteries/by-puuid/${encodeURIComponent(account.puuid)}/top?count=10`),
      env.RIOT_API_KEY
    ),
    championMap()
  ]);

  if (!Array.isArray(masteries) || !masteries.length) {
    return errorResponse("A Riot não retornou maestrias para esse jogador.");
  }

  return embedResponse({
    title: `⭐ ${riotId(account)} • Maestrias`,
    color: COLORS.gold,
    description: masteries.slice(0, 10).map((entry, index) => {
      const name = names[String(entry.championId)] ?? `Champion ${entry.championId}`;
      return `**${index + 1}. ${name}** — Nv. ${entry.championLevel ?? "?"} • ${Number(entry.championPoints ?? 0).toLocaleString("pt-BR")} pts`;
    }).join("\n"),
    footer: "Dados ao vivo: Champion Mastery-V4"
  });
}

async function renderTftPlayer(account, env) {
  const [ranks, ids] = await Promise.all([
    riotGet(platformUrl(env, `/tft/league/v1/by-puuid/${encodeURIComponent(account.puuid)}`), env.RIOT_TFT_API_KEY),
    riotGet(regionalUrl(env, `/tft/match/v1/matches/by-puuid/${encodeURIComponent(account.puuid)}/ids?start=0&count=8`), env.RIOT_TFT_API_KEY)
  ]);

  const matches = await Promise.all(
    (Array.isArray(ids) ? ids.slice(0, 8) : []).map(id =>
      riotGet(regionalUrl(env, `/tft/match/v1/matches/${encodeURIComponent(id)}`), env.RIOT_TFT_API_KEY)
    )
  );

  const rank = rankEntry(ranks, "RANKED_TFT");
  const recent = summarizeTftMatches(matches, account.puuid);

  const fields = [
    {
      name: "🏆 Ranked TFT",
      value: rankLabel(rank),
      inline: false
    },
    {
      name: "📊 Partidas recentes",
      value: recent.games
        ? `Top 4 **${pct(recent.top4Rate)}** • 1º **${pct(recent.firstRate)}**\nPosição média **${num(recent.avg, 2)}** • ${recent.games} partida(s)`
        : "Sem partidas recentes retornadas.",
      inline: false
    }
  ];

  if (recent.placements.length) {
    fields.push({
      name: "🎲 Colocações",
      value: recent.placements.map(value => ordinal(value)).join(" • "),
      inline: false
    });
  }

  return embedResponse({
    title: `♟️ ${riotId(account)}`,
    color: COLORS.purple,
    fields,
    footer: "Dados ao vivo: Riot API • Teamfight Tactics"
  });
}

async function renderLatestTftMatch(account, env) {
  const ids = await riotGet(
    regionalUrl(env, `/tft/match/v1/matches/by-puuid/${encodeURIComponent(account.puuid)}/ids?start=0&count=1`),
    env.RIOT_TFT_API_KEY
  );
  const id = Array.isArray(ids) ? ids[0] : null;

  if (!id) return errorResponse("Não encontrei uma partida recente de TFT para esse Riot ID.");

  const match = await riotGet(
    regionalUrl(env, `/tft/match/v1/matches/${encodeURIComponent(id)}`),
    env.RIOT_TFT_API_KEY
  );
  const participant = (match?.info?.participants ?? []).find(row => row.puuid === account.puuid);

  if (!participant) return errorResponse("A Riot retornou a partida, mas não encontrei o jogador nela.");

  const traits = (participant.traits ?? [])
    .filter(row => Number(row.tier_current ?? 0) > 0)
    .sort((a, b) => Number(b.num_units ?? 0) - Number(a.num_units ?? 0))
    .slice(0, 6)
    .map(row => cleanTftName(row.name));

  const units = (participant.units ?? [])
    .sort((a, b) => Number(b.tier ?? 0) - Number(a.tier ?? 0))
    .slice(0, 8)
    .map(row => `${cleanTftName(row.character_id)} ${"★".repeat(Math.max(1, Math.min(3, Number(row.tier ?? 1))))}`);

  return embedResponse({
    title: `♟️ ${ordinal(participant.placement)} lugar • ${riotId(account)}`,
    color: Number(participant.placement) <= 4 ? COLORS.green : COLORS.red,
    fields: [
      {
        name: "📊 Resultado",
        value: `Colocação **${ordinal(participant.placement)}** • nível **${participant.level ?? "?"}**\nEliminações **${participant.players_eliminated ?? 0}** • dano a jogadores **${participant.total_damage_to_players ?? 0}**`,
        inline: false
      },
      {
        name: "🧬 Traits",
        value: traits.length ? traits.join(" • ") : "Não disponível.",
        inline: false
      },
      {
        name: "👥 Unidades",
        value: units.length ? units.join(" • ") : "Não disponível.",
        inline: false
      },
      {
        name: "🆔 Match",
        value: String(id),
        inline: false
      }
    ],
    footer: "Partida TFT mais recente • Riot TFT Match-V1"
  });
}

async function accountByRiotId(input, apiKey, env) {
  const parsed = parseRiotId(input);
  if (!parsed) {
    throw new UserError("Informe o Riot ID completo no formato **Nome#TAG**.");
  }

  const data = await riotGet(
    regionalUrl(env, `/riot/account/v1/accounts/by-riot-id/${encodeURIComponent(parsed.gameName)}/${encodeURIComponent(parsed.tagLine)}`),
    apiKey
  );

  if (!data?.puuid) throw new UserError("A Riot não retornou PUUID para esse Riot ID.");

  return data;
}

async function riotGet(url, apiKey) {
  const response = await fetch(url, {
    headers: {
      accept: "application/json",
      "X-Riot-Token": apiKey,
      "User-Agent": "discord-automation-oraculo-riot/2.0"
    }
  });

  if (response.status === 404) throw new UserError("Riot ID ou recurso não encontrado.");
  if (response.status === 403 || response.status === 401) {
    throw new UserError("A chave configurada não tem acesso a este endpoint da Riot.");
  }
  if (response.status === 429) throw new UserError("Limite temporário da Riot atingido. Tente novamente em alguns segundos.");
  if (!response.ok) throw new Error(`Riot API HTTP ${response.status}: ${url}`);

  return response.json();
}

function summarizeLolMatches(matches, puuid) {
  const participants = matches
    .map(match => participantByPuuid(match, puuid))
    .filter(Boolean);

  const wins = participants.filter(row => row.win).length;
  const championMap = new Map();

  for (const row of participants) {
    const name = row.championName ?? "Campeão";
    const current = championMap.get(name) ?? { name, games: 0, wins: 0 };
    current.games++;
    if (row.win) current.wins++;
    championMap.set(name, current);
  }

  return {
    games: participants.length,
    wins,
    losses: participants.length - wins,
    winRate: participants.length ? wins / participants.length : 0,
    kda: participants.length
      ? participants.reduce((sum, row) => sum + kda(row), 0) / participants.length
      : 0,
    champions: [...championMap.values()].sort((a, b) => b.games - a.games || b.wins - a.wins)
  };
}

function summarizeTftMatches(matches, puuid) {
  const placements = matches
    .map(match => (match?.info?.participants ?? []).find(row => row.puuid === puuid)?.placement)
    .map(Number)
    .filter(value => Number.isFinite(value) && value >= 1 && value <= 8);

  return {
    games: placements.length,
    placements,
    top4Rate: placements.length ? placements.filter(value => value <= 4).length / placements.length : 0,
    firstRate: placements.length ? placements.filter(value => value === 1).length / placements.length : 0,
    avg: placements.length ? placements.reduce((sum, value) => sum + value, 0) / placements.length : 0
  };
}

function participantByPuuid(match, puuid) {
  return (match?.info?.participants ?? []).find(row => row.puuid === puuid) ?? null;
}

function rankEntry(entries, queueType) {
  if (!Array.isArray(entries)) return null;
  return entries.find(row => row.queueType === queueType) ?? null;
}

function rankLabel(rank) {
  if (!rank) return "Unranked";
  const tier = titleCase(rank.tier ?? "");
  const division = rank.rank ?? "";
  const lp = Number(rank.leaguePoints ?? rank.lp ?? 0);
  return `**${tier} ${division}** • ${lp} LP`.trim();
}

function kda(row) {
  return (Number(row.kills ?? 0) + Number(row.assists ?? 0)) / Math.max(1, Number(row.deaths ?? 0));
}

function parseRiotId(value) {
  const raw = String(value ?? "").trim();
  const index = raw.lastIndexOf("#");
  if (index <= 0 || index >= raw.length - 1) return null;
  return {
    gameName: raw.slice(0, index).trim(),
    tagLine: raw.slice(index + 1).trim()
  };
}

function riotId(account) {
  return `${account.gameName ?? "Jogador"}#${account.tagLine ?? ""}`;
}

function platformUrl(env, path) {
  return `https://${env.RIOT_PLATFORM || "br1"}.api.riotgames.com${path}`;
}

function regionalUrl(env, path) {
  return `https://${env.RIOT_REGION || "americas"}.api.riotgames.com${path}`;
}

let championNamesPromise;
async function championMap() {
  if (!championNamesPromise) {
    championNamesPromise = (async () => {
      const versions = await fetch("https://ddragon.leagueoflegends.com/api/versions.json", {
        cf: { cacheEverything: true, cacheTtl: 21600 }
      }).then(response => response.json());
      const version = Array.isArray(versions) ? versions[0] : null;
      if (!version) return {};

      const payload = await fetch(
        `https://ddragon.leagueoflegends.com/cdn/${version}/data/pt_BR/champion.json`,
        { cf: { cacheEverything: true, cacheTtl: 21600 } }
      ).then(response => response.json());

      return Object.values(payload?.data ?? {}).reduce((map, champion) => {
        map[String(champion.key)] = champion.name;
        return map;
      }, {});
    })().catch(() => ({}));
  }
  return championNamesPromise;
}

function optionValue(subcommand, name) {
  return subcommand?.options?.find(option => option.name === name)?.value?.toString() ?? "";
}

function cleanTftName(value) {
  return String(value ?? "")
    .replace(/^TFT\d+_/i, "")
    .replace(/^TFT_/i, "")
    .replace(/_/g, " ")
    .trim();
}

function ordinal(value) {
  const number = Number(value);
  return Number.isFinite(number) ? `${number}º` : "?";
}

function titleCase(value) {
  return String(value ?? "").toLowerCase().replace(/\b\w/g, char => char.toUpperCase());
}

function pct(value) {
  return `${(Number(value ?? 0) * 100).toFixed(1).replace(".", ",")}%`;
}

function num(value, decimals = 2) {
  return Number(value ?? 0).toFixed(decimals).replace(".", ",");
}

function embedResponse({ title, description, color, fields = [], footer }) {
  const embed = {
    title: trim(title, 256),
    color: color ?? COLORS.blue,
    fields: fields.slice(0, 25).map(field => ({
      ...field,
      name: trim(field.name, 256),
      value: trim(field.value, 1024)
    })),
    timestamp: new Date().toISOString()
  };

  if (description) embed.description = trim(description, 4096);
  if (footer) embed.footer = { text: trim(footer, 2048) };

  return { embeds: [embed], allowed_mentions: { parse: [] } };
}

export function errorResponse(message) {
  return {
    embeds: [{
      title: "🔎 Consulta Riot",
      description: trim(message, 4096),
      color: COLORS.red
    }],
    allowed_mentions: { parse: [] }
  };
}

export function userErrorResponse(error) {
  if (error instanceof UserError) return errorResponse(error.message);
  return null;
}

function trim(value, max) {
  const string = String(value ?? "");
  return string.length <= max ? string : string.slice(0, max - 1) + "…";
}

class UserError extends Error {}
